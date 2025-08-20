<?php

namespace App\Filament\Widgets;

use App\Enums\ResultStatus;
use App\Helpers\Number;
use App\Helpers\HeatmapPalettes;
use App\Models\Result;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class RecentThroughputHeatmapWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-throughput-heatmap';
    protected static ?string $heading = 'Network Heatmaps';

    protected int|string|array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';
    protected static bool $isLazy = false;


    public string $palette = 'viridis';


    private const TILE = 16;
    private const GAP  = -1;


    private const BINS_MIN = 24;
    private const BINS_MAX = 48;
    private const BINS_NICE = [24, 28, 32, 36, 40, 44, 48];

    private const AXIS_HEADROOM = 0.08;

    protected ?float $axisMaxUpload   = null;
    protected ?float $axisMaxDownload = null;
    protected ?float $axisMinUpload   = null;
    protected ?float $axisMinDownload = null;

    private const FEATHER_CENTER   = 0.8;
    private const FEATHER_NEIGHBOR = 0.60;
    private const FEATHER_DIAGONAL = 0.40;

    private const COLOR_EMPTY_CELLS = true;

    private const COLOR_SCALE_MODE = 'adaptive';   // 'p95'|'p99'|'max'|'adaptive'
    private const VALUE_SCALE      = 'sqrt';       // 'linear'|'log'|'sqrt'

    private const TILE_STROKE_COLOR = 'rgba(0,0,0,0.25)';
    private const TILE_STROKE_WIDTH = 0.5;
    private const LEGEND_STEPS      = 48;

    public function mount(): void
    {
        $options = $this->paletteOptions();
        $default = array_key_first($options) ?? 'viridis';

        $chosen = (string) session('heatmap_palette', $this->palette ?: $default);

        $this->palette = isset($options[$chosen]) ? $chosen : $default;
    }

    public function updatedPalette($val): void
    {
        $options = $this->paletteOptions();
        $default = array_key_first($options) ?? 'viridis';

        $this->palette = isset($options[$val]) ? (string) $val : $default;

        session(['heatmap_palette' => $this->palette]);
    }

    public function getPollingInterval(): ?string
    {
        return static::$pollingInterval;
    }

    public function paletteOptions(): array
    {
        return HeatmapPalettes::options();
    }

    protected function getViewData(): array
    {
        $cacheKey = sprintf('heatmap:composite:v10_autobins:%s', $this->palette);

        return Cache::remember($cacheKey, 10, function () {
            $bins = $this->autoBinCount();

            // ---------- Panel A: Upload × Download ----------
            [$minUp, $maxUp, $minDn, $maxDn] = $this->scanExtrema();
            $panelA = $this->emptyPanel('Throughput (Upload x Download)');

            if (is_finite($minUp) && is_finite($minDn) && $maxUp > 0 && $maxDn > 0) {
                [$domMinX, $domMaxX] = $this->domainWithHeadroom($minUp, $maxUp, $this->axisMinUpload, $this->axisMaxUpload);
                [$domMinY, $domMaxY] = $this->domainWithHeadroom($minDn, $maxDn, $this->axisMinDownload, $this->axisMaxDownload);

                $stepX = ($domMaxX - $domMinX) / $bins;
                $stepY = ($domMaxY - $domMinY) / $bins;

                $xStarts = $this->seq($domMinX, $stepX, $bins);
                $yStarts = $this->seq($domMinY, $stepY, $bins);

                $xLabels   = array_map(fn($v) => $this->rangeLabel($v, $v + $stepX), $xStarts);
                $yLabelsUp = array_map(fn($v) => $this->rangeLabel($v, $v + $stepY), $yStarts);
                $yLabels   = array_reverse($yLabelsUp);

                $hist = $this->buildHistogramUploadDownload($bins, $bins, $domMinX, $stepX, $domMinY, $stepY);

                $density = $this->adjacentFeather(
                    $hist,
                    self::FEATHER_CENTER,
                    self::FEATHER_NEIGHBOR,
                    self::FEATHER_DIAGONAL
                );

                [$clampMin, $clampMax] = $this->legendClamp($density);

                $size   = $bins * self::TILE + ($bins - 1) * self::GAP;
                $tilesA = $this->buildTiles(
                    $hist,
                    $density,
                    $bins,
                    $bins,
                    $xLabels,
                    $yLabelsUp,
                    $clampMin,
                    $clampMax
                );

                $panelA = [
                    'title'           => 'Throughput (Upload x Download)',
                    'xAxisTitle'      => 'Upload (Mbit/s)',
                    'yAxisTitle'      => 'Download (Mbit/s)',
                    'xLabels'         => $xLabels,
                    'yLabels'         => $yLabels,
                    'xTickEvery'      => max(1, (int) ceil($bins / 12)),
                    'yTickEvery'      => max(1, (int) ceil($bins / 10)),
                    'width'           => $size,
                    'height'          => $size,
                    'gap'             => self::GAP,
                    'tiles'           => $tilesA,
                    'legendBar'       => $this->legendBar(self::LEGEND_STEPS),
                    'legendTicks'     => $this->legendTicks($clampMin, $clampMax, 6),
                    'tileStrokeColor' => self::TILE_STROKE_COLOR,
                    'tileStrokeWidth' => self::TILE_STROKE_WIDTH,
                ];
            }

            // ---------- Panel B: Download x Time of Day ----------
            [$minDn2, $maxDn2] = $this->scanDownloadOnlyExtrema();
            $panelB = $this->emptyPanel('Download x Time of Day');

            if (is_finite($minDn2) && $maxDn2 > 0) {
                [$domMinY2, $domMaxY2] = $this->domainWithHeadroom($minDn2, $maxDn2, $this->axisMinDownload, $this->axisMaxDownload);

                $cols  = $bins;
                $rows  = $bins;
                $stepH = 24 / $cols;
                $stepY = ($domMaxY2 - $domMinY2) / $rows;

                $xStarts = $this->seq(0, $stepH, $cols);
                $yStarts = $this->seq($domMinY2, $stepY, $rows);

                $xLabels   = array_map(fn($v) => $this->rangeLabelHour($v, $v + $stepH), $xStarts);
                $yLabelsUp = array_map(fn($v) => $this->rangeLabel($v, $v + $stepY), $yStarts);
                $yLabels   = array_reverse($yLabelsUp);

                $hist = $this->buildHistogramDownloadByHour($cols, $rows, $stepH, $domMinY2, $stepY);

                $density = $this->adjacentFeather(
                    $hist,
                    self::FEATHER_CENTER,
                    self::FEATHER_NEIGHBOR,
                    self::FEATHER_DIAGONAL
                );

                [$clampMin, $clampMax] = $this->legendClamp($density);

                $size   = $bins * self::TILE + ($bins - 1) * self::GAP;
                $tilesB = $this->buildTiles(
                    $hist,
                    $density,
                    $cols,
                    $rows,
                    $xLabels,
                    $yLabelsUp,
                    $clampMin,
                    $clampMax
                );

                $panelB = [
                    'title'           => 'Download x Time of Day',
                    'xAxisTitle'      => 'Hour of Day',
                    'yAxisTitle'      => 'Download (Mbit/s)',
                    'xLabels'         => $xLabels,
                    'yLabels'         => $yLabels,
                    'xTickEvery'      => max(1, (int) ceil($cols / 12)),
                    'yTickEvery'      => max(1, (int) ceil($rows / 10)),
                    'width'           => $size,
                    'height'          => $size,
                    'gap'             => self::GAP,
                    'tiles'           => $tilesB,
                    'legendBar'       => $this->legendBar(self::LEGEND_STEPS),
                    'legendTicks'     => $this->legendTicks($clampMin, $clampMax, 6),
                    'tileStrokeColor' => self::TILE_STROKE_COLOR,
                    'tileStrokeWidth' => self::TILE_STROKE_WIDTH,
                ];
            }

            return [
                'heading'        => static::$heading,
                'palettes'       => $this->paletteOptions(),
                'selectedPalette'=> $this->palette,
                'panels'         => [$panelA, $panelB],
            ];
        });
    }

    // ---------- Queries / chunking ----------

    private function resultsQuery(): Builder
    {
        return Result::query()
            ->select(['id', 'download', 'upload', 'created_at', 'status'])
            ->where('status', ResultStatus::Completed)
            ->orderBy('id');
    }

    private function resultsChunked(callable $cb): void
    {
        $this->resultsQuery()->chunkById(5000, function ($chunk) use ($cb) {
            foreach ($chunk as $r) {
                $db = $r->download_bits ?? null;
                $ub = $r->upload_bits   ?? null;
                if ($db === null && $r->download !== null) $db = (float) $r->download * 8.0;
                if ($ub === null && $r->upload   !== null) $ub = (float) $r->upload   * 8.0;

                if ($db === null || $ub === null) continue;

                $dn = (float) Number::bitsToMagnitude(bits: $db, precision: 6, magnitude: 'mbit');
                $up = (float) Number::bitsToMagnitude(bits: $ub, precision: 6, magnitude: 'mbit');
                $ts = $r->created_at;

                $cb($up, $dn, $ts);
            }
        });
    }

    // ---------- Auto bin count (Freedman–Diaconis + Sturges) ----------

    private function autoBinCount(): int
    {
        $K = 4096;
        $ups = []; $dns = [];
        $n = 0;

        $this->resultsChunked(function (float $up, float $dn) use (&$ups, &$dns, &$n, $K) {
            $n++;
            $this->reservoirPush($ups, $up, $n, $K);
            $this->reservoirPush($dns, $dn, $n, $K);
        });

        if ($n === 0) return 48;

        sort($ups);
        sort($dns);

        $iqrU = $this->iqr($ups);
        $iqrD = $this->iqr($dns);

        $spanU = (end($ups) - $ups[0]) ?: 1e-9;
        $spanD = (end($dns) - $dns[0]) ?: 1e-9;

        $fdU = $this->freedmanDiaconisBins($iqrU, $spanU, $n);
        $fdD = $this->freedmanDiaconisBins($iqrD, $spanD, $n);
        $sturges = (int) ceil(log(max($n,1), 2) + 1);

        $raw = (int) round($this->median([$fdU, $fdD, $sturges]));
        $clamped = max(self::BINS_MIN, min(self::BINS_MAX, max(6, $raw)));
        return $this->snapToNice($clamped);
    }

    private function reservoirPush(array &$res, float $value, int $seen, int $K): void
    {
        $count = count($res);
        if ($count < $K) { $res[] = $value; return; }
        $j = random_int(1, $seen);
        if ($j <= $K) $res[$j - 1] = $value;
    }

    private function freedmanDiaconisBins(float $iqr, float $span, int $n): int
    {
        if ($iqr <= 0 || $n <= 1) {
            return (int) ceil(log(max($n,1), 2) + 1);
        }
        $h = 2.0 * $iqr / pow($n, 1.0 / 3.0);
        if ($h <= 0) return (int) ceil(log(max($n,1), 2) + 1);
        return max(1, (int) ceil($span / $h));
    }

    private function snapToNice(int $b): int
    {
        $best = self::BINS_NICE[0];
        $bestDiff = abs($b - $best);
        foreach (self::BINS_NICE as $n) {
            $d = abs($b - $n);
            if ($d < $bestDiff) { $best = $n; $bestDiff = $d; }
        }
        return $best;
    }

    private function iqr(array $sorted): float
    {
        if (!$sorted) return 0.0;
        $q1 = $this->percentile($sorted, 0.25);
        $q3 = $this->percentile($sorted, 0.75);
        return max(0.0, $q3 - $q1);
    }

    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        $p = max(0.0, min(1.0, $p));
        $pos = $p * ($n - 1);
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);
        if ($lo === $hi) return (float) $sorted[$lo];
        $w = $pos - $lo;
        return (1 - $w) * (float) $sorted[$lo] + $w * (float) $sorted[$hi];
    }

    private function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        return ($n % 2)
            ? (float) $vals[intval($n/2)]
            : 0.5 * ($vals[$n/2 - 1] + $vals[$n/2]);
    }

    // ---------- PASS 1: extrema ----------

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

    private function scanDownloadOnlyExtrema(): array
    {
        $minDn = INF; $maxDn = 0.0;

        $this->resultsChunked(function (float $_up, float $dn) use (&$minDn, &$maxDn) {
            if ($dn < $minDn) $minDn = $dn;
            if ($dn > $maxDn) $maxDn = $dn;
        });

        return [$minDn, $maxDn];
    }

    // ---------- PASS 2: histograms ----------

    private function buildHistogramUploadDownload(
        int $cols, int $rows,
        float $domMinX, float $stepX,
        float $domMinY, float $stepY
    ): array {
        $hist = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $this->resultsChunked(function (float $up, float $dn) use (
            &$hist, $cols, $rows, $domMinX, $stepX, $domMinY, $stepY
        ) {
            $xi = (int) floor(($up - $domMinX) / max($stepX, 1e-9));
            $yi = (int) floor(($dn - $domMinY) / max($stepY, 1e-9));

            if ($xi < 0 || $yi < 0 || $xi >= $cols || $yi >= $rows) return;

            $hist[$yi][$xi] += 1.0;
        });

        return $hist;
    }

    private function buildHistogramDownloadByHour(
        int $cols, int $rows,
        float $stepHour,
        float $domMinY, float $stepY
    ): array {
        $hist = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $this->resultsChunked(function (float $_up, float $dn, $ts) use (
            &$hist, $cols, $rows, $stepHour, $domMinY, $stepY
        ) {
            if (!$ts) return;

            $hour = (int) ($ts->timezone(config('app.timezone'))->format('G'));

            $xi = (int) floor($hour / max($stepHour, 1e-9));
            $yi = (int) floor(($dn - $domMinY) / max($stepY, 1e-9));

            if ($xi < 0 || $yi < 0 || $xi >= $cols || $yi >= $rows) return;

            $hist[$yi][$xi] += 1.0;
        });

        return $hist;
    }

    // ---------- Domain helpers ----------

    private function domainWithHeadroom(float $min, float $max, ?float $hardMin, ?float $hardMax): array
    {
        $rawMin = $min * (1 - self::AXIS_HEADROOM);
        $rawMax = $max * (1 + self::AXIS_HEADROOM);

        if ($hardMin !== null) $rawMin = min($rawMin, $hardMin);
        if ($hardMax !== null) $rawMax = max($rawMax, $hardMax);

        $stepGuess = $this->niceStep(max($rawMax - $rawMin, 1e-6), 10);
        $domMin = max(0.0, $this->niceFloor($rawMin, $stepGuess));
        $domMax = $this->niceCeil($rawMax,  $stepGuess);

        if ($domMax <= $domMin) $domMax = $domMin + 1;

        return [$domMin, $domMax];
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
        array $density,
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
                $raw     = $hist[$yy][$xx];
                $dens    = $density[$yy][$xx];
                $hasRaw  = $raw > 0;

                $fill  = 'transparent';
                $label = 'no data';

                if ($hasRaw) {
                    $t     = $this->scaleValue($dens, $clampMin, $clampMax, self::VALUE_SCALE);
                    $fill  = $this->colorFor($t);
                    $label = 'samples: ' . (int) $raw;
                } elseif (self::COLOR_EMPTY_CELLS) {
                    $t    = $this->scaleValue($dens, $clampMin, $clampMax, self::VALUE_SCALE);
                    $fill = $this->colorFor($t);
                    $label = 'feathered';
                }

                $tiles[] = [
                    'x'        => $xx * (self::TILE + self::GAP),
                    'y'        => ($rows - 1 - $yy) * (self::TILE + self::GAP),
                    'w'        => self::TILE,
                    'h'        => self::TILE,
                    'fill'     => $fill,
                    'title'    => sprintf('X %s, Y %s: %s', $xLabels[$xx] ?? '', $yLabelsUp[$yy] ?? '', $label),
                    'uLabel'   => $xLabels[$xx]   ?? '',
                    'dLabel'   => $yLabelsUp[$yy] ?? '',
                    'count'    => (int) $raw,
                    'hasRaw'   => $hasRaw,
                ];
            }
        }

        return $tiles;
    }

    private function legendClamp(array $density): array
    {
        $vals = [];
        foreach ($density as $row) foreach ($row as $v) if ($v > 0) $vals[] = $v;
        sort($vals);
        $n      = count($vals);
        $maxBin = $n ? $vals[$n - 1] : 1.0;
        $p95    = $n ? $vals[(int) floor(0.95 * ($n - 1))] : 1.0;
        $p99    = $n ? $vals[(int) floor(0.99 * ($n - 1))] : $p95;

        switch (self::COLOR_SCALE_MODE) {
            case 'max': $hi = $maxBin; break;
            case 'p95': $hi = max(1.0, $p95); break;
            case 'p99': $hi = max(1.0, $p99); break;
            default:
                $hi = max(1.0, $p99);
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
        for ($i = 0; $i <= $steps; $i++) $bar[] = $this->colorFor($i / $steps);
        return array_reverse($bar);
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

    private function rangeLabelHour(float $fromHour, float $toHour): string
    {
        $fmt = function (float $h) {
            $h = fmod($h + 24.0, 24.0);
            $hh = (int) floor($h);
            $mm = (int) round(($h - $hh) * 60);
            if ($mm === 60) { $hh = ($hh + 1) % 24; $mm = 0; }
            return str_pad((string)$hh, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$mm, 2, '0', STR_PAD_LEFT);
        };
        return $fmt($fromHour) . '–' . $fmt($toHour);
    }

    /** Feather only radius-1 neighbors; renormalize */
    private function adjacentFeather(array $hist, float $centerWeight, float $neighborWeight, float $diagWeight = 0.0): array
    {
        $rows = count($hist);
        $cols = count($hist[0] ?? []);
        $out  = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $dirs4 = [[-1,0],[1,0],[0,-1],[0,1]];
        $dirsD = [[-1,-1],[-1,1],[1,-1],[1,1]];

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $cols; $x++) {
                $acc  = $hist[$y][$x] * $centerWeight;
                $wSum = $centerWeight;

                foreach ($dirs4 as [$dy,$dx]) {
                    $yy = $y + $dy; $xx = $x + $dx;
                    if ($yy >= 0 && $yy < $rows && $xx >= 0 && $xx < $cols) {
                        $acc  += $hist[$yy][$xx] * $neighborWeight;
                        $wSum += $neighborWeight;
                    }
                }
                if ($diagWeight > 0) {
                    foreach ($dirsD as [$dy,$dx]) {
                        $yy = $y + $dy; $xx = $x + $dx;
                        if ($yy >= 0 && $yy < $rows && $xx >= 0 && $xx < $cols) {
                            $acc  += $hist[$yy][$xx] * $diagWeight;
                            $wSum += $diagWeight;
                        }
                    }
                }

                $out[$y][$x] = $wSum > 0 ? $acc / $wSum : 0.0;
            }
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

    /** Map normalized t->[0,1] to a color for the current palette */
    private function colorFor(float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        return HeatmapPalettes::colorAt($this->palette, $t);
    }

private function legendTicks(float $min, float $max, int $count): array
{
    $ticks = [];
    for ($i = 0; $i <= $count; $i++) {
        $t   = $i / max($count, 1);
        $pos = 100 * (1 - $t);
        $val = $min + $t * ($max - $min);
        $ticks[] = ['pos' => $pos, 'value' => (int) round($val)];
    }
    return $ticks;
}

private function emptyPanel(string $title): array
{
    $bins = 5;
    $size = $bins * self::TILE + ($bins - 1) * self::GAP;

    $tiles = [];
    for ($yy = 0; $yy < $bins; $yy++) {
        for ($xx = 0; $xx < $bins; $xx++) {
            $tiles[] = [
                'x'      => $xx * (self::TILE + self::GAP),
                'y'      => ($bins - 1 - $yy) * (self::TILE + self::GAP),
                'w'      => self::TILE,
                'h'      => self::TILE,
                'fill'   => 'transparent',
                'title'  => 'no data',
                'uLabel' => '',
                'dLabel' => '',
                'count'  => 0,
                'hasRaw' => false,
            ];
        }
    }

    return [
        'title'           => $title,
        'xAxisTitle'      => '',
        'yAxisTitle'      => '',
        'xLabels'         => [],
        'yLabels'         => [],
        'xTickEvery'      => 1,
        'yTickEvery'      => 1,
        'width'           => $size,
        'height'          => $size,
        'gap'             => self::GAP,
        'tiles'           => $tiles,
        'legendBar'       => $this->legendBar(self::LEGEND_STEPS),
        'legendTicks'     => $this->legendTicks(0, 6, 6),
        'tileStrokeColor' => self::TILE_STROKE_COLOR,
        'tileStrokeWidth' => self::TILE_STROKE_WIDTH,
    ];
}
}