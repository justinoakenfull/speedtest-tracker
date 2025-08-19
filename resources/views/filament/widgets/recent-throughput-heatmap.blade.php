@php
    $color = method_exists($this, 'getColor') ? $this->getColor() : 'gray';
    $heading = method_exists($this, 'getHeading') ? $this->getHeading() : 'Throughput Heatmap (density)';
    $description = method_exists($this, 'getDescription') ? $this->getDescription() : null;

    $cols = count($xLabels ?? []);
    $rows = count($yLabels ?? []);
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <div
            @if (method_exists($this, 'getPollingInterval') && ($pollingInterval = $this->getPollingInterval()))
                wire:poll.{{ $pollingInterval }}
            @endif
        >
            {{-- Heatmap + legend (3 columns: Y label | heatmap | legend) --}}
            <div class="w-full" wire:key="throughput-heatmap-all">
                <div class="grid items-stretch gap-x-4 sm:gap-x-6" style="grid-template-columns: auto 1fr auto;">

                    {{-- Y-axis title column (stretches to the heatmap height) --}}
                    <div class="w-6 sm:w-8 md:w-10 shrink-0 flex items-center justify-center">
                        <span class="vertical-text text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">
                            Download (Mbps) ↑
                        </span>
                    </div>

                    {{-- Heatmap (sets row height via aspect-ratio) --}}
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
                                        stroke="{{ $tileStrokeColor ?? 'rgba(0,0,0,0.2)' }}"
                                        stroke-width="{{ $tileStrokeWidth ?? 0.5 }}"

                                        {{-- tooltip payload via dataset (avoids Blade quoting issues) --}}
                                        data-u="{{ $t['uLabel'] ?? '' }}"
                                        data-d="{{ $t['dLabel'] ?? '' }}"
                                        data-count="{{ (int)($t['count'] ?? 0) }}"
                                        data-color="{{ $t['fill'] ?? '' }}"
                                        data-has-data="{{ !empty($t['hasData']) ? 1 : 0 }}"

                                        @mouseenter="enter($event)"
                                    />
                                @endforeach
                            </svg>

                            {{-- Chart.js-like tooltip --}}
                            <div x-cloak x-show="opts.enabled && show" x-ref="tip"
                                 :data-caret="caretSide"
                                 :style="tipStyle"
                                 class="hm-tip pointer-events-none absolute z-20 min-w-[180px] shadow-lg">

                                {{-- title --}}
                                <template x-if="titleText">
                                    <div class="hm-title" x-text="titleText"></div>
                                </template>

                                {{-- body (Download/Upload with color boxes) --}}
                                <div class="hm-body">
                                    <div class="hm-row">
                                        <template x-if="opts.displayColors">
                                            <span class="hm-box" :style="boxStyle('#38bdf8')"></span> {{-- sky-400 --}}
                                        </template>
                                        <span class="hm-label"><span class="hm-dim">Download:</span> <span x-text="info?.d"></span> Mbps</span>
                                    </div>
                                    <div class="hm-row">
                                        <template x-if="opts.displayColors">
                                            <span class="hm-box" :style="boxStyle('#ef4444')"></span> {{-- red-500 --}}
                                        </template>
                                        <span class="hm-label"><span class="hm-dim">Upload:</span> <span x-text="info?.u"></span> Mbps</span>
                                    </div>
                                </div>

                                {{-- footer --}}
                                <template x-if="footerText">
                                    <div class="hm-footer" x-text="footerText"></div>
                                </template>

                                {{-- no-data note --}}
                                <template x-if="info && !info.hasData">
                                    <div class="italic opacity-70">No data</div>
                                </template>
                            </div>
                        </div>

                        <div class="mt-3 text-center">
                            <span class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">
                                Upload (Mbps) →
                            </span>
                        </div>
                    </div>

                    {{-- Vertical legend (visuals aligned with Chart.js defaults) --}}
                    <div class="w-16 sm:w-20 shrink-0 relative hm-legend">
                        <div class="h-full relative" style="height: 85%;">
                            {{-- gradient bar --}}
                            <div class="absolute inset-y-0 left-0 w-4 sm:w-5 rounded-md overflow-hidden hm-legend-bar">
                                <div class="flex h-full w-full flex-col">
                                    @foreach ($legendBar as $color)
                                        <span style="background: {{ $color }}; height: {{ 100 / max(count($legendBar),1) }}%;"></span>
                                    @endforeach
                                </div>
                            </div>

                            {{-- tick marks / labels --}}
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
        </div>

        {{-- Styles: tooltip & legend (Chart.js defaults) --}}
        <style>
            .vertical-text{ writing-mode: vertical-rl; text-orientation: mixed; }
            [x-cloak]{ display:none !important; }

            /* Tooltip driven by CSS variables from JS to mirror Chart.js options */
            .hm-tip{
                font-size: var(--font-size, 12px); /* default 12px */
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
                --caret-x: 12px; /* where the caret meets the box */
                background: var(--bg);
                color: var(--body-color);
                padding: var(--pad);
                border: var(--border-width) solid var(--border-color);
                border-radius: var(--corner);
            }
            .hm-tip::after{
                /* caret arrow */
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

            /* Legend visuals akin to Chart.js */
            .hm-legend-bar{ border: 0 solid rgba(0,0,0,0); border-radius: 6px; }
            .hm-legend-line{ width: 8px; height: 1px; background-color: rgba(255,255,255,.5); display:inline-block; }
            .hm-legend-label{ color: #fff; font-size: 11px; }
        </style>

        {{-- Chart.js-like tooltip options + Alpine controller --}}
        <script>
            // Options object using Chart.js tooltip property names
            window.chartJsTooltipOpts = {
            enabled: true,
            external: null,
            mode: 'nearest',
            intersect: true,
            position: 'average',

            backgroundColor: 'rgba(0,0,0,0.8)',
            titleColor: '#fff',
            titleFont: { weight: 'bold' }, // family/size/style inherit global (12px normal)
            titleAlign: 'left',
            titleSpacing: 2,
            titleMarginBottom: 6,

            bodyColor: '#fff',
            bodyFont: { size:12 },                  // inherits global: 12px normal
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
            boxWidth: 12,                  // Chart.js uses bodyFont.size
            boxHeight: 12,                 // "
            boxPadding: 1,
            usePointStyle: false,

            borderColor: 'rgba(0,0,0,0)',
            borderWidth: 0,

            rtl: false,                    // Chart.js inherits canvas; leaving false is fine
            textDirection: 'ltr',          // (optional) omit to inherit canvas default
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
                            hasData: el.dataset.hasData === '1' || el.dataset.hasData === 'true',
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

                    // Compute style from Chart.js-like options
                    get tipStyle(){
                        const o = this.opts;
                        const fs = Number(o.bodyFont?.size ?? 12);
                        const wrap = this.$refs.wrap.getBoundingClientRect();
                        const tip  = this.$refs.tip;
                        const tw = tip ? tip.offsetWidth : 180;
                        const th = tip ? tip.offsetHeight : 80;

                        const pad = Number(o.caretPadding ?? 2);
                        const caret = Number(o.caretSize ?? 5);

                        // base position (like 'average')
                        let left = this.x + pad + caret;
                        let top  = this.y + pad + caret;
                        this.caretSide = 'top';

                        // overflow handling (flip if needed)
                        if (left + tw > wrap.width) left = this.x - tw - pad - caret;
                        if (top  + th > wrap.height) { top = this.y - th - pad - caret; this.caretSide = 'bottom'; }

                        left = Math.max(0, left);
                        top  = Math.max(0, top);

                        // map options to CSS vars
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
