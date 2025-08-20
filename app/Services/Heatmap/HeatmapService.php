<?php

declare(strict_types=1);

namespace App\Services\Heatmap;

use App\Enums\ResultStatus;
use App\Helpers\HeatmapPalettes;
use App\Helpers\Number;
use App\Models\Result;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single-responsibility service:
 * - Streams data from DB with chunking
 * - One pass for reservoir + global extrema (reduces total scans)
 * - Decides bins (FD + Sturges snapped to nice)
 * - Builds two histograms (Upload×Download) and (Download×Hour)
 * - Applies feathering, scaling, legends, and renders tile arrays for Blade
 */
class HeatmapService
{
    /** Stream results in id order; closure receives ($upMbit, $dnMbit, $tsCarbon) */
    private function streamResults(callable $cb): void
    {
        $this->baseQuery()
            ->chunkById(5000, function ($chunk) use ($cb) {
                foreach ($chunk as $r) {
                    $db = $r->download_bits ?? null;
                    $ub = $r->upload_bits   ?? null;
                    if ($db === null && $r->download !== null) $db = (float) $r->download * 8.0;
                    if ($ub === null && $r->upload   !== null) $ub = (float) $r->upload   * 8.0;
                    if ($db === null || $ub === null) continue;

                    $dn = (float) Number::bitsToMagnitude(bits: (float) $db, precision: 6, magnitude: 'mbit');
                    $up = (float) Number::bitsToMagnitude(bits: (float) $ub, precision: 6, magnitude: 'mbit');

                    $cb($up, $dn, $r->created_at);
                }
            });
    }

    private function baseQuery(): Builder
    {
        return Result::query()
            ->select(['id', 'download', 'upload', 'download_bits', 'upload_bits', 'created_at', 'status'])
            ->where('status', ResultStatus::Completed)
            ->orderBy('id');
    }

    /** Public entrypoint used by the widget */
    public function computePanels(string $palette, array $options, string $timezone = 'UTC'): array
    {
        $cfg = $this->normalizeOptions($options);

        // Pass #1: sample + global extrema
        $scan = $this->scanStats($cfg);

        // Decide bins from scan
        $bins = $this->autoBinsFromScan($scan, $cfg);

        // Domains with headroom / optional overrides
        [$domMinX, $domMaxX] = $this->domainWithHeadroom(
            $scan['min_up'], $scan['max_up'],
            $cfg['axis_min_up'], $cfg['axis_max_up'],
            $cfg['axis_headroom']
        );
        [$domMinY, $domMaxY] = $this->domainWithHeadroom(
            $scan['min_dn'], $scan['max_dn'],
            $cfg['axis_min_dn'], $cfg['axis_max_dn'],
            $cfg['axis_headroom']
        );

        // Steps + label sequences
        $stepX = ($domMaxX - $domMinX) / $bins;
        $stepY = ($domMaxY - $domMinY) / $bins;

        $xStarts = $this->seq($domMinX, $stepX, $bins);
        $yStarts = $this->seq($domMinY, $stepY, $bins);

        $xLabels   = array_map(fn($v) => $this->rangeLabel($v, $v + $stepX), $xStarts);
        $yLabelsUp = array_map(fn($v) => $this->rangeLabel($v, $v + $stepY), $yStarts);
        $yLabels   = array_reverse($yLabelsUp);

        // Pass #2: UD histogram
        $histUD = $this->histUploadDownload(
            $bins, $bins, $domMinX, $stepX, $domMinY, $stepY
        );

        $densityUD = $this->adjacentFeather(
            $histUD, $cfg['feather_center'], $cfg['feather_neighbor'], $cfg['feather_diag']
        );
        [$clampMinUD, $clampMaxUD] = $this->legendClamp($densityUD, $cfg['color_scale_mode']);

        $size = $bins * $cfg['tile'] + ($bins - 1) * $cfg['gap'];

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
            'gap'             => $cfg['gap'],
            'tiles'           => $this->buildTiles(
                $histUD,
                $densityUD,
                $bins,
                $bins,
                $xLabels,
                $yLabelsUp,
                $clampMinUD,
                $clampMaxUD,
                $palette,
                $cfg['value_scale'],
                $cfg['tile'],
                $cfg['gap'],
                $cfg['color_empty']
            ),
            'legendBar'       => $this->legendBar($palette, $cfg['legend_steps']),
            'legendTicks'     => $this->legendTicks($clampMinUD, $clampMaxUD, 6),
            'tileStrokeColor' => $cfg['tile_stroke_color'],
            'tileStrokeWidth' => $cfg['tile_stroke_width'],
        ];

        // Pass #3: Download × Hour histogram
        $cols  = $bins;
        $rows  = $bins;
        $stepH = 24 / $cols;

        $xStartsH = $this->seq(0, $stepH, $cols);
        $yStartsH = $this->seq($domMinY, $stepY, $rows);

        $xLabelsH = array_map(fn($v) => $this->rangeLabelHour($v, $v + $stepH), $xStartsH);
        $yLabelsUpH = array_map(fn($v) => $this->rangeLabel($v, $v + $stepY), $yStartsH);
        $yLabelsH = array_reverse($yLabelsUpH);

        $histDH = $this->histDownloadByHour(
            $cols, $rows, $stepH, $domMinY, $stepY, $timezone
        );

        $densityDH = $this->adjacentFeather(
            $histDH, $cfg['feather_center'], $cfg['feather_neighbor'], $cfg['feather_diag']
        );
        [$clampMinDH, $clampMaxDH] = $this->legendClamp($densityDH, $cfg['color_scale_mode']);

        $panelB = [
            'title'           => 'Download x Time of Day',
            'xAxisTitle'      => 'Hour of Day',
            'yAxisTitle'      => 'Download (Mbit/s)',
            'xLabels'         => $xLabelsH,
            'yLabels'         => $yLabelsH,
            'xTickEvery'      => max(1, (int) ceil($cols / 12)),
            'yTickEvery'      => max(1, (int) ceil($rows / 10)),
            'width'           => $size,
            'height'          => $size,
            'gap'             => $cfg['gap'],
            'tiles'           => $this->buildTiles(
                $histDH,
                $densityDH,
                $cols,
                $rows,
                $xLabelsH,
                $yLabelsUpH,
                $clampMinDH,
                $clampMaxDH,
                $palette,
                $cfg['value_scale'],
                $cfg['tile'],
                $cfg['gap'],
                $cfg['color_empty']
            ),
            'legendBar'       => $this->legendBar($palette, $cfg['legend_steps']),
            'legendTicks'     => $this->legendTicks($clampMinDH, $clampMaxDH, 6),
            'tileStrokeColor' => $cfg['tile_stroke_color'],
            'tileStrokeWidth' => $cfg['tile_stroke_width'],
        ];

        // Handle empty-data case gracefully (keep UI consistent)
        if ($scan['count'] === 0) {
            return [$this->emptyPanel('Throughput (Upload x Download)', $cfg), $this->emptyPanel('Download x Time of Day', $cfg)];
        }

        return [$panelA, $panelB];
    }

    // ---------------------- Stats / Bins ----------------------

    private function scanStats(array $cfg): array
    {
        $K = 4096;
        $ups = [];
        $dns = [];
        $n   = 0;

        $minUp = INF; $maxUp = 0.0;
        $minDn = INF; $maxDn = 0.0;

        $this->streamResults(function (float $up, float $dn) use (&$ups, &$dns, &$n, $K, &$minUp, &$maxUp, &$minDn, &$maxDn) {
            $n++;
            $this->reservoirPush($ups, $up, $n, $K);
            $this->reservoirPush($dns, $dn, $n, $K);

            if ($up < $minUp) $minUp = $up;
            if ($up > $maxUp) $maxUp = $up;
            if ($dn < $minDn) $minDn = $dn;
            if ($dn > $maxDn) $maxDn = $dn;
        });

        sort($ups);
        sort($dns);

        return [
            'count'  => $n,
            'ups'    => $ups, 'dns'   => $dns,
            'min_up' => $minUp, 'max_up' => $maxUp,
            'min_dn' => $minDn, 'max_dn' => $maxDn,
        ];
    }

    private function autoBinsFromScan(array $scan, array $cfg): int
    {
        if ($scan['count'] === 0) {
            return $this->snapToNice(48, $cfg['bins_nice']);
        }

        $iqrU = $this->iqr($scan['ups']);
        $iqrD = $this->iqr($scan['dns']);

        $spanU = (end($scan['ups']) - $scan['ups'][0]) ?: 1e-9;
        $spanD = (end($scan['dns']) - $scan['dns'][0]) ?: 1e-9;

        $fdU = $this->freedmanDiaconisBins($iqrU, $spanU, $scan['count']);
        $fdD = $this->freedmanDiaconisBins($iqrD, $spanD, $scan['count']);
        $sturges = (int) ceil(log(max($scan['count'], 1), 2) + 1);

        $raw = (int) round($this->median([$fdU, $fdD, $sturges]));
        $clamped = max($cfg['bins_min'], min($cfg['bins_max'], max(6, $raw)));

        return $this->snapToNice($clamped, $cfg['bins_nice']);
    }

    // ---------------------- Histograms ----------------------

    private function histUploadDownload(
        int $cols, int $rows,
        float $domMinX, float $stepX,
        float $domMinY, float $stepY
    ): array {
        $hist = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $this->streamResults(function (float $up, float $dn) use (&$hist, $cols, $rows, $domMinX, $stepX, $domMinY, $stepY) {
            $xi = (int) floor(($up - $domMinX) / max($stepX, 1e-9));
            $yi = (int) floor(($dn - $domMinY) / max($stepY, 1e-9));
            if ($xi < 0 || $yi < 0 || $xi >= $cols || $yi >= $rows) return;
            $hist[$yi][$xi] += 1.0;
        });

        return $hist;
    }

    private function histDownloadByHour(
        int $cols, int $rows,
        float $stepHour,
        float $domMinY, float $stepY,
        string $timezone
    ): array {
        $hist = array_fill(0, $rows, array_fill(0, $cols, 0.0));

        $this->streamResults(function (float $_up, float $dn, $ts) use (&$hist, $cols, $rows, $stepHour, $domMinY, $stepY, $timezone) {
            if (!$ts) return;
            $hour = (int) ($ts->timezone($timezone)->format('G'));
            $xi = (int) floor($hour / max($stepHour, 1e-9));
            $yi = (int) floor(($dn - $domMinY) / max($stepY, 1e-9));
            if ($xi < 0 || $yi < 0 || $xi >= $cols || $yi >= $rows) return;
            $hist[$yi][$xi] += 1.0;
        });

        return $hist;
    }

    // ---------------------- Rendering ----------------------

    private function buildTiles(
        array $hist,
        array $density,
        int $cols,
        int $rows,
        array $xLabels,
        array $yLabelsUp,
        float $clampMin,
        float $clampMax,
        string $palette,
        string $valueScale,
        int $tile,
        int $gap,
        bool $colorEmpty
    ): array {
        $tiles = [];

        for ($yy = 0; $yy < $rows; $yy++) {
            for ($xx = 0; $xx < $cols; $xx++) {
                $raw    = $hist[$yy][$xx];
                $dens   = $density[$yy][$xx];
                $hasRaw = $raw > 0;

                $fill  = 'transparent';
                $label = 'no data';

                if ($hasRaw) {
                    $t = $this->scaleValue($dens, $clampMin, $clampMax, $valueScale);
                    $fill  = HeatmapPalettes::colorAt($palette, $t);
                    $label = 'samples: ' . (int) $raw;
                } elseif ($colorEmpty) {
                    $t    = $this->scaleValue($dens, $clampMin, $clampMax, $valueScale);
                    $fill = HeatmapPalettes::colorAt($palette, $t);
                    $label = 'feathered';
                }

                $tiles[] = [
                    'x'        => $xx * ($tile + $gap),
                    'y'        => ($rows - 1 - $yy) * ($tile + $gap),
                    'w'        => $tile,
                    'h'        => $tile,
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

    private function legendBar(string $palette, int $steps): array
    {
        $bar = [];
        for ($i = 0; $i <= $steps; $i++) {
            $bar[] = HeatmapPalettes::colorAt($palette, $i / $steps);
        }
        return array_reverse($bar);
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

    private function emptyPanel(string $title, array $cfg): array
    {
        $bins = 5;
        $size = $bins * $cfg['tile'] + ($bins - 1) * $cfg['gap'];

        $tiles = [];
        for ($yy = 0; $yy < $bins; $yy++) {
            for ($xx = 0; $xx < $bins; $xx++) {
                $tiles[] = [
                    'x'      => $xx * ($cfg['tile'] + $cfg['gap']),
                    'y'      => ($bins - 1 - $yy) * ($cfg['tile'] + $cfg['gap']),
                    'w'      => $cfg['tile'],
                    'h'      => $cfg['tile'],
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
            'gap'             => $cfg['gap'],
            'tiles'           => $tiles,
            'legendBar'       => $this->legendBar('viridis', 48),
            'legendTicks'     => $this->legendTicks(0, 6, 6),
            'tileStrokeColor' => $cfg['tile_stroke_color'],
            'tileStrokeWidth' => $cfg['tile_stroke_width'],
        ];
    }

    // ---------------------- Math / Helpers ----------------------

    private function normalizeOptions(array $options): array
    {
        $axis = $options['axis_overrides'] ?? [];
        [$strokeColor, $strokeWidth] = $options['tile_stroke'] ?? ['rgba(0,0,0,0.25)', 0.5];
        [$fC, $fN, $fD] = $options['feather'] ?? [0.8, 0.6, 0.4];

        return [
            'tile'   => (int) ($options['tile'] ?? 16),
            'gap'    => (int) ($options['gap'] ?? -1),

            'bins_min'  => (int) ($options['bins_min'] ?? 24),
            'bins_max'  => (int) ($options['bins_max'] ?? 48),
            'bins_nice' => is_array($options['bins_nice'] ?? null) ? $options['bins_nice'] : [24,28,32,36,40,44,48],

            'axis_headroom' => (float) ($options['axis_headroom'] ?? 0.08),
            'axis_min_up'   => $axis['min_up'] ?? null,
            'axis_max_up'   => $axis['max_up'] ?? null,
            'axis_min_dn'   => $axis['min_dn'] ?? null,
            'axis_max_dn'   => $axis['max_dn'] ?? null,

            'feather_center'  => (float) $fC,
            'feather_neighbor'=> (float) $fN,
            'feather_diag'    => (float) $fD,

            'color_empty'      => (bool) ($options['color_empty'] ?? true),
            'color_scale_mode' => (string) ($options['color_scale_mode'] ?? 'adaptive'),
            'value_scale'      => (string) ($options['value_scale'] ?? 'sqrt'),

            'legend_steps'     => (int) ($options['legend_steps'] ?? 48),
            'tile_stroke_color'=> (string) $strokeColor,
            'tile_stroke_width'=> (float) $strokeWidth,
        ];
    }

    private function reservoirPush(array &$res, float $value, int $seen, int $K): void
    {
        $count = count($res);
        if ($count < $K) { $res[] = $value; return; }
        $j = random_int(1, $seen);
        if ($j <= $K) $res[$j - 1] = $value;
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

    private function freedmanDiaconisBins(float $iqr, float $span, int $n): int
    {
        if ($iqr <= 0 || $n <= 1) {
            return (int) ceil(log(max($n,1), 2) + 1);
        }
        $h = 2.0 * $iqr / pow($n, 1.0 / 3.0);
        if ($h <= 0) return (int) ceil(log(max($n,1), 2) + 1);
        return max(1, (int) ceil($span / $h));
    }

    private function snapToNice(int $b, array $nice): int
    {
        $best = $nice[0];
        $bestDiff = abs($b - $best);
        foreach ($nice as $n) {
            $d = abs($b - $n);
            if ($d < $bestDiff) { $best = $n; $bestDiff = $d; }
        }
        return $best;
    }

    private function domainWithHeadroom(float $min, float $max, ?float $hardMin, ?float $hardMax, float $headroom): array
    {
        $rawMin = $min * (1 - $headroom);
        $rawMax = $max * (1 + $headroom);

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

    private function legendClamp(array $density, string $mode): array
    {
        $vals = [];
        foreach ($density as $row) foreach ($row as $v) if ($v > 0) $vals[] = $v;
        sort($vals);
        $n      = count($vals);
        $maxBin = $n ? $vals[$n - 1] : 1.0;
        $p95    = $n ? $vals[(int) floor(0.95 * ($n - 1))] : 1.0;
        $p99    = $n ? $vals[(int) floor(0.99 * ($n - 1))] : $p95;

        switch ($mode) {
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
}
