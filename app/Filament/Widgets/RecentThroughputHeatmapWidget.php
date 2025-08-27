<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Helpers\HeatmapPalettes;
use App\Services\Heatmap\HeatmapConfig;
use App\Services\Heatmap\HeatmapService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class RecentThroughputHeatmapWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-throughput-heatmap';

    protected static ?string $heading = 'Network Heatmaps';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** UI state */
    public string $palette = 'viridis';

    public function mount(): void
    {
        $options = $this->paletteOptions();
        $default = HeatmapConfig::defaultPalette();

        if (HeatmapConfig::rememberPaletteInSession()) {
            $chosen = (string) session('heatmap_palette', $this->palette ?: $default);
            $this->palette = isset($options[$chosen]) ? $chosen : $default;
        } else {
            $this->palette = isset($options[$default]) ? $default : array_key_first($options);
        }
    }

    public function updatedPalette($val): void
    {
        $options = $this->paletteOptions();
        $default = HeatmapConfig::defaultPalette();
        $this->palette = isset($options[$val]) ? (string) $val : $default;

        if (HeatmapConfig::rememberPaletteInSession()) {
            session(['heatmap_palette' => $this->palette]);
        }
    }

    public function getPollingInterval(): ?string
    {
        return HeatmapConfig::pollingInterval();
    }

    public function paletteOptions(): array
    {
        return HeatmapPalettes::options();
    }

    protected function getViewData(): array
    {
        $cfgHash = md5(json_encode(\App\Services\Heatmap\HeatmapConfig::forCompute()));
        $cacheKey = sprintf('heatmap:composite:v14:%s:%s', $this->palette, $cfgHash);

        $panels = Cache::remember($cacheKey, HeatmapConfig::cacheTtl(), function () {
            /** @var HeatmapService $svc */
            $svc = app(HeatmapService::class);

            return $svc->computePanels(
                palette: $this->palette,
                timezone: config('app.timezone', 'UTC'),
            );
        });

        return [
            'heading' => static::$heading,
            'palettes' => $this->paletteOptions(),
            'selectedPalette' => $this->palette,
            'panels' => $panels,
        ];
    }
}
