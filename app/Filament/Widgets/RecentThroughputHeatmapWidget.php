<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ResultStatus;
use App\Helpers\HeatmapPalettes;
use App\Services\Heatmap\HeatmapService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class RecentThroughputHeatmapWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-throughput-heatmap';
    protected static ?string $heading = 'Network Heatmaps';

    protected int|string|array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';
    protected static bool $isLazy = false;

    /** UI State */
    public string $palette = 'viridis';

    /** Visual defaults (kept here so UI concerns stay in the widget) */
    private const TILE = 16;
    private const GAP  = -1;

    private const LEGEND_STEPS      = 48;
    private const TILE_STROKE_COLOR = 'rgba(0,0,0,0.25)';
    private const TILE_STROKE_WIDTH = 0.5;

    /** High-level data/scaling options (still configurable from the widget) */
    private const BINS_MIN     = 24;
    private const BINS_MAX     = 48;
    private const BINS_NICE    = [24, 28, 32, 36, 40, 44, 48];
    private const AXIS_HEADROOM = 0.08;

    private const FEATHER_CENTER   = 0.8;
    private const FEATHER_NEIGHBOR = 0.60;
    private const FEATHER_DIAGONAL = 0.40;

    private const COLOR_EMPTY_CELLS = true;           // keep current look
    private const COLOR_SCALE_MODE  = 'adaptive';     // 'p95'|'p99'|'max'|'adaptive'
    private const VALUE_SCALE       = 'sqrt';         // 'linear'|'log'|'sqrt'

    /** Optional axis pinning; keep null to auto-fit */
    protected ?float $axisMaxUpload   = null;
    protected ?float $axisMaxDownload = null;
    protected ?float $axisMinUpload   = null;
    protected ?float $axisMinDownload = null;

    public function mount(): void
    {
        $options = $this->paletteOptions();
        $default = array_key_first($options) ?? 'viridis';
        $chosen  = (string) session('heatmap_palette', $this->palette ?: $default);
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
        /** Bundle widget-config into a compact options array for the service */
        $opts = [
            'tile'             => self::TILE,
            'gap'              => self::GAP,
            'bins_min'         => self::BINS_MIN,
            'bins_max'         => self::BINS_MAX,
            'bins_nice'        => self::BINS_NICE,
            'axis_headroom'    => self::AXIS_HEADROOM,
            'feather'          => [self::FEATHER_CENTER, self::FEATHER_NEIGHBOR, self::FEATHER_DIAGONAL],
            'color_empty'      => self::COLOR_EMPTY_CELLS,
            'color_scale_mode' => self::COLOR_SCALE_MODE,
            'value_scale'      => self::VALUE_SCALE,
            'legend_steps'     => self::LEGEND_STEPS,
            'tile_stroke'      => [self::TILE_STROKE_COLOR, self::TILE_STROKE_WIDTH],
            'axis_overrides'   => [
                'min_up' => $this->axisMinUpload,
                'max_up' => $this->axisMaxUpload,
                'min_dn' => $this->axisMinDownload,
                'max_dn' => $this->axisMaxDownload,
            ],
        ];

        $cacheKey = sprintf(
            'heatmap:composite:v11:%s:%s',
            $this->palette,
            md5(json_encode($opts))
        );

        /** Keep caching outside the service so the widget controls TTL & keying strategy */
        $panels = Cache::remember($cacheKey, 10, function () use ($opts) {
            /** @var HeatmapService $svc */
            $svc = app(HeatmapService::class);
            return $svc->computePanels(
                palette: $this->palette,
                options: $opts,
                timezone: config('app.timezone', 'UTC'),
            );
        });

        return [
            'heading'         => static::$heading,
            'palettes'        => $this->paletteOptions(),
            'selectedPalette' => $this->palette,
            'panels'          => $panels,
        ];
    }
}
