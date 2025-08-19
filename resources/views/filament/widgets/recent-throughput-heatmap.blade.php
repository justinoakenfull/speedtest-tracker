@php
    $color = method_exists($this, 'getColor') ? $this->getColor() : 'gray';
    $heading = method_exists($this, 'getHeading') ? $this->getHeading() : 'Throughput Heatmap (density)';
    $description = method_exists($this, 'getDescription') ? $this->getDescription() : null;
@endphp

<x-filament-widgets::widget class="fi-wi-chart">
    <x-filament::section :description="$description" :heading="$heading">
        <div
            @if (method_exists($this, 'getPollingInterval') && ($pollingInterval = $this->getPollingInterval()))
                wire:poll.{{ $pollingInterval }}
            @endif
        >
            @php
                $cols = count($xLabels ?? []);
                $rows = count($yLabels ?? []);
                $gapXPercent = $width > 0 ? ($gap / $width) * 100 : 0;
                $gapYPercent = $height > 0 ? ($gap / $height) * 100 : 0;
            @endphp

            {{-- Heatmap + legend (3 columns: Y label | heatmap | legend) --}}
            <div class="w-full" wire:key="throughput-heatmap-all">
                <div class="grid items-stretch gap-x-4 sm:gap-x-6" style="grid-template-columns: auto 1fr auto;">

                    {{-- Y-axis title column (stretches to the heatmap height) --}}
                    <div class="w-6 sm:w-8 md:w-10 shrink-0 flex items-center justify-center">
                        <span class="vertical-text text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap">
                            Download (Mbps) ↑
                        </span>
                    </div>

                    {{-- Heatmap (this alone sets the row height) --}}
                    <div class="min-w-0">
                        <div x-data="heatmapTooltip()" x-ref="wrap" class="relative w-full"
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
                                        stroke="{{ $tileStrokeColor }}" stroke-width="stroke-width="{{ $tileStrokeWidth }}"
                                        {{-- send tooltip data on hover --}}
                                        @mouseenter="enter({ 
                                            u: '{{ e($t['uLabel'] ?? '') }}', 
                                            d: '{{ e($t['dLabel'] ?? '') }}', 
                                            count: {{ (int)($t['count'] ?? 0) }}, 
                                            color: '{{ $t['fill'] }}', 
                                            hasData: {{ !empty($t['hasData']) ? 'true' : 'false' }} 
                                        })"
                                    />
                                @endforeach
                            </svg>

                            {{-- Floating tooltip --}}
                            <div x-cloak x-show="show" x-ref="tip" :style="style"
                                 class="pointer-events-none absolute z-20 min-w-[180px] rounded-lg bg-gray-900 bg-opacity-95 p-2 text-[11px] text-white shadow-lg ring-1 ring-black/40">
                                <div class="font-semibold mb-1">Throughput bin</div>

                                <div class="grid grid-cols-[auto_1fr] gap-x-2 gap-y-1">
                                    <div class="flex flex-row">
                                        <span class="inline-block h-3 w-3 rounded-sm" :style="{ background: hsl(199, 89%, 48%); }"></span>
                                        <span><span class="opacity-70">Download:</span> <span x-text="info?.d"></span> Mbps</span>
                                    </div>
                                    <div class="flex flex-row">
                                        <span class="inline-block h-3 w-3 rounded-sm" :style="{ background: hsl(0, 95%, 49%); }"></span>
                                        <span><span class="opacity-70">Upload:</span> <span x-text="info?.u"></span> Mbps</span>
                                    </div>
                                </div>

                                <template x-if="info && !info.hasData">
                                    <div class="mt-1 italic opacity-60">No data</div>
                                </template>
                            </div>
                        </div>

                        <div class="mt-3 text-center">
                            <span class="text-xs sm:text-sm font-medium text-gray-500 dark:text-gray-400">
                                Upload (Mbps) →
                            </span>
                        </div>
                    </div>

                    {{-- Vertical legend (stretches to the heatmap height) --}}
                    <div class="w-16 sm:w-20 shrink-0 relative">
                        <div class="h-full relative" style="height: 85%;">
                            {{-- gradient bar --}}
                            <div class="absolute inset-y-0 left-0 w-4 sm:w-5 rounded-md overflow-hidden border border-gray-300 dark:border-gray-600">
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
                                    <span class="h-px w-2 bg-gray-400 dark:bg-gray-500"></span>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                        {{ $tick['value'] }}
                                    </span>
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

        <style>
            .vertical-text{
                writing-mode: vertical-rl;
                text-orientation: mixed;
            }
            [x-cloak]{ display:none !important; }
        </style>

        {{-- Alpine helper (declared once per page; harmless if re-declared) --}}
        <script>
            document.addEventListener('alpine:init', () => {
                if (!window.__heatmapTooltipDefined) {
                    Alpine.data('heatmapTooltip', () => ({
                        show: false,
                        x: 0, y: 0,
                        info: null,
                        enter(info) { this.info = info; this.show = true; },
                        leave() { this.show = false; },
                        move(e) {
                            const rect = this.$refs.wrap.getBoundingClientRect();
                            this.x = e.clientX - rect.left;
                            this.y = e.clientY - rect.top;
                        },
                        get style() {
                            const pad = 12;
                            const wrap = this.$refs.wrap.getBoundingClientRect();
                            const tip = this.$refs.tip;
                            const tw = tip ? tip.offsetWidth : 180;
                            const th = tip ? tip.offsetHeight : 80;
                            let left = this.x + pad;
                            let top  = this.y + pad;
                            if (left + tw > wrap.width) left = this.x - tw - pad;
                            if (top + th > wrap.height) top = this.y - th - pad;
                            left = Math.max(0, left); top = Math.max(0, top);
                            return `left:${left}px;top:${top}px;`;
                        },
                    }));
                    window.__heatmapTooltipDefined = true;
                }
            });
        </script>
    </x-filament::section>
</x-filament-widgets::widget>
