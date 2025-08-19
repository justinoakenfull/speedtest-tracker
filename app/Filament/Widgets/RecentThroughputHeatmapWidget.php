<?php

namespace App\Filament\Widgets;

use App\Enums\ResultStatus;
use App\Helpers\Number;
use App\Models\Result;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class RecentThroughputHeatmapWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-throughput-heatmap';
    protected static ?string $heading = 'Throughput Heatmap (density)';

    protected int|string|array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';
    protected static bool $isLazy = false;

    /** Layout */
    private const TILE = 16; // px
    private const GAP  = 1;  // px

    /** Target bins */
    private const TARGET_X_BINS = 24; // Upload
    private const TARGET_Y_BINS = 18; // Download

    /** Axis padding (both ends) */
    private const AXIS_HEADROOM = 0.08;

    /** Optional hard caps (null = auto) */
    protected ?float $axisMaxUpload   = null;
    protected ?float $axisMaxDownload = null;
    protected ?float $axisMinUpload   = null;
    protected ?float $axisMinDownload = null;

    /** Density smoothing (σ in cells) */
    private const SIGMA = 1.5;

    /** Color/value scaling */
    private const COLOR_SCALE_MODE = 'adaptive'; // 'p95'|'p99'|'max'|'adaptive'
    private const VALUE_SCALE      = 'sqrt';     // 'linear'|'log'|'sqrt'

    /** Styling */
    private const TILE_STROKE_COLOR = 'rgba(0,0,0,0.2)';
    private const TILE_STROKE_WIDTH = 0.5;
    private const LEGEND_STEPS      = 48;

    /** HSL ramp (blue → pink) */
    private const RAMP_HUE_START   = 240;
    private const RAMP_HUE_END     = 320;
    private const RAMP_SATURATION  = 90; // %
    private const RAMP_LIGHT_START = 20; // %
    private const RAMP_LIGHT_END   = 60; // %

    public function getPollingInterval(): ?string
    {
        return static::$pollingInterval;
    }

    protected function getViewData(): array
    {
        // bump this when computation changes
        return Cache::remember('heatmap:throughput:all:v7', 60, function () {
            [$minUp, $maxUp, $minDn, $maxDn] = $this->scanExtrema();
            if (!is_finite($minUp) || !is_finite($minDn) || $maxUp <= 0 || $maxDn <= 0) {
                return $this->emptyGrid();
            }

            [$domMinX, $domMaxX, $stepX, $cols] = $this->buildDomain($minUp, $maxUp, self::TARGET_X_BINS, $this->axisMinUpload, $this->axisMaxUpload);
            [$domMinY, $domMaxY, $stepY, $rows] = $this->buildDomain($minDn, $maxDn, self::TARGET_Y_BINS, $this->axisMinDownload, $this->axisMaxDownload);

            $xStarts = $this->seq($domMinX, $stepX, $cols);
            $yStarts = $this->seq($domMinY, $stepY, $rows);

            $xLabels   = array_map(fn($v) => $this->rangeLabel($v, $v + $stepX), $xStarts);
            $yLabelsUp = array_map(fn($v) => $this->rangeLabel($v, $v + $stepY), $yStarts); // low→high
            $yLabels   = array_reverse($yLabelsUp); // display high→low

            $hist = $this->buildHistogram($cols, $rows, $domMinX, $domMaxX, $stepX, $domMinY, $domMaxY, $stepY);

            $blur = self::SIGMA > 0 ? $this->gaussianSmooth($hist, self::SIGMA) : $hist;
            [$clampMin, $clampMax] = $this->legendClamp($blur);

            $width  = $cols * self::TILE + ($cols - 1) * self::GAP;
            $height = $rows * self::TILE + ($rows - 1) * self::GAP;

            $tiles = $this->buildTiles(
                $hist,
                $blur,
                $cols,
                $rows,
                $xLabels,
                $yLabelsUp,
                $clampMin,
                $clampMax
            );

            $legendBar   = $this->legendBar(self::LEGEND_STEPS);
            $legendTicks = $this->legendTicks($clampMin, $clampMax, 6);

            $xTickEvery = max(1, (int) ceil($cols / 12));
            $yTickEvery = max(1, (int) ceil($rows / 10));

            return [
                'heading'         => static::$heading,
                'xLabels'         => $xLabels,              // Upload (X)
                'yLabels'         => $yLabels,              // Download (display order)
                'xTickEvery'      => $xTickEvery,
                'yTickEvery'      => $yTickEvery,
                'width'           => $width,
                'height'          => $height,
                'gap'             => self::GAP,
                'tiles'           => $tiles,
                'legendBar'       => $legendBar,
                'legendTicks'     => $legendTicks,
                'tileStrokeColor' => self::TILE_STROKE_COLOR,
                'tileStrokeWidth' => self::TILE_STROKE_WIDTH,
            ];
        });
    }

    // ---------- Data passes ----------

    private function resultsQuery(): Builder
    {
        return Result::query()
            ->select(['id', 'download', 'upload'])
            ->where('status', ResultStatus::Completed)
            ->orderBy('id');
    }

    private function resultsChunked(callable $cb): void
    {
        $this->resultsQuery()->chunkById(5000, function ($chunk) use ($cb) {
            foreach ($chunk as $r) {
                $db = $r->download_bits ?? null;
                $ub = $r->upload_bits ?? null;
                if ($db === null || $ub === null) {
                    continue;
                }
                // Convert once here (mbit) so everything downstream is consistent
                $dn = (float) Number::bitsToMagnitude(bits: $db, precision: 6, magnitude: 'mbit');
                $up = (float) Number::bitsToMagnitude(bits: $ub, precision: 6, magnitude: 'mbit');
                $cb($up, $dn);
            }
        });
    }

    /** PASS 1: extrema */
    private function scanExtrema(): array
    {
        $minUp = INF; $maxUp = 0.0;
        $minDn = INF; $maxDn = 0.0;

        $this->resultsChunked(function (float $up, float $dn) use (&$minUp, &$maxUp, &$minDn, &$maxDn) {
            if ($up < $minUp) $minUp = $up;
            if ($up > $maxUp) $maxUp = $up;
            if ($dn < $minDn) $minDn = $dn;
            if ($dn > $maxDn) $maxDn = $dn;
        });

        return [$minUp, $maxUp, $minDn, $maxDn];
    }

    /** PASS 2: histogram */
    private function buildHistogram(
        int $cols, int $rows,
        float $domMinX, float $domMaxX, float $stepX,
        float $domMinY, float $domMaxY, float $stepY
    ): array {
        $hist = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $this->resultsChunked(function (float $up, float $dn) use (
            &$hist, $cols, $rows, $domMinX, $domMaxX, $stepX, $domMinY, $domMaxY, $stepY
        ) {
            $xv = min($domMaxX - 1e-9, max($domMinX, $up));
            $yv = min($domMaxY - 1e-9, max($domMinY, $dn));

            $xi = (int) floor(($xv - $domMinX) / $stepX);
            $yi = (int) floor(($yv - $domMinY) / $stepY);

            if ($xi < 0) $xi = 0; elseif ($xi >= $cols) $xi = $cols - 1;
            if ($yi < 0) $yi = 0; elseif ($yi >= $rows) $yi = $rows - 1;

            $hist[$yi][$xi] += 1.0;
        });

        return $hist;
    }

    // ---------- Domain / axis ----------

    private function buildDomain(
        float $min, float $max, int $targetBins,
        ?float $hardMin, ?float $hardMax
    ): array {
        $span   = max(1e-9, $max - $min);
        $step   = $this->niceStep($span, $targetBins);

        $rawMin = $min * (1 - self::AXIS_HEADROOM);
        $rawMax = $max * (1 + self::AXIS_HEADROOM);

        if ($hardMin !== null) $rawMin = min($rawMin, $hardMin);
        if ($hardMax !== null) $rawMax = max($rawMax, $hardMax);

        $domMin = $this->niceFloor(max(0.0, $rawMin), $step);
        $domMax = $this->niceCeil($rawMax, $step);

        $bins = max(1, (int) ceil(($domMax - $domMin) / $step));
        return [$domMin, $domMax, $step, $bins];
    }

    private function seq(float $start, float $step, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) $out[] = $start + $i * $step;
        return $out;
    }

    // ---------- Tiles / legend ----------

    private function buildTiles(
        array $hist,
        array $blur,
        int $cols,
        int $rows,
        array $xLabels,
        array $yLabelsUp,
        float $clampMin,
        float $clampMax
    ): array {
        $tiles = [];

        for ($yy = 0; $yy < $rows; $yy++) {
            for ($xx = 0; $xx < $cols; $xx++) {
                $raw      = $hist[$yy][$xx];
                $smoothed = $blur[$yy][$xx];
                $hasData  = $raw > 0;

                if ($hasData) {
                    $t     = $this->scaleValue($smoothed, $clampMin, $clampMax, self::VALUE_SCALE);
                    $fill  = $this->pinkBlueRamp($t);
                    $label = 'samples: ' . (int) $raw;
                } else {
                    $fill  = $this->pinkBlueRamp(0.0);
                    $label = 'no data';
                }

                $tiles[] = [
                    'x'        => $xx * (self::TILE + self::GAP),
                    'y'        => ($rows - 1 - $yy) * (self::TILE + self::GAP),
                    'w'        => self::TILE,
                    'h'        => self::TILE,
                    'fill'     => $fill,
                    'title'    => sprintf('Up %s, Down %s: %s', $xLabels[$xx] ?? '', $yLabelsUp[$yy] ?? '', $label),
                    'uLabel'   => $xLabels[$xx]   ?? '',
                    'dLabel'   => $yLabelsUp[$yy] ?? '',
                    'count'    => (int) $raw,
                    'hasData'  => $hasData,
                ];
            }
        }

        return $tiles;
    }

    private function legendClamp(array $blur): array
    {
        $vals = [];
        foreach ($blur as $row) {
            foreach ($row as $v) if ($v > 0) $vals[] = $v;
        }
        sort($vals);
        $n      = count($vals);
        $maxBin = $n ? $vals[$n - 1] : 1.0;
        $p95    = $n ? $vals[(int) floor(0.95 * ($n - 1))] : 1.0;
        $p99    = $n ? $vals[(int) floor(0.99 * ($n - 1))] : $p95;

        switch (self::COLOR_SCALE_MODE) {
            case 'max': $hi = $maxBin; break;
            case 'p95': $hi = max(10.0, $p95); break;
            case 'p99': $hi = max(10.0, $p99); break;
            default:    // adaptive
                $hi = max(10.0, $p99);
                if ($maxBin > 3 * $hi) $hi = $maxBin;
                break;
        }

        $clampMin = 0.0;
        $clampMax = $this->niceCeil($hi, $this->niceStep($hi, 8));
        return [$clampMin, $clampMax];
    }

    private function legendBar(int $steps): array
    {
        $bar = [];
        for ($i = 0; $i <= $steps; $i++) $bar[] = $this->pinkBlueRamp($i / $steps);
        return $bar;
    }

    private function legendTicks(float $min, float $max, int $count): array
    {
        $ticks = [];
        for ($i = 0; $i <= $count; $i++) {
            $pos = 100 * (1 - ($i / $count));
            $val = $min + ($i / $count) * ($max - $min);
            $ticks[] = ['pos' => $pos, 'value' => (int) round($val)];
        }
        return $ticks;
    }

    // ---------- Helpers ----------

    private function emptyGrid(): array
    {
        $cols = 5; $rows = 5;
        $width  = $cols * self::TILE + ($cols - 1) * self::GAP;
        $height = $rows * self::TILE + ($rows - 1) * self::GAP;

        $tiles = [];
        for ($yy = 0; $yy < $rows; $yy++) {
            for ($xx = 0; $xx < $cols; $xx++) {
                $tiles[] = [
                    'x' => $xx * (self::TILE + self::GAP),
                    'y' => ($rows - 1 - $yy) * (self::TILE + self::GAP),
                    'w' => self::TILE,
                    'h' => self::TILE,
                    'fill'    => $this->pinkBlueRamp(0),
                    'title'   => 'no data',
                    'uLabel'  => '',
                    'dLabel'  => '',
                    'count'   => 0,
                    'hasData' => false,
                ];
            }
        }

        return [
            'heading'         => static::$heading,
            'xLabels'         => [],
            'yLabels'         => [],
            'xTickEvery'      => 1,
            'yTickEvery'      => 1,
            'width'           => $width,
            'height'          => $height,
            'gap'             => self::GAP,
            'tiles'           => $tiles,
            'legendBar'       => $this->legendBar(self::LEGEND_STEPS),
            'legendTicks'     => $this->legendTicks(0, 6, 6),
            'tileStrokeColor' => self::TILE_STROKE_COLOR,
            'tileStrokeWidth' => self::TILE_STROKE_WIDTH,
        ];
    }

    private function niceStep(float $span, int $targetBins): float
    {
        $span = max($span, 1e-6);
        $raw  = $span / max($targetBins, 1);
        $pow  = pow(10, floor(log10($raw)));
        $nice = $raw / $pow;
        if     ($nice <= 1.0) $nice = 1.0;
        elseif ($nice <= 2.0) $nice = 2.0;
        elseif ($nice <= 5.0) $nice = 5.0;
        else                  $nice = 10.0;
        return $nice * $pow;
    }

    private function niceFloor(float $value, float $step): float
    {
        return floor($value / max($step, 1e-9)) * $step;
    }

    private function niceCeil(float $value, float $step): float
    {
        return ceil($value / max($step, 1e-9)) * $step;
    }

    private function rangeLabel(float $from, float $to): string
    {
        return (int) $from . '–' . (int) $to;
    }

    private function gaussianKernel1D(float $sigma): array
    {
        $sigma = max(0.1, $sigma);
        $r = (int) ceil(3 * $sigma);
        $kern = []; $sum = 0.0;
        for ($i = -$r; $i <= $r; $i++) {
            $w = exp(-($i * $i) / (2 * $sigma * $sigma));
            $kern[] = $w; $sum += $w;
        }
        foreach ($kern as $k => $w) $kern[$k] = $w / $sum;
        return $kern;
    }

    private function gaussianSmooth(array $hist, float $sigma): array
    {
        $kern = $this->gaussianKernel1D($sigma);

        // X pass
        $tmp = [];
        foreach ($hist as $row) $tmp[] = $this->convolve1D($row, $kern);

        // Y pass
        $cols = count($hist[0] ?? []);
        $rows = count($hist);
        $out = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $r = (int) floor(count($kern) / 2);
        for ($x = 0; $x < $cols; $x++) {
            for ($y = 0; $y < $rows; $y++) {
                $acc = 0.0; $wSum = 0.0;
                for ($k = -$r; $k <= $r; $k++) {
                    $yy = $y + $k;
                    if ($yy < 0 || $yy >= $rows) continue;
                    $w = $kern[$k + $r];
                    $acc += $tmp[$yy][$x] * $w;
                    $wSum += $w;
                }
                $out[$y][$x] = $wSum > 0 ? $acc / $wSum : 0.0;
            }
        }
        return $out;
    }

    private function convolve1D(array $row, array $kern): array
    {
        $n = count($row);
        $r = (int) floor(count($kern) / 2);
        $out = array_fill(0, $n, 0.0);

        for ($i = 0; $i < $n; $i++) {
            $acc = 0.0; $wSum = 0.0;
            for ($k = -$r; $k <= $r; $k++) {
                $j = $i + $k;
                if ($j < 0 || $j >= $n) continue;
                $w = $kern[$k + $r];
                $acc  += $row[$j] * $w;
                $wSum += $w;
            }
            $out[$i] = $wSum > 0 ? $acc / $wSum : 0.0;
        }
        return $out;
    }

    private function scaleValue(float $v, float $min, float $max, string $mode): float
    {
        $v = max($min, min($max, $v));
        switch ($mode) {
            case 'log':
                $t = log(1 + $v) / max(log(1 + $max), 1e-9);
                break;
            case 'sqrt':
                $t = sqrt($v / max($max, 1e-9));
                break;
            case 'linear':
            default:
                $t = ($v - $min) / max($max - $min, 1e-9);
                break;
        }
        return max(0.0, min(1.0, $t));
    }

    private function pinkBlueRamp(float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        $h = self::RAMP_HUE_START + (self::RAMP_HUE_END - self::RAMP_HUE_START) * $t;
        $s = self::RAMP_SATURATION;
        $l = self::RAMP_LIGHT_START + (self::RAMP_LIGHT_END - self::RAMP_LIGHT_START) * $t;

        return sprintf('hsl(%d, %d%%, %d%%)', (int) round($h), (int) round($s), (int) round($l));
    }
}
