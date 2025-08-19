<?php

namespace App\Filament\Widgets;

use App\Enums\ResultStatus;
use App\Helpers\Number;
use App\Models\Result;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class RecentThroughputHeatmapWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-throughput-heatmap';
    protected static ?string $heading = 'Throughput Heatmap (density)';

    protected int|string|array $columnSpan = 'full';

    // Poll every 60s (we cache for the same interval to avoid recompute)
    protected static ?string $pollingInterval = '60s';
    protected static bool $isLazy = false;

    /** Layout */
    protected int $tile = 16; // px per cell
    protected int $gap  = 1;  // px between cells

    /** Target bin counts (we pick “nice” steps near these) */
    protected int $targetXBins = 24; // Upload bins (X)
    protected int $targetYBins = 18; // Download bins (Y)

    /** Smoothing strength (Gaussian σ in cells). 0 = off */
    protected float $sigma = 2.0;

    /** X = Upload, Y = Download (per your spec) */
    protected bool $flipAxes = false;

    /** Treat tiny smoothed values as background (.5% of clampMax or 0.5) */
    protected float $noiseFloorRatio = 0.005;

    // How to set the upper bound of color scale: 'p95' | 'p99' | 'max' | 'adaptive'
    protected string $colorScaleMode = 'max';

    // Value → color normalization: 'linear' | 'log' | 'sqrt'
    protected string $valueScale = 'sqrt';

    // Colors & styling (single place to tweak)
    protected string $noDataColor      = 'hsl(240, 90%, 20%)';
    protected string $tileStrokeColor  = 'rgba(0,0,0,0.2)';
    protected float  $tileStrokeWidth  = 0.5;
    protected int    $legendSteps      = 48;

    // Ramp parameters for pink → blue gradient
    protected int $rampHueStart   = 240; // blue
    protected int $rampHueEnd     = 320; // pink
    protected int $rampSaturation = 90;  // %
    protected int $rampLightStart = 20;  // %
    protected int $rampLightEnd   = 60;  // %

    public function getPollingInterval(): ?string
    {
        return static::$pollingInterval;
    }

    protected function getViewData(): array
    {
        // Bump cache key to avoid stale tiles after we add tooltip fields
        return Cache::remember('heatmap:throughput:all:v4', 60, function () {
            // ---- PASS 1: scan all results, find observed maxima (streamed) ----
            $maxUp = 0.0;
            $maxDown = 0.0;

            Result::query()
                ->select(['id', 'download', 'upload'])
                ->where('status', ResultStatus::Completed)
                ->orderBy('id') // for chunkById
                ->chunkById(5000, function ($chunk) use (&$maxUp, &$maxDown) {
                    foreach ($chunk as $r) {
                        $db = $r->download_bits ?? null;
                        $ub = $r->upload_bits ?? null;
                        if ($db === null || $ub === null) {
                            continue;
                        }
                        $down = (float) Number::bitsToMagnitude(bits: $db, precision: 6, magnitude: 'mbit');
                        $up   = (float) Number::bitsToMagnitude(bits: $ub, precision: 6, magnitude: 'mbit');
                        if ($up > $maxUp) $maxUp = $up;
                        if ($down > $maxDown) $maxDown = $down;
                    }
                });

            if ($maxUp <= 0 || $maxDown <= 0) {
                return $this->emptyGrid();
            }

            // Pick “nice” steps near the target bin counts
            $stepX = $this->niceStep($maxUp,   $this->targetXBins);
            $stepY = $this->niceStep($maxDown, $this->targetYBins);

            $maxX  = $this->niceCeil($maxUp,   $stepX);
            $maxY  = $this->niceCeil($maxDown, $stepY);

            $xStarts = range(0, max($stepX, $maxX - $stepX), $stepX);
            $yStarts = range(0, max($stepY, $maxY - $stepY), $stepY);

            $xLabels = array_map(fn($v) => $this->rangeLabel($v, $v + $stepX), $xStarts);
            $yLabelsAsc = array_map(fn($v) => $this->rangeLabel($v, $v + $stepY), $yStarts); // low→high
            $yLabels = array_reverse($yLabelsAsc); // show high→low top→bottom

            $cols = count($xStarts);
            $rowsCnt = count($yStarts);

            // ---- PASS 2: build histogram (streamed) ----
            $hist = array_fill(0, $rowsCnt, array_fill(0, $cols, 0.0));

            Result::query()
                ->select(['id', 'download', 'upload'])
                ->where('status', ResultStatus::Completed)
                ->orderBy('id')
                ->chunkById(5000, function ($chunk) use (&$hist, $stepX, $stepY, $maxX, $maxY) {
                    foreach ($chunk as $r) {
                        $db = $r->download_bits ?? null;
                        $ub = $r->upload_bits ?? null;
                        if ($db === null || $ub === null) {
                            continue;
                        }
                        $down = (float) Number::bitsToMagnitude(bits: $db, precision: 6, magnitude: 'mbit');
                        $up   = (float) Number::bitsToMagnitude(bits: $ub, precision: 6, magnitude: 'mbit');

                        $x = min($maxX - 1e-9, max(0.0, $up));
                        $y = min($maxY - 1e-9, max(0.0, $down));

                        $xi = (int) floor($x / $stepX);
                        $yi = (int) floor($y / $stepY);

                        $hist[$yi][$xi] += 1.0;
                    }
                });

            // Smooth (optional)
            $blur = $this->sigma > 0 ? $this->gaussianSmooth($hist, $this->sigma) : $hist;

            // Color scale via 95th percentile of non-zero bins
            $vals = [];
            foreach ($blur as $row) foreach ($row as $v) if ($v > 0) $vals[] = $v;
            sort($vals);
            $n = count($vals);
            $maxBin = $n ? $vals[$n - 1] : 1.0;
            $p95    = $n ? $vals[(int) floor(0.95 * ($n - 1))] : 1.0;
            $p99    = $n ? $vals[(int) floor(0.99 * ($n - 1))] : $p95;

            $clampMin = 0.0;

            // choose upper bound
            switch ($this->colorScaleMode) {
                case 'max':
                    $hi = $maxBin;
                    break;
                case 'p95':
                    $hi = max(10.0, $p95);
                    break;
                case 'p99':
                    $hi = max(10.0, $p99);
                    break;
                case 'adaptive':
                default:
                    $hi = max(10.0, $p99);
                    // if the tail is huge, expand to max to avoid saturation
                    if ($maxBin > 3 * $hi) {
                        $hi = $maxBin;
                    }
                    break;
            }

            $clampMax = $this->niceCeil($hi, $this->niceStep($hi, 8));
            $epsilon  = max(0.5, $clampMax * $this->noiseFloorRatio);
            $noDataColor = $this->pinkBlueRamp(0);

            $epsilon  = max(0.5, $clampMax * $this->noiseFloorRatio); // noise floor
            $noDataColor = $this->pinkBlueRamp(0);

            // Build tiles (invert Y so low at bottom)
            $width  = $cols * $this->tile + ($cols - 1) * $this->gap;
            $height = $rowsCnt * $this->tile + ($rowsCnt - 1) * $this->gap;

            $tiles = [];
            for ($yy = 0; $yy < $rowsCnt; $yy++) {
                for ($xx = 0; $xx < $cols; $xx++) {
                    $v = $blur[$yy][$xx];

                    $hasData = $v > $epsilon;
                    if ($hasData) {
                        // normalize by selected scale
                        switch ($this->valueScale) {
                            case 'log':
                                $t = log(1 + $v) / max(log(1 + $clampMax), 1e-9);
                                break;
                            case 'sqrt':
                                $t = sqrt($v / max($clampMax, 1e-9));
                                break;
                            case 'linear':
                            default:
                                $t = ($v - $clampMin) / max($clampMax - $clampMin, 1e-9);
                                break;
                        }
                        // clamp t into [0,1]
                        $t = max(0.0, min(1.0, $t));

                        $fill  = $this->pinkBlueRamp($t);
                        $label = sprintf('count ≈ %d', (int) round($v));
                    } else {
                        $fill  = $this->noDataColor;
                        $label = 'no data';
                    }

                    $tiles[] = [
                        'x'       => $xx * ($this->tile + $this->gap),
                        'y'       => ($rowsCnt - 1 - $yy) * ($this->tile + $this->gap),
                        'w'       => $this->tile,
                        'h'       => $this->tile,
                        'fill'    => $fill,
                        'title'   => sprintf('Up %s, Down %s: %s', $xLabels[$xx] ?? '', $yLabelsAsc[$yy] ?? '', $label),

                        // Tooltip data (read in Blade via data-* attributes)
                        'uLabel'  => $xLabels[$xx] ?? '',
                        'dLabel'  => $yLabelsAsc[$yy] ?? '',
                        'count'   => (int) round($v),
                        'hasData' => $hasData,
                    ];
                }
            }

            // Dynamic vertical legend
            $legendBar = [];
            for ($i = 0; $i <= $this->legendSteps; $i++) {
                $legendBar[] = $this->pinkBlueRamp($i / $this->legendSteps);
            }

            $legendTicks = [];
            $tickCount = 6;
            for ($i = 0; $i <= $tickCount; $i++) {
                $pos = 100 * (1 - ($i / $tickCount)); // top→bottom
                $val = $clampMin + ($i / $tickCount) * ($clampMax - $clampMin);
                $legendTicks[] = [
                    'pos'   => $pos,
                    'value' => (int) round($val),
                ];
            }

            $xTickEvery = max(1, (int) ceil($cols / 12));
            $yTickEvery = max(1, (int) ceil($rowsCnt / 10));

            return [
                'heading'     => static::$heading,
                'xLabels'     => $xLabels,              // Upload (X)
                'yLabels'     => array_reverse($yLabelsAsc), // Download (display order)
                'xTickEvery'  => $xTickEvery,
                'yTickEvery'  => $yTickEvery,
                'width'       => $width,
                'height'      => $height,
                'gap'         => $this->gap,
                'tiles'       => $tiles,
                'legendBar'   => $legendBar,
                'legendTicks' => $legendTicks,
                'tileStrokeColor' => $this->tileStrokeColor,
                'tileStrokeWidth' => $this->tileStrokeWidth,
            ];
        });
    }

    // ---------- helpers ----------

    private function emptyGrid(): array
    {
        $cols = 5; $rowsCnt = 5;
        $width  = $cols * $this->tile + ($cols - 1) * $this->gap;
        $height = $rowsCnt * $this->tile + ($rowsCnt - 1) * $this->gap;

        $tiles = [];
        for ($yy = 0; $yy < $rowsCnt; $yy++) {
            for ($xx = 0; $xx < $cols; $xx++) {
                $tiles[] = [
                    'x'       => $xx * ($this->tile + $this->gap),
                    'y'       => ($rowsCnt - 1 - $yy) * ($this->tile + $this->gap),
                    'w'       => $this->tile,
                    'h'       => $this->tile,
                    'fill'    => $this->pinkBlueRamp(0), // match ramp(0)
                    'title'   => 'no data',
                    'uLabel'  => '',
                    'dLabel'  => '',
                    'count'   => 0,
                    'hasData' => false,
                ];
            }
        }

        // basic legend
        $legendBar = [];
        for ($i = 0; $i <= 48; $i++) $legendBar[] = $this->pinkBlueRamp($i / 48);
        $legendTicks = [];
        for ($i = 0; $i <= 6; $i++) {
            $legendTicks[] = ['pos' => 100 * (1 - ($i / 6)), 'value' => $i];
        }

        return [
            'heading'     => static::$heading,
            'xLabels'     => [], 'yLabels' => [],
            'xTickEvery'  => 1, 'yTickEvery' => 1,
            'width'       => $width,
            'height'      => $height,
            'gap'         => $this->gap,
            'tiles'       => $tiles,
            'legendBar'   => $legendBar,
            'legendTicks' => $legendTicks,
        ];
    }

    /** Nice step: 1|2|5 × 10^k near max/targetBins */
    private function niceStep(float $maxValue, int $targetBins): float
    {
        $raw = max($maxValue, 1e-9) / max($targetBins, 1);
        $pow = pow(10, floor(log10($raw)));
        $nice = $raw / $pow;
        if     ($nice <= 1.0) $nice = 1.0;
        elseif ($nice <= 2.0) $nice = 2.0;
        elseif ($nice <= 5.0) $nice = 5.0;
        else                  $nice = 10.0;
        return $nice * $pow;
    }

    private function niceCeil(float $value, float $step): float
    {
        return ceil($value / $step) * $step;
    }

    private function rangeLabel(float $from, float $to): string
    {
        return (int) $from . '–' . (int) $to;
    }

    /** Build 1D Gaussian kernel, normalized. */
    private function gaussianKernel1D(float $sigma): array
    {
        $sigma = max(0.1, $sigma);
        $r = (int) ceil(3 * $sigma); // ~99.7% within 3σ
        $kern = [];
        $sum = 0.0;
        for ($i = -$r; $i <= $r; $i++) {
            $w = exp(-($i * $i) / (2 * $sigma * $sigma));
            $kern[] = $w;
            $sum += $w;
        }
        foreach ($kern as $k => $w) $kern[$k] = $w / $sum;
        return $kern;
    }

    /** Separable Gaussian smoothing. */
    private function gaussianSmooth(array $hist, float $sigma): array
    {
        $kern = $this->gaussianKernel1D($sigma);

        // X pass
        $tmp = [];
        foreach ($hist as $row) $tmp[] = $this->convolve1D($row, $kern);

        // Y pass
        $cols = count($hist[0] ?? []);
        $rowsCnt = count($hist);
        $out = array_fill(0, $rowsCnt, array_fill(0, $cols, 0.0));

        $r = (int) floor(count($kern) / 2);
        for ($x = 0; $x < $cols; $x++) {
            for ($y = 0; $y < $rowsCnt; $y++) {
                $acc = 0.0; $wSum = 0.0;
                for ($k = -$r; $k <= $r; $k++) {
                    $yy = $y + $k;
                    if ($yy < 0 || $yy >= $rowsCnt) continue; // zero padding
                    $w = $kern[$k + $r];
                    $acc += $tmp[$yy][$x] * $w;
                    $wSum += $w;
                }
                $out[$y][$x] = $wSum > 0 ? $acc / $wSum : 0.0;
            }
        }
        return $out;
    }

    /** Blue → magenta → pink ramp (HSL). t in [0,1]. */
    private function pinkBlueRamp(float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        $h = $this->rampHueStart + ($this->rampHueEnd - $this->rampHueStart) * $t;
        $s = $this->rampSaturation;
        $l = $this->rampLightStart + ($this->rampLightEnd - $this->rampLightStart) * $t;

        return sprintf('hsl(%d, %d%%, %d%%)', (int) round($h), (int) round($s), (int) round($l));
    }

    /**
 * 1D convolution with zero-padding; renormalizes edges so sums stay stable.
 */
private function convolve1D(array $row, array $kern): array
{
    $n = count($row);
    $r = (int) floor(count($kern) / 2);
    $out = array_fill(0, $n, 0.0);

    for ($i = 0; $i < $n; $i++) {
        $acc = 0.0;
        $wSum = 0.0;

        for ($k = -$r; $k <= $r; $k++) {
            $j = $i + $k;
            if ($j < 0 || $j >= $n) {
                continue; // zero padding
            }
            $w = $kern[$k + $r];
            $acc  += $row[$j] * $w;
            $wSum += $w;
        }

        $out[$i] = $wSum > 0 ? $acc / $wSum : 0.0;
    }

    return $out;
}
}
