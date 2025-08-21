<?php

declare(strict_types=1);

namespace App\Services\Heatmap;

use App\Enums\ResultStatus;
use App\Helpers\HeatmapPalettes;
use App\Helpers\Number;
use App\Models\Result;
use Illuminate\Database\Eloquent\Builder;
use Random\RandomException;

class HeatmapService
{
    private const TITLE_THROUGHPUT = 'Throughput (Upload x Download)';

    private const TITLE_DOWNLOAD_TIME = 'Download x Time of Day';

    private const X_TITLE_UPLOAD = 'Upload (Mbit/s)';

    private const Y_TITLE_DOWNLOAD = 'Download (Mbit/s)';

    private const X_TITLE_HOUR = 'Hour of Day';

    /**
     * Computes and generates panels for data visualization with heatmap configurations.
     *
     * This method creates two types of panels:
     * 1. A heatmap showing throughput as "Upload x Download".
     * 2. A heatmap showing "Download x Time of Day".
     *
     * Each panel is created based on statistical data, binning strategies,
     * and visual configurations defined in the application.
     *
     * @param  string  $palette  The color palette used for the generated heatmaps.
     * @param  string|null  $timezone  The timezone for time-based panel computations. Defaults to 'UTC'.
     * @return array An array of two panels, each represented as an associative array containing
     *               metadata and rendering information for heatmaps.
     */
    public function computePanels(string $palette, ?string $timezone = null): array
    {
        $cfg = HeatmapConfig::forCompute();
        $timezone ??= (string) config('app.timezone', 'UTC');

        // Pass #1: sample + global extrema
        $scan = $this->scanStats();

        // Handle empty-data case early
        if ($scan['count'] === 0) {
            return [
                $this->emptyPanel(self::TITLE_THROUGHPUT, $cfg),
                $this->emptyPanel(self::TITLE_DOWNLOAD_TIME, $cfg),
            ];
        }

        // Decide bins from scan
        $bins = $this->autoBinsFromScan($scan, $cfg);

        // Domains with headroom / optional overrides
        [$uploadDomMin, $uploadDomMax] = $this->domainWithHeadroom(
            $scan['min_up'], $scan['max_up'],
            $cfg['axis_min_up'], $cfg['axis_max_up'],
            $cfg['axis_headroom']
        );
        [$downloadDomMin, $downloadDomMax] = $this->domainWithHeadroom(
            $scan['min_dn'], $scan['max_dn'],
            $cfg['axis_min_dn'], $cfg['axis_max_dn'],
            $cfg['axis_headroom']
        );

        // Steps + labels (Upload / Download)
        // Start variables were used for ticks on the heatmaps that was removed.
        [$uploadStep, $_uploadStarts, $uploadLabels] = $this->buildAxis(
            $uploadDomMin, $uploadDomMax, $bins,
            fn (float $from, float $to) => $this->rangeLabel($from, $to)
        );
        [$downloadStep, $_downloadStarts, $downloadLabelsUp] = $this->buildAxis(
            $downloadDomMin, $downloadDomMax, $bins,
            fn (float $from, float $to) => $this->rangeLabel($from, $to)
        );
        $downloadLabels = array_reverse($downloadLabelsUp);

        // Hist A: Upload × Download
        $histUD = $this->histUploadDownload($bins, $bins, $uploadDomMin, $uploadStep, $downloadDomMin, $downloadStep);
        [$densityUD, $clampMinUD, $clampMaxUD] = $this->densityAndClamp($histUD, $cfg);

        $size = $this->panelSize($bins, $cfg);

        $panelA = $this->composePanel(
            title: self::TITLE_THROUGHPUT,
            xAxisTitle: self::X_TITLE_UPLOAD,
            yAxisTitle: self::Y_TITLE_DOWNLOAD,
            xLabels: $uploadLabels,
            yLabels: $downloadLabels,
            yLabelsUp: $downloadLabelsUp,
            hist: $histUD,
            density: $densityUD,
            cols: $bins,
            rows: $bins,
            size: $size,
            cfg: $cfg,
            palette: $palette,
            clampMin: $clampMinUD,
            clampMax: $clampMaxUD
        );

        // Hist B: Download × Hour
        // Start variables were used for ticks on the heatmaps that was removed.
        [$hourStep, $_hourStarts, $hourLabels] = $this->buildAxis(
            0.0, 24.0, $bins,
            fn (float $from, float $to) => $this->rangeLabelHour($from, $to)
        );
        // Reuse previously computed download axis pieces for rows
        $downloadLabelsH = array_reverse($downloadLabelsUp);

        $histDH = $this->histDownloadByHour($bins, $bins, $hourStep, $downloadDomMin, $downloadStep, $timezone);
        [$densityDH, $clampMinDH, $clampMaxDH] = $this->densityAndClamp($histDH, $cfg);

        $panelB = $this->composePanel(
            title: self::TITLE_DOWNLOAD_TIME,
            xAxisTitle: self::X_TITLE_HOUR,
            yAxisTitle: self::Y_TITLE_DOWNLOAD,
            xLabels: $hourLabels,
            yLabels: $downloadLabelsH,
            yLabelsUp: $downloadLabelsUp,
            hist: $histDH,
            density: $densityDH,
            cols: $bins,
            rows: $bins,
            size: $size,
            cfg: $cfg,
            palette: $palette,
            clampMin: $clampMinDH,
            clampMax: $clampMaxDH
        );

        return [$panelA, $panelB];
    }

    private function composePanel(
        string $title,
        string $xAxisTitle,
        string $yAxisTitle,
        array $xLabels,
        array $yLabels,
        array $yLabelsUp,
        array $hist,
        array $density,
        int $cols,
        int $rows,
        int $size,
        array $cfg,
        string $palette,
        float $clampMin,
        float $clampMax
    ): array {
        return [
            'title' => $title,
            'xAxisTitle' => $xAxisTitle,
            'yAxisTitle' => $yAxisTitle,
            'xLabels' => $xLabels,
            'yLabels' => $yLabels,
            'xTickEvery' => $this->tickEvery($cols, 12),
            'yTickEvery' => $this->tickEvery($rows, 10),
            'width' => $size,
            'height' => $size,
            'gap' => $cfg['gap'],
            'tiles' => $this->buildTiles(
                $hist, $density, $cols, $rows, $xLabels, $yLabelsUp,
                $clampMin, $clampMax, $palette, $cfg['value_scale'],
                $cfg['tile'], $cfg['gap'], $cfg['color_empty']
            ),
            'legendBar' => $this->legendBar($palette, $cfg['legend_steps']),
            'legendTicks' => $this->legendTicks($clampMin, $clampMax, 6),
            'tileStrokeColor' => $cfg['tile_stroke_color'],
            'tileStrokeWidth' => $cfg['tile_stroke_width'],
        ];
    }

    private function tickEvery(int $bins, int $target): int
    {
        return max(1, (int) ceil($bins / max($target, 1)));
    }

    private function panelSize(int $bins, array $cfg): int
    {
        return $bins * $cfg['tile'] + ($bins - 1) * $cfg['gap'];
    }

    /**
     * Builds an axis step, starts, and labels using a provided label formatter.
     *
     * @param  callable  $labelFn  fn (float $from, float $to): string
     * @return array{0: float, 1: array, 2: array}
     */
    private function buildAxis(float $domMin, float $domMax, int $bins, callable $labelFn): array
    {
        $step = ($domMax - $domMin) / max($bins, 1);
        $starts = $this->seq($domMin, $step, $bins);
        $labels = array_map(fn ($v) => $labelFn($v, $v + $step), $starts);

        return [$step, $starts, $labels];
    }

    private function densityAndClamp(array $hist, array $cfg): array
    {
        $density = $this->adjacentFeather(
            $hist,
            $cfg['feather_center'],
            $cfg['feather_neighbor'],
            $cfg['feather_diag']
        );
        [$clampMin, $clampMax] = $this->legendClamp($density, $cfg['color_scale_mode']);

        return [$density, $clampMin, $clampMax];
    }

    // -------- Data streaming & scans --------

    /**
     * Base Eloquent query for streaming completed results in id order.
     */
    private function baseQuery(): Builder
    {
        return Result::query()
            ->select(['id', 'download', 'upload', 'download_bits', 'upload_bits', 'created_at', 'status'])
            ->where('status', ResultStatus::Completed);
    }

    /**
     * Stream results in stable id order, invoking a callback per row.
     *
     * Converts download/upload to Mbps, preferring *_bits columns when present,
     * else falling back to byte-per-second columns multiplied by 8.
     *
     * @param  callable  $callback  fn(float $uploadMbit, float $downloadMbit, \Carbon\CarbonInterface|null $createdAt): void
     */
    private function streamResults(callable $callback): void
    {
        // Magic number: 5000 rows per chunk is a pragmatic balance — large enough to be I/O efficient,
        // small enough to keep memory bounded during hydration.
        $this->baseQuery()->chunkById(5000, function ($chunk) use ($callback) {
            foreach ($chunk as $row) {
                // Prefer explicit bit/second fields; otherwise convert bytes/sec → bits/sec (* 8.0).
                $downloadBitsPerSec = $row->download_bits ?? (isset($row->download) ? (float) $row->download * 8.0 : null);
                $uploadBitsPerSec = $row->upload_bits ?? (isset($row->upload) ? (float) $row->upload * 8.0 : null);

                if ($downloadBitsPerSec === null || $uploadBitsPerSec === null) {
                    continue; // skip incomplete measurements
                }

                // Convert bits/sec → megabits/sec with fixed precision to avoid FP noise chaining.
                // Magic number: precision=6 is a rendering/consistency choice; it keeps enough details without bloat.
                $downloadMbit = Number::bitsToMagnitude(bits: $downloadBitsPerSec, precision: 6, magnitude: 'mbit');
                $uploadMbit = Number::bitsToMagnitude(bits: $uploadBitsPerSec, precision: 6, magnitude: 'mbit');

                $callback($uploadMbit, $downloadMbit, $row->created_at);
            }
        });
    }

    /**
     * A single pass over the dataset to collect:
     * - uniform reservoirs (samples) for upload/download
     * - global min/max for upload/download
     * - total count
     *
     * @return array{
     *   count:int,
     *   ups: float[],
     *   dns: float[],
     *   min_up: float, max_up: float,
     *   min_dn: float, max_dn: float
     * }
     */
    private function scanStats(): array
    {
        // Magic number: reservoir size (K=4096). Large enough for stable quantiles and FD bins,
        // still cheap to sort. Tune via config if needed.

        $uploadReservoir = [];
        $downloadReservoir = [];

        $totalSeen = 0;

        // Initialize extrema. INF ensures the first comparison wins.
        $minUpload = INF;
        $maxUpload = 0.0;
        $minDownload = INF;
        $maxDownload = 0.0;

        $this->streamResults(function (float $uploadMbit, float $downloadMbit) use (
            &$uploadReservoir, &$downloadReservoir, &$totalSeen,
            &$minUpload, &$maxUpload, &$minDownload, &$maxDownload
        ) {
            $reservoirSize = 4096;
            $totalSeen++;

            // Maintain uniform samples for robust quantile/FD estimation.
            $this->reservoirPush($uploadReservoir, $uploadMbit, $totalSeen, $reservoirSize);
            $this->reservoirPush($downloadReservoir, $downloadMbit, $totalSeen, $reservoirSize);

            // Update global extrema.
            if ($uploadMbit < $minUpload) {
                $minUpload = $uploadMbit;
            }
            if ($uploadMbit > $maxUpload) {
                $maxUpload = $uploadMbit;
            }
            if ($downloadMbit < $minDownload) {
                $minDownload = $downloadMbit;
            }
            if ($downloadMbit > $maxDownload) {
                $maxDownload = $downloadMbit;
            }
        });

        sort($uploadReservoir);
        sort($downloadReservoir);

        return [
            'count' => $totalSeen,
            'ups' => $uploadReservoir,
            'dns' => $downloadReservoir,
            'min_up' => $minUpload,
            'max_up' => $maxUpload,
            'min_dn' => $minDownload,
            'max_dn' => $maxDownload,
        ];
    }

    /**
     * Choose a "nice" bin count based on the scan:
     * - compute FD bins for both upload/download using reservoir quantiles
     * - include Sturges' rule as a small-n fallback
     * - take the median of the three
     * - clamp to the configured bounds and snap to preferred "nice" values
     */
    private function autoBinsFromScan(array $scan, array $cfg): int
    {
        // Empty dataset: default to a reasonable visual density.
        // Magic number: 48 is a pleasant upper-end default grid (also aligns with our legend default).
        if ($scan['count'] === 0) {
            return $this->snapToNice(48, $cfg['bins_nice']);
        }

        // Interquartile ranges from the sampled, sorted reservoirs.
        $iqrUpload = $this->iqr($scan['ups']);
        $iqrDownload = $this->iqr($scan['dns']);

        // Data spans (avoid zero; Magic number 1e-9 prevents div/0 downstream).
        $uploadSpan = (end($scan['ups']) - $scan['ups'][0]) ?: 1e-9;
        $downloadSpan = (end($scan['dns']) - $scan['dns'][0]) ?: 1e-9;

        // Freedman–Diaconis recommended bin counts.
        $fdBinsUpload = $this->freedmanDiaconisBins($iqrUpload, $uploadSpan, $scan['count']);
        $fdBinsDownload = $this->freedmanDiaconisBins($iqrDownload, $downloadSpan, $scan['count']);

        // Sturges' rule: ceil(log2(n) + 1). Works well for small/normal data.
        $sturgesBins = (int) ceil(log(max($scan['count'], 1), 2) + 1);

        // Take the median of the three estimates to avoid outliers dominating.
        $medianRecommendation = (int) round($this->median([$fdBinsUpload, $fdBinsDownload, $sturgesBins]));

        // Clamp within configured bounds and ensure a minimum readability floor of 6 bins.
        // Magic number 6: avoids unreadably coarse grids (empirical UX floor).
        $clamped = max($cfg['bins_min'], min($cfg['bins_max'], max(6, $medianRecommendation)));

        // Snap to a preferred aesthetically "nice" bin count (e.g., 24/28/32/36/40/44/48).
        return $this->snapToNice($clamped, $cfg['bins_nice']);
    }

    // -------- Histograms --------

    /**
     * Build a 2D histogram of Upload (X) × Download (Y) sample counts.
     *
     * @param  int  $columnCount  Number of X bins.
     * @param  int  $rowCount  Number of Y bins.
     * @param  float  $domainMinX  Lower bound of X (Upload) domain.
     * @param  float  $stepX  Bin width along X.
     * @param  float  $domainMinY  Lower bound of Y (Download) domain.
     * @param  float  $stepY  Bin height along Y.
     * @return array<float[]> 2D grid [row][col] of counts.
     */
    private function histUploadDownload(
        int $columnCount,
        int $rowCount,
        float $domainMinX,
        float $stepX,
        float $domainMinY,
        float $stepY
    ): array {
        // Preallocate grid with zeros
        $histogramGrid = array_fill(0, $rowCount, array_fill(0, $columnCount, 0.0));

        $this->streamResults(function (float $uploadMbit, float $downloadMbit) use (
            &$histogramGrid,
            $columnCount,
            $rowCount,
            $domainMinX,
            $stepX,
            $domainMinY,
            $stepY
        ) {
            // Guard: use a very small epsilon to avoid divide-by-zero when step == 0.
            // Magic number 1e-9: sufficiently small relative to Mbps scales.
            $columnIndex = (int) floor(($uploadMbit - $domainMinX) / max($stepX, 1e-9));
            $rowIndex = (int) floor(($downloadMbit - $domainMinY) / max($stepY, 1e-9));
            $columnIndex = max(0, min($columnCount - 1, $columnIndex));
            $rowIndex = max(0, min($rowCount - 1, $rowIndex));

            $histogramGrid[$rowIndex][$columnIndex] += 1.0;
        });

        return $histogramGrid;
    }

    /**
     * Build a 2D histogram of Hour-of-day (X) × Download (Y) sample counts.
     *
     * @param  int  $columnCount  Number of X bins (time-of-day bins).
     * @param  int  $rowCount  Number of Y bins (download bins).
     * @param  float  $hourStep  Bin width along X in hours (e.g., 1.0 → hourly bins).
     * @param  float  $domainMinY  Lower bound of Y (Download) domain.
     * @param  float  $stepY  Bin height along Y.
     * @param  string  $timezone  IANA timezone used to compute local hour.
     * @return array<float[]> 2D grid [row][col] of counts.
     */
    private function histDownloadByHour(
        int $columnCount,
        int $rowCount,
        float $hourStep,
        float $domainMinY,
        float $stepY,
        string $timezone
    ): array {
        // Preallocate grid with zeros
        $histogramGrid = array_fill(0, $rowCount, array_fill(0, $columnCount, 0.0));

        $this->streamResults(function ($_uploadIgnored, float $downloadMbit, $timestamp) use (
            &$histogramGrid,
            $columnCount,
            $rowCount,
            $hourStep,
            $domainMinY,
            $stepY,
            $timezone
        ) {
            if (! $timestamp) {
                return;
            }

            // Carbon::format('G') returns 0..23 without leading zero.
            // We deliberately use integer hours here; finer buckets come from $hourStep.
            $hourOfDay = (int) $timestamp->timezone($timezone)->format('G'); // 0..23

            // Guard with epsilon to avoid division by zero when hourStep == 0.
            $columnIndex = (int) floor($hourOfDay / max($hourStep, 1e-9));
            $rowIndex = (int) floor(($downloadMbit - $domainMinY) / max($stepY, 1e-9));

            $columnIndex = max(0, min($columnCount - 1, $columnIndex));
            $rowIndex = max(0, min($rowCount - 1, $rowIndex));

            $histogramGrid[$rowIndex][$columnIndex] += 1.0;
        });

        return $histogramGrid;
    }

    // -------- Render helpers --------

    /**
     * Build renderable heatmap tiles from raw counts and feathered densities.
     *
     * @param  array  $rawCountGrid  2D array [row][col] of raw sample counts.
     * @param  array  $densityGrid  2D array [row][col] of feathered densities.
     * @param  int  $columnCount  Number of columns (X bins).
     * @param  int  $rowCount  Number of rows (Y bins).
     * @param  array<string>  $xAxisLabels  Display labels for X bins (index = column).
     * @param  array<string>  $yAxisLabelsUpwards  Display labels for Y bins in upward order (index = row).
     * @param  float  $legendMin  Clamp minimum for legend scaling.
     * @param  float  $legendMax  Clamp maximum for legend scaling.
     * @param  string  $paletteName  Palette id for HeatmapPalettes::colorAt().
     * @param  string  $valueScaleMode  'linear' | 'log' | 'sqrt' for density normalization.
     * @param  int  $tileSize  Tile width/height in px.
     * @param  int  $tileGap  Gap between tiles in px.
     * @param  bool  $colorEmptyCells  If true, color cells with no raw samples using density.
     * @return array<int, array<string, mixed>>
     */
    private function buildTiles(
        array $rawCountGrid,
        array $densityGrid,
        int $columnCount,
        int $rowCount,
        array $xAxisLabels,
        array $yAxisLabelsUpwards,
        float $legendMin,
        float $legendMax,
        string $paletteName,
        string $valueScaleMode,
        int $tileSize,
        int $tileGap,
        bool $colorEmptyCells
    ): array {
        $tiles = [];

        $pitch = $tileSize + $tileGap;

        for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
            for ($columnIndex = 0; $columnIndex < $columnCount; $columnIndex++) {
                $rawCount = $rawCountGrid[$rowIndex][$columnIndex];
                $densityValue = $densityGrid[$rowIndex][$columnIndex];
                $hasRawData = $rawCount > 0;

                $fillColor = 'transparent';
                $tooltip = 'no data';

                // When the cell has raw samples, we color by normalized density
                // (feathered) and show the sample count. Otherwise, if allowed,
                // we still color by feathered density but label as "feathered".
                if ($hasRawData) {
                    $normalized = $this->scaleValue($densityValue, $legendMin, $legendMax, $valueScaleMode);
                    $fillColor = HeatmapPalettes::colorAt($paletteName, $normalized);
                    $tooltip = 'samples: '.(int) $rawCount;
                } elseif ($colorEmptyCells) {
                    $normalized = $this->scaleValue($densityValue, $legendMin, $legendMax, $valueScaleMode);
                    $fillColor = HeatmapPalettes::colorAt($paletteName, $normalized);
                    $tooltip = 'feathered';
                }

                // Positioning:
                //  - X grows left→right with column index.
                //  - Y is flipped so that larger row indices appear higher on the chart
                //    (SVG/HTML coordinate system origin is top-left, so we invert Y).
                $tiles[] = [
                    'x' => (int) ($columnIndex * $pitch),
                    'y' => (int) (($rowCount - 1 - $rowIndex) * $pitch),
                    'w' => $tileSize,
                    'h' => $tileSize,
                    'fill' => $fillColor,
                    'title' => sprintf(
                        'X %s, Y %s: %s',
                        $xAxisLabels[$columnIndex] ?? '',
                        $yAxisLabelsUpwards[$rowIndex] ?? '',
                        $tooltip
                    ),
                    'uLabel' => $xAxisLabels[$columnIndex] ?? '',
                    'dLabel' => $yAxisLabelsUpwards[$rowIndex] ?? '',
                    'count' => (int) $rawCount,
                    'hasRaw' => $hasRawData,
                ];
            }
        }

        return $tiles;
    }

    /**
     * Build a legend color bar sampled uniformly across [0,1].
     *
     * @param  string  $paletteName  Palette id for HeatmapPalettes::colorAt().
     * @param  int  $stepCount  Number of segments to generate.
     *                          Magic: we include both endpoints, so we iterate 0..$stepCount (inclusive),
     *                          giving ($stepCount + 1) colors for a smooth gradient.
     * @return array<int, string> Colors (hex/rgba) arranged from high→low (reversed for UI orientation).
     */
    private function legendBar(string $paletteName, int $stepCount): array
    {

        static $cache = [];
        $key = $paletteName.'|'.$stepCount;

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $colorSteps = [];
        for ($i = 0; $i <= $stepCount; $i++) {
            $t = $i / max($stepCount, 1); // guard against 0; 0..1 inclusive
            $colorSteps[] = HeatmapPalettes::colorAt($paletteName, $t);
        }

        // UI convention: show "high" at the top; reversing aligns with vertical legends
        // where 0 is at the bottom and 1 at the top.
        return $cache[$key] = array_reverse($colorSteps);
    }

    /**
     * Generate legend tick marks with positions and display values.
     *
     * @param  float  $domainMin  Legend/domain minimum value.
     * @param  float  $domainMax  Legend/domain maximum value.
     * @param  int  $legendTickIntervals  Number of intervals (inclusive ticks → tickCount+1 ticks).
     *                                      Magic: We loop 0..$tickCount to include both ends.
     *                                      Note: At the moment, this will basically always be 6 labels/ticks.
     * @return array<int, array{pos: float, value: int}>
     *                                                   'pos' => percentage from top (0..100), for CSS positioning,
     *                                                   'value' => integer-rounded numeric value at the tick.
     */
    private function legendTicks(float $domainMin, float $domainMax, int $legendTickIntervals): array
    {
        $ticks = [];

        for ($i = 0; $i <= $legendTickIntervals; $i++) {
            // Fraction across the legend scale, 0..1 inclusive.
            $t = $i / max($legendTickIntervals, 1);

            // Position in % from TOP:
            // Magic: (1 - t) * 100 flips so that larger values are at the top of a
            // vertical legend (CSS top origin). If your legend grows bottom→top, keep this.
            $positionPercentFromTop = 100 * (1 - $t);

            // Interpolate the numeric value for the tick.
            $valueAtTick = $domainMin + $t * ($domainMax - $domainMin);

            $ticks[] = [
                'pos' => $positionPercentFromTop,
                'value' => (int) round($valueAtTick),
            ];
        }

        return $ticks;
    }

    /**
     * Build an "empty" panel placeholder with a small transparent grid and a default legend.
     *
     * @param  array  $cfg  Expected keys: tile, gap, tile_stroke_color, tile_stroke_width.
     * @return array<string, mixed>
     */
    private function emptyPanel(string $title, array $cfg): array
    {
        // Magic: 5x5 grid gives a small, unobtrusive placeholder while preserving layout.
        $placeholderBinCount = 5;

        $pitch = (int) ($cfg['tile'] + $cfg['gap']);

        $panelSize = $placeholderBinCount * $cfg['tile']
            + ($placeholderBinCount - 1) * $cfg['gap'];

        $tiles = [];
        for ($rowIndex = 0; $rowIndex < $placeholderBinCount; $rowIndex++) {
            for ($columnIndex = 0; $columnIndex < $placeholderBinCount; $columnIndex++) {
                $tiles[] = [
                    'x' => (int) ($columnIndex * ($pitch)),
                    'y' => (int) (($placeholderBinCount - 1 - $rowIndex) * ($pitch)),
                    'w' => (int) ($cfg['tile']),
                    'h' => (int) ($cfg['tile']),
                    'fill' => 'transparent',
                    'title' => 'no data',
                    'uLabel' => '',
                    'dLabel' => '',
                    'count' => 0,
                    'hasRaw' => false,
                ];
            }
        }

        return [
            'title' => $title,
            'xAxisTitle' => '',
            'yAxisTitle' => '',
            'xLabels' => [],
            'yLabels' => [],
            'xTickEvery' => 1,
            'yTickEvery' => 1,
            'width' => $panelSize,
            'height' => $panelSize,
            'gap' => $cfg['gap'],

            // Magic defaults chosen to match other panels’ look-and-feel:
            // - Palette 'viridis' with 48 steps (smooth enough for placeholders).
            // - Legend ticks from 0..6 with 6 intervals → 7 ticks.
            'tiles' => $tiles,
            'legendBar' => $this->legendBar('viridis', 48),
            'legendTicks' => $this->legendTicks(0, 6, 6),

            'tileStrokeColor' => $cfg['tile_stroke_color'],
            'tileStrokeWidth' => $cfg['tile_stroke_width'],
        ];
    }

    // -------- Math & helpers --------

    /**
     * Reservoir sampling (Algorithm R) push.
     *
     * Maintains a fixed-size uniform sample ("reservoir") from a stream of values
     * seen so far. For the k-th seen item (1-based), replace an existing item
     * with probability K/k.
     *
     * @param array<float> $reservoir Reservoir sample (modified in place).
     * @param float $newValue Incoming stream value.
     * @param int $itemsSeen Count of items observed so far (1-based).
     * @param int $sampleSize Reservoir capacity K.
     * @throws RandomException
     */
    private function reservoirPush(array &$reservoir, float $newValue, int $itemsSeen, int $sampleSize): void
    {
        $currentSize = count($reservoir);

        // Fill the reservoir until it reaches capacity.
        if ($currentSize < $sampleSize) {
            $reservoir[] = $newValue;

            return;
        }

        // Pick a 1-based random index in [1, itemsSeen].
        // Magic number explanation:
        // - random_int(1, itemsSeen) uses 1-based indexing to match the textbook
        //   Algorithm R derivation. We convert to 0-based only if we accept.
        $pickIndex1Based = random_int(1, $itemsSeen);

        // With probability sampleSize/itemsSeen, keep the new value by replacing
        // a uniformly chosen slot in the reservoir.
        if ($pickIndex1Based <= $sampleSize) {
            // Convert 1-based to 0-based index for the reservoir array.
            $reservoir[$pickIndex1Based - 1] = $newValue;
        }
    }

    /**
     * Interquartile range (IQR = Q3 - Q1) for a pre-sorted array.
     *
     * @param  array<float>  $sortedValues  Values sorted in non-decreasing order.
     */
    private function iqr(array $sortedValues): float
    {
        if (empty($sortedValues)) {
            return 0.0;
        }

        // Magic numbers:
        // - 0.25: first quartile (Q1)
        // - 0.75: third quartile (Q3)
        $q1 = $this->percentile($sortedValues, 0.25);
        $q3 = $this->percentile($sortedValues, 0.75);

        // Guard against tiny negative due to FP errors.
        return max(0.0, $q3 - $q1);
    }

    /**
     * Compute the p-th percentile of a sorted array using linear interpolation.
     *
     * @param  array<float>  $sortedValues  Values sorted in non-decreasing order.
     * @param  float  $percentile  Fraction in [0,1], e.g., 0.95 for the 95th percentile.
     */
    private function percentile(array $sortedValues, float $percentile): float
    {
        $valueCount = count($sortedValues);
        if ($valueCount === 0) {
            return 0.0;
        }

        // Clamp to [0,1] to avoid invalid ranks.
        $percentile = max(0.0, min(1.0, $percentile));

        // Use (N - 1) so that percentile=1.0 maps to the last element (0-indexed rank).
        $zeroIndexedRank = $percentile * ($valueCount - 1);

        $lowerIndex = (int) floor($zeroIndexedRank);
        $upperIndex = (int) ceil($zeroIndexedRank);

        // Exact index: no interpolation needed.
        if ($lowerIndex === $upperIndex) {
            return $sortedValues[$lowerIndex];
        }

        // Linear interpolation between the bracketing indices.
        $fractionalPart = $zeroIndexedRank - $lowerIndex;

        return (1 - $fractionalPart) * $sortedValues[$lowerIndex]
            + $fractionalPart * $sortedValues[$upperIndex];
    }

    /**
     * Median of an array. Sorts in-place.
     *
     * @param  array<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        // If odd, pick the middle element; if even, average the two middles.
        // Magic numbers:
        //  - 2: splitting even-sized arrays into two halves
        //  - 0.5: arithmetic mean of the two middle values
        if ($count % 2 === 1) {
            $middleIndex = intdiv($count, 2);

            return $values[$middleIndex];
        }

        $upperMiddleIndex = $count / 2;
        $lowerMiddleIndex = $upperMiddleIndex - 1;

        return 0.5 * ($values[$lowerMiddleIndex] + $values[$upperMiddleIndex]);
    }

    /**
     * Estimate an appropriate bin count using the Freedman–Diaconis rule.
     *
     * h = 2 * IQR * n^(-1/3)
     * bins ≈ range / h
     *
     * Falls back to Sturges' rule (ceil(log2(n) + 1)) when IQR is 0 or n <= 1.
     *
     * @param  float  $interquartileRange  IQR of the data (Q3 - Q1).
     * @param  float  $dataRange  Max - Min of the data.
     * @param  int  $sampleCount  Number of observations n.
     * @return int Recommended number of bins (>= 1).
     */
    private function freedmanDiaconisBins(float $interquartileRange, float $dataRange, int $sampleCount): int
    {
        // Fallback: Sturges' rule for small n or zero spread.
        // Magic numbers:
        //  - log base 2: Sturges uses log2(n)
        //  - +1: Sturges' offset
        if ($interquartileRange <= 0.0 || $sampleCount <= 1) {
            return (int) ceil(log(max($sampleCount, 1), 2) + 1);
        }

        // Freedman – Diaconis bin width: 2 * IQR * n^(-1/3)
        // Magic numbers:
        //  - 2.0: rule constant
        //  - 1/3: cube-root scaling on sample size
        $binWidth = 2.0 * $interquartileRange / pow($sampleCount, 1.0 / 3.0);

        if ($binWidth <= 0.0) {
            return (int) ceil(log(max($sampleCount, 1), 2) + 1); // Sturges fallback
        }

        // Convert width to count; ensure at least 1 bin.
        return max(1, (int) ceil($dataRange / $binWidth));
    }

    /**
     * Snap an integer target to the nearest value from a preferred set.
     *
     * @param  int  $targetValue  Value to snap.
     * @param  int[]  $preferredValues  Non-empty list of allowed "nice" values.
     */
    private function snapToNice(int $targetValue, array $preferredValues): int
    {
        // Assumes $preferredValues is non-empty; if not, the caller contract is broken.
        $bestMatch = $preferredValues[0];
        $smallestAbsDistance = abs($targetValue - $bestMatch);

        foreach ($preferredValues as $candidate) {
            $distance = abs($targetValue - $candidate);
            if ($distance < $smallestAbsDistance) {
                $bestMatch = $candidate;
                $smallestAbsDistance = $distance;
            }
        }

        return $bestMatch;
    }

    /**
     * Expand a numeric domain by a headroom factor and optional hard limits,
     * then snap to "nice" floor/ceil boundaries.
     *
     * @param  float  $observedMin  Observed minimum value.
     * @param  float  $observedMax  Observed maximum value.
     * @param  float|null  $explicitMin  Optional explicit lower bound.
     * @param  float|null  $explicitMax  Optional explicit upper bound.
     * @param  float  $headroomRatio  Extra padding ratio (e.g., 0.05 = 5%).
     * @return array{0: float, 1: float} [domainMin, domainMax]
     */
    private function domainWithHeadroom(
        float $observedMin,
        float $observedMax,
        ?float $explicitMin,
        ?float $explicitMax,
        float $headroomRatio
    ): array {
        // Apply headroom padding
        $paddedMin = $observedMin * (1 - $headroomRatio);
        $paddedMax = $observedMax * (1 + $headroomRatio);

        // Apply explicit hard limits if present
        if ($explicitMin !== null) {
            $paddedMin = min($paddedMin, $explicitMin);
        }
        if ($explicitMax !== null) {
            $paddedMax = max($paddedMax, $explicitMax);
        }

        // Estimate a "nice" step size for rounding
        $stepSize = $this->niceStep(max($paddedMax - $paddedMin, 1e-6), 10);

        // Snap min/max to nice floor/ceil boundaries
        $domainMin = max(0.0, $this->niceFloor($paddedMin, $stepSize));
        $domainMax = $this->niceCeil($paddedMax, $stepSize);

        // Ensure non-degenerate domain
        if ($domainMax <= $domainMin) {
            $domainMax = $domainMin + 1;
        }

        return [$domainMin, $domainMax];
    }

    /**
     * Generate a simple arithmetic sequence.
     *
     * @param  float  $startValue  First value in the sequence.
     * @param  float  $stepSize  Increment per step.
     * @param  int  $valueCount  Number of values to generate.
     * @return array<float> Sequence of evenly spaced values.
     */
    private function seq(float $startValue, float $stepSize, int $valueCount): array
    {
        $sequence = [];
        for ($i = 0; $i < $valueCount; $i++) {
            $sequence[] = $startValue + $i * $stepSize;
        }

        return $sequence;
    }

    /**
     * Generates a simple numeric range label by concatenating two values as integers.
     *
     * @param  float  $rangeStart  The starting value of the range.
     * @param  float  $rangeEnd  The ending value of the range.
     * @return string Label in the form "start–end".
     */
    private function rangeLabel(float $rangeStart, float $rangeEnd): string
    {
        return (int) $rangeStart.'–'.(int) $rangeEnd;
    }

    /**
     * Generates a time-of-day range label (HH:MM–HH:MM), wrapping at 24h.
     *
     * @param  float  $hourStart  Starting hour (e.g., 0.0 = 00:00).
     * @param  float  $hourEnd  Ending hour (can wrap past 24).
     * @return string Label in the form "HH:MM–HH:MM".
     */
    private function rangeLabelHour(float $hourStart, float $hourEnd): string
    {
        $formatHour = function (float $hourFraction): string {
            // Normalize into [0, 24)
            $normalizedHour = fmod($hourFraction + 24.0, 24.0);

            $hourComponent = (int) floor($normalizedHour);
            $minuteComponent = (int) round(($normalizedHour - $hourComponent) * 60);

            // Correct rollover (e.g., 59.9 minutes → 60)
            if ($minuteComponent === 60) {
                $hourComponent = ($hourComponent + 1) % 24;
                $minuteComponent = 0;
            }

            return str_pad((string) $hourComponent, 2, '0', STR_PAD_LEFT)
                .':'.
                str_pad((string) $minuteComponent, 2, '0', STR_PAD_LEFT);
        };

        return $formatHour($hourStart).'–'.$formatHour($hourEnd);
    }

    /**
     * Compute a "nice" step size for dividing a numeric span into bins.
     * Ensures human-friendly increments like 1, 2, 5, 10 × powers of 10.
     *
     * @param  float  $valueRange  Total numeric span to be divided.
     * @param  int  $desiredBinCount  Approximate number of bins desired.
     * @return float A rounded step size.
     */
    private function niceStep(float $valueRange, int $desiredBinCount): float
    {
        $safeRange = max($valueRange, 1e-6); // avoid div/0
        $rawStep = $safeRange / max($desiredBinCount, 1);

        $orderOfMagnitude = pow(10, floor(log10($rawStep)));
        $normalizedStep = $rawStep / $orderOfMagnitude;

        if ($normalizedStep <= 1.0) {
            $normalizedStep = 1.0;
        } elseif ($normalizedStep <= 2.0) {
            $normalizedStep = 2.0;
        } elseif ($normalizedStep <= 5.0) {
            $normalizedStep = 5.0;
        } else {
            $normalizedStep = 10.0;
        }

        return $normalizedStep * $orderOfMagnitude;
    }

    /**
     * Snap a numeric value downwards to the nearest multiple of the given step size.
     *
     * @param  float  $inputValue  The value to be snapped down.
     * @param  float  $stepSize  The granularity to snap to (must be > 0).
     * @return float The largest multiple of stepSize <= inputValue.
     */
    private function niceFloor(float $inputValue, float $stepSize): float
    {
        return floor($inputValue / max($stepSize, 1e-9)) * $stepSize;
    }

    /**
     * Snap a numeric value upwards to the nearest multiple of the given step size.
     *
     * @param  float  $inputValue  The value to be snapped up.
     * @param  float  $stepSize  The granularity to snap to (must be > 0).
     * @return float The smallest multiple of stepSize >= inputValue.
     */
    private function niceCeil(float $inputValue, float $stepSize): float
    {
        return ceil($inputValue / max($stepSize, 1e-9)) * $stepSize;
    }

    /**
     * Apply local averaging ("feathering") to a 2D histogram grid.
     *
     * Each cell is replaced by a weighted average of:
     *   - its own count (center),
     *   - the 4 orthogonal neighbors (up/down/left/right),
     *   - optionally the 4 diagonal neighbors.
     *
     * Weights are normalized per cell, so the result stays on the original scale.
     *
     * @param  array  $binCountsGrid  2D array [row][col] of raw bin counts.
     * @param  float  $centerWeight  Weight applied to the cell itself.
     * @param  float  $orthWeight  Weight applied to each orthogonal neighbor.
     * @param  float  $diagWeight  Weight applied to each diagonal neighbor (0 to disable).
     * @return array 2D array [row][col] of feathered bin densities.
     */
    private function adjacentFeather(
        array $binCountsGrid,
        float $centerWeight,
        float $orthWeight,
        float $diagWeight = 0.0
    ): array {
        $rowCount = count($binCountsGrid);
        $colCount = count($binCountsGrid[0] ?? []);

        // Pre-size the output grid
        $featheredGrid = array_fill(0, $rowCount, array_fill(0, $colCount, 0.0));

        // Neighbor coordinate offsets (constant throughout the loop)
        $orthogonalOffsets = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        $diagonalOffsets = [[-1, -1], [-1, 1], [1, -1], [1, 1]];

        for ($row = 0; $row < $rowCount; $row++) {
            for ($col = 0; $col < $colCount; $col++) {
                // Start with the center cell
                $weightedSum = $binCountsGrid[$row][$col] * $centerWeight;
                $weightTotal = $centerWeight;

                // Orthogonal neighbors
                [$orthSum, $orthWeightTotal] = $this->accumulateNeighborWeights(
                    $binCountsGrid, $row, $col,
                    $orthogonalOffsets, $orthWeight,
                    $rowCount, $colCount
                );
                $weightedSum += $orthSum;
                $weightTotal += $orthWeightTotal;

                // Diagonals (optional)
                if ($diagWeight > 0.0) {
                    [$diagSum, $diagWeightTotal] = $this->accumulateNeighborWeights(
                        $binCountsGrid, $row, $col,
                        $diagonalOffsets, $diagWeight,
                        $rowCount, $colCount
                    );
                    $weightedSum += $diagSum;
                    $weightTotal += $diagWeightTotal;
                }

                // Normalize by total applied weight for this location
                $featheredGrid[$row][$col] = $weightTotal > 0.0 ? $weightedSum / $weightTotal : 0.0;
            }
        }

        return $featheredGrid;
    }

    /**
     * Safely add contributions from neighbors with a given offset set and weight.
     *
     * @param  array  $binCountsGrid  2D histogram grid
     * @param  int  $rowIndex  Current row index
     * @param  int  $colIndex  Current column index
     * @param  array  $offsets  List of [rowOffset, colOffset] neighbor offsets
     * @param  float  $neighborWeight  Weight applied to each neighbor
     * @param  int  $rowCount  Number of rows in the grid
     * @param  int  $colCount  Number of columns in the grid
     * @return array{0: float, 1: float} [contributionSum, contributionWeight]
     */
    private function accumulateNeighborWeights(
        array $binCountsGrid,
        int $rowIndex,
        int $colIndex,
        array $offsets,
        float $neighborWeight,
        int $rowCount,
        int $colCount
    ): array {
        $contributionSum = 0.0;
        $contributionWeight = 0.0;

        foreach ($offsets as [$rowOffset, $colOffset]) {
            $neighborRow = $rowIndex + $rowOffset;
            $neighborCol = $colIndex + $colOffset;

            $isInsideGrid =
                $neighborRow >= 0 && $neighborRow < $rowCount &&
                $neighborCol >= 0 && $neighborCol < $colCount;

            if ($isInsideGrid) {
                $contributionSum += $binCountsGrid[$neighborRow][$neighborCol] * $neighborWeight;
                $contributionWeight += $neighborWeight;
            }
        }

        return [$contributionSum, $contributionWeight];
    }

    /**
     * Determine the clamping range [min, max] for the legend scale
     * based on density values and the chosen scaling mode.
     *
     * @param  array  $densityGrid  2D array of bin densities (after feathering).
     * @param  string  $scaleMode  One of: 'max', 'p95', 'p99', or 'auto' (default).
     * @return array{0: float, 1: float} [clampMin, clampMax]
     */
    private function legendClamp(array $densityGrid, string $scaleMode): array
    {
        // Flatten non-zero values for statistics
        $nonZeroValues = [];
        foreach ($densityGrid as $row) {
            foreach ($row as $density) {
                if ($density > 0) {
                    $nonZeroValues[] = $density;
                }
            }
        }

        // Early exit
        if (empty($nonZeroValues)) {
            return [0.0, 1.0];
        }

        sort($nonZeroValues);
        $valueCount = count($nonZeroValues);

        // Common statistics
        $maxValue = $valueCount ? $nonZeroValues[$valueCount - 1] : 1.0;
        $p95Value = $valueCount ? $nonZeroValues[(int) floor(0.95 * ($valueCount - 1))] : 1.0;
        $p99Value = $valueCount ? $nonZeroValues[(int) floor(0.99 * ($valueCount - 1))] : $p95Value;

        // Select upper bound depending on mode
        switch ($scaleMode) {
            case 'max':
                $legendMax = $maxValue;
                break;
            case 'p95':
                $legendMax = max(1.0, $p95Value);
                break;
            case 'p99':
                $legendMax = max(1.0, $p99Value);
                break;
            default: // 'auto'
                $legendMax = max(1.0, $p99Value);
                // If there's an extreme outlier, allow expansion
                if ($maxValue > 3 * $legendMax) {
                    $legendMax = $maxValue;
                }
                break;
        }

        $legendMin = 0.0;
        $legendStep = $this->niceStep($legendMax, 8);
        $legendMax = $this->niceCeil($legendMax, $legendStep);

        return [$legendMin, $legendMax];
    }

    /**
     * Normalize a raw bin value into [0, 1] for color lookup.
     *
     * @param  float  $value  Raw (possibly feathered) bin value to normalize.
     * @param  float  $domainMin  Lower bound of the legend/domain (inclusive).
     * @param  float  $domainMax  Upper bound of the legend/domain (inclusive).
     * @param  string  $scaleMode  One of: 'linear' (default), 'log', 'sqrt'.
     * @return float Normalized value in [0, 1].
     */
    private function scaleValue(float $value, float $domainMin, float $domainMax, string $scaleMode): float
    {
        // Clamp to the display domain so extreme values don't distort the mapping.
        $clampedValue = max($domainMin, min($domainMax, $value));

        // Guard against zero/negative spans.
        $domainSpan = max($domainMax - $domainMin, 1e-9);

        switch ($scaleMode) {
            case 'log':
                // log(1+x) keeps zero non-negative and compresses the tail.
                $logDenominator = max(log(1 + $domainMax), 1e-9);
                $normalized = log(1 + $clampedValue) / $logDenominator;
                break;

            case 'sqrt':
                // Square-root scaling emphasizes smaller values but keeps ordering.
                $sqrtDenominator = max($domainMax, 1e-9);
                $normalized = sqrt($clampedValue / $sqrtDenominator);
                break;

            default: // 'linear'
                $normalized = ($clampedValue - $domainMin) / $domainSpan;
                break;
        }

        // Final guard to ensure we always return a valid unit interval.
        return max(0.0, min(1.0, $normalized));
    }
}
