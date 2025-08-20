@php
    $color = method_exists($this, 'getColor') ? $this->getColor() : 'gray';
    $heading = method_exists($this, 'getHeading') ? $this->getHeading() : 'Network Heatmaps';
    $description = method_exists($this, 'getDescription') ? $this->getDescription() : null;

    /** @var array<int,array> $panels */
    $panels = $panels ?? [];

    // Palette data from the widget (with safe fallbacks if not passed)
    $palettes = $palettes
        ?? (method_exists($this, 'paletteOptions') ? $this->paletteOptions() : []);
    $selectedPalette = $selectedPalette
        ?? (property_exists($this, 'palette') ? $this->palette : 'pinkblue');
@endphp

<x-filament-widgets::widget class="fi-wi-chart">

    <x-filament::section :description="$description">
        <x-slot name="heading">
            {{ $heading }}
        </x-slot>

<x-slot name="headerEnd">
    <x-filament::input.wrapper class="w-max sm:-my-2" wire:key="heatmap-palette-select-wrp">

        <select
            wire:model.live="palette"
            aria-label="Palette"
            class="fi-select-input block w-full border-none bg-transparent ps-2 py-1.5 pe-8 text-base text-gray-950 transition duration-75 focus:ring-0 disabled:text-gray-500 disabled:[-webkit-text-fill-color:theme(colors.gray.500)] dark:text-white dark:disabled:text-gray-400 dark:disabled:[-webkit-text-fill-color:theme(colors.gray.400)] sm:text-sm sm:leading-6 [&_optgroup]:bg-white [&_optgroup]:dark:bg-gray-900 [&_option]:bg-white [&_option]:dark:bg-gray-900"
        >
            @foreach ($palettes as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </x-filament::input.wrapper>
</x-slot>

        <div
            @if (method_exists($this, 'getPollingInterval') && ($pollingInterval = $this->getPollingInterval()))
                wire:poll.{{ $pollingInterval }}
            @endif
        >

            <div class="w-full" wire:key="throughput-heatmaps-all">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

                    @foreach ($panels as $i => $panel)
                        @php
                            $width   = $panel['width']  ?? 300;
                            $height  = $panel['height'] ?? 300;
                            $tiles   = $panel['tiles']  ?? [];
                            $legendBar   = $panel['legendBar']   ?? [];
                            $legendTicks = $panel['legendTicks'] ?? [];
                            $tileStrokeColor = $panel['tileStrokeColor'] ?? 'rgba(0,0,0,0.2)';
                            $tileStrokeWidth = $panel['tileStrokeWidth'] ?? 0.5;

                            $xAxisTitle = trim(($panel['xAxisTitle'] ?? ''));
                            $yAxisTitle = trim(($panel['yAxisTitle'] ?? ''));
                            $xLabels    = $panel['xLabels'] ?? [];
                            $yLabels    = $panel['yLabels'] ?? [];
                        @endphp

                        <div class="space-y-3" wire:key="throughput-heatmap-{{ $i }}">
                            <div class="flex items-center justify-between">
                                <div class="text-sm font-medium">
                                    {{ $panel['title'] ?? 'Heatmap' }}
                                </div>

                            </div>

                            <div class="grid items-stretch gap-x-4 sm:gap-x-6" style="grid-template-columns: auto 1fr auto;">

                                <div class="w-6 sm:w-8 md:w-10 shrink-0 flex items-center justify-center">
                                    <span class="vertical-text text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        {{ $yAxisTitle }}
                                    </span>
                                </div>

                                <div class="min-w-0">
                                    <div x-data="heatmapTooltip(chartJsTooltipOpts)" x-ref="wrap" class="relative w-full"
                                         style="aspect-ratio: {{ $width }} / {{ $height }};"
                                         @mouseleave="leave()">
                                        <svg xmlns="http://www.w3.org/2000/svg"
                                             viewBox="0 0 {{ $width }} {{ $height }}"
                                             preserveAspectRatio="none"
                                             class="h-full w-full rounded-lg"
                                             @mousemove="move($event)">
                                            <rect x="0" y="0" width="{{ $width }}" height="{{ $height }}" fill="none" />
                                            @foreach ($tiles as $t)
                                                <rect
                                                    x="{{ $t['x'] }}" y="{{ $t['y'] }}"
                                                    width="{{ $t['w'] }}" height="{{ $t['h'] }}"
                                                    fill="{{ $t['fill'] }}" rx="2" ry="2"
                                                    stroke="{{ $tileStrokeColor }}"
                                                    stroke-width="{{ $tileStrokeWidth }}"
                                                    data-u="{{ $t['uLabel'] ?? '' }}"
                                                    data-d="{{ $t['dLabel'] ?? '' }}"
                                                    data-count="{{ (int)($t['count'] ?? 0) }}"
                                                    data-color="{{ $t['fill'] ?? '' }}"
                                                    data-has-raw="{{ !empty($t['hasRaw']) ? 1 : 0 }}"

                                                    @mouseenter="enter($event)"
                                                />
                                            @endforeach
                                        </svg>

                                        <div x-cloak x-show="opts.enabled && show" x-ref="tip"
                                             :data-caret="caretSide"
                                             :style="tipStyle"
                                             class="hm-tip pointer-events-none absolute z-20 min-w-[180px] shadow-lg">

                                            <template x-if="titleText">
                                                <div class="hm-title" x-text="titleText"></div>
                                            </template>

                                            <div class="hm-body">
                                                <div class="hm-row">
                                                    <template x-if="opts.displayColors">
                                                        <span class="hm-box" :style="boxStyle('#38bdf8')"></span>
                                                    </template>
                                                    <span class="hm-label">
                                                        <span class="hm-dim">{{ $yAxisTitle }}:</span> <span x-text="info?.d"></span>
                                                    </span>
                                                </div>
                                                <div class="hm-row">
                                                    <template x-if="opts.displayColors">
                                                        <span class="hm-box" :style="boxStyle('#ef4444')"></span>
                                                    </template>
                                                    <span class="hm-label">
                                                        <span class="hm-dim">{{ $xAxisTitle }}:</span> <span x-text="info?.u"></span>
                                                    </span>
                                                </div>
                                            </div>

                                            <template x-if="footerText">
                                                <div class="hm-footer" x-text="footerText"></div>
                                            </template>
                                            <template x-if="info && !info.hasRaw">
                                                <div class="italic opacity-70">No data</div>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="mt-3 text-center">
                                        <span class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">
                                            {{ $xAxisTitle }}
                                        </span>
                                    </div>
                                </div>

                                <div class="w-16 sm:w-20 shrink-0 relative hm-legend">
                                    <div class="h-full relative" style="height: 85%;">
                                        <div class="absolute inset-y-0 left-0 w-4 sm:w-5 rounded-md overflow-hidden hm-legend-bar">
                                            <div class="flex h-full w-full flex-col">
                                                @foreach ($legendBar as $color)
                                                    <span style="background: {{ $color }}; height: {{ 100 / max(count($legendBar),1) }}%;"></span>
                                                @endforeach
                                            </div>
                                        </div>

                                        @foreach ($legendTicks as $tick)
                                            <div class="absolute flex items-center gap-2"
                                                 style="left: calc(1.25rem + 4px); top: {{ $tick['pos'] }}%; transform: translateY(-50%);">
                                                <span class="hm-legend-line"></span>
                                                <span class="hm-legend-label">{{ $tick['value'] }}</span>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-3 text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400 text-center">
                                        Frequency
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach

                </div>
            </div>
        </div>
        <style>
            .vertical-text{ writing-mode: vertical-rl; text-orientation: mixed; }
            [x-cloak]{ display:none !important; }

            .hm-tip{
                font-size: var(--font-size, 12px);
                font-family: var(--ff, 'Helvetica Neue', 'Helvetica', 'Arial', sans-serif);
                line-height: 1.2;
                --bg: rgba(0,0,0,0.8);
                --title-color: #fff;
                --body-color: #fff;
                --footer-color: #fff;
                --border-color: rgba(0,0,0,0);
                --border-width: 0px;
                --pad: 6px;
                --corner: 6px;
                --title-mb: 6px;
                --body-gap: 2px;
                --footer-mt: 6px;
                --caret-size: 5px;
                --caret-pad: 2px;
                --caret-x: 12px;
                background: var(--bg);
                color: var(--body-color);
                padding: var(--pad);
                border: var(--border-width) solid var(--border-color);
                border-radius: var(--corner);
            }
            .hm-tip::after{
                content: "";
                position: absolute;
                width: 0; height: 0;
                border: var(--caret-size) solid transparent;
            }
            .hm-tip[data-caret="top"]::after{
                bottom: calc(100% - var(--caret-pad));
                left: var(--caret-x);
                border-bottom-color: var(--bg);
            }
            .hm-tip[data-caret="bottom"]::after{
                top: calc(100% - var(--caret-pad));
                left: var(--caret-x);
                border-top-color: var(--bg);
            }
            .hm-title{ color: var(--title-color); font-weight: 700; margin-bottom: var(--title-mb); }
            .hm-body{ display: grid; gap: var(--body-gap); }
            .hm-row{ display: flex; align-items: center; }
            .hm-box{ display:inline-block; border-radius: 2px; margin-right: 4px; }
            .hm-label{ color: var(--body-color); }
            .hm-dim{ opacity: .7; }
            .hm-footer{ color: var(--footer-color); font-weight: 700; margin-top: var(--footer-mt); }

            .hm-legend-bar{ border: 0 solid rgba(0,0,0,0); border-radius: 6px; }
            .hm-legend-line{ width: 8px; height: 1px; background-color: rgba(255,255,255,.5); display:inline-block; }
            .hm-legend-label{ color: #fff; font-size: 11px; }
        </style>

        <script>
            window.chartJsTooltipOpts = {
                enabled: true,
                external: null,
                mode: 'nearest',
                intersect: true,
                position: 'average',

                backgroundColor: 'rgba(0,0,0,0.8)',
                titleColor: '#fff',
                titleFont: { weight: 'bold' },
                titleAlign: 'left',
                titleSpacing: 2,
                titleMarginBottom: 6,

                bodyColor: '#fff',
                bodyFont: { size:12 },
                bodyAlign: 'left',
                bodySpacing: 2,

                footerColor: '#fff',
                footerFont: { weight: 'bold' },
                footerAlign: 'left',
                footerSpacing: 2,
                footerMarginTop: 6,

                padding: 6,
                caretPadding: 2,
                caretSize: 5,
                cornerRadius: 6,

                multiKeyBackground: '#fff',
                displayColors: true,
                boxWidth: 12,
                boxHeight: 12,
                boxPadding: 1,
                usePointStyle: false,

                borderColor: 'rgba(0,0,0,0)',
                borderWidth: 0,

                rtl: false,
                textDirection: 'ltr',
                xAlign: undefined,
                yAlign: undefined,

                callbacks: {
                    title: (ctx) => 'Throughput bin',
                    footer: (ctx) => {
                        const raw = ctx?.[0]?.raw;
                        return raw && typeof raw.count !== 'undefined'
                            ? `Frequency: ${raw.count}`
                            : '';
                    },
                },
            };

            document.addEventListener('alpine:init', () => {
                Alpine.data('heatmapTooltip', (opts) => ({
                    opts,
                    show: false,
                    x: 0, y: 0,
                    info: null,
                    caretSide: 'top',

                    enter(e){
                        const el = e.currentTarget;
                        this.info = {
                            u: el.dataset.u || '',
                            d: el.dataset.d || '',
                            count: Number(el.dataset.count || 0),
                            color: el.dataset.color || '#fff',
                            hasRaw: el.dataset.hasRaw === '1' || el.dataset.hasRaw === 'true',
                        };
                        this.show = true;
                    },
                    leave(){ this.show = false; },
                    move(e){
                        const r = this.$refs.wrap.getBoundingClientRect();
                        this.x = e.clientX - r.left;
                        this.y = e.clientY - r.top;
                    },

                    get titleText(){
                        return this.opts.callbacks?.title?.([{ raw: this.info }]) ?? 'Throughput bin';
                    },
                    get footerText(){
                        return this.opts.callbacks?.footer?.([{ raw: this.info }]) ?? '';
                    },

                    get tipStyle(){
                        const o = this.opts;
                        const fs = Number(o.bodyFont?.size ?? 12);
                        const wrap = this.$refs.wrap.getBoundingClientRect();
                        const tip  = this.$refs.tip;
                        const tw = tip ? tip.offsetWidth : 180;
                        const th = tip ? tip.offsetHeight : 80;

                        const pad = Number(o.caretPadding ?? 2);
                        const caret = Number(o.caretSize ?? 5);

                        let left = this.x + pad + caret;
                        let top  = this.y + pad + caret;
                        this.caretSide = 'top';

                        if (left + tw > wrap.width) left = this.x - tw - pad - caret;
                        if (top  + th > wrap.height) { top = this.y - th - pad - caret; this.caretSide = 'bottom'; }

                        left = Math.max(0, left);
                        top  = Math.max(0, top);

                        return `
                            left:${left}px; top:${top}px;
                            --fs:${fs}px;
                            --bg:${o.backgroundColor};
                            --title-color:${o.titleColor};
                            --body-color:${o.bodyColor};
                            --footer-color:${o.footerColor};
                            --border-color:${o.borderColor};
                            --border-width:${o.borderWidth}px;
                            --pad:${o.padding}px;
                            --corner:${o.cornerRadius}px;
                            --title-mb:${o.titleMarginBottom}px;
                            --body-gap:${o.bodySpacing}px;
                            --footer-mt:${o.footerMarginTop}px;
                            --caret-size:${o.caretSize}px;
                            --caret-pad:${o.caretPadding}px;
                            --caret-x:${(o.padding ?? 6) + (o.boxWidth ?? fs)}px;
                        `;
                    },

                    boxStyle(color){
                        const o = this.opts;
                        const fs = Number(o.bodyFont?.size ?? 12);
                        const w = o.boxWidth ?? fs;
                        const h = o.boxHeight ?? fs;
                        const p = o.boxPadding ?? 1;
                        return `background:${color}; width:${w}px; height:${h}px; margin-right:${p * 4}px;`;
                    },
                }));
            });
        </script>
    </x-filament::section>
</x-filament-widgets::widget>
