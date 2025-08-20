<?php

namespace App\Helpers;

final class HeatmapPalettes
{
    /**
     * Returns a CSS linear-gradient (left->right) for the legend bar.
     */
    public static function gradientCss(string $palette, int $steps = 24): string
    {
        $steps = max(3, min(256, $steps));
        $colors = [];
        for ($i = 0; $i < $steps; $i++) {
            $t = $i / ($steps - 1);
            $colors[] = self::colorAt($palette, $t);
        }

        $parts = [];
        foreach ($colors as $i => $hex) {
            $pct = $i === ($steps - 1) ? 100 : round(($i / ($steps - 1)) * 100, 2);
            $parts[] = "{$hex} {$pct}%";
        }

        return "linear-gradient(to right, " . implode(', ', $parts) . ")";
    }

    /**
     * Get a hex color for normalized $t in [0,1] for the selected palette.
     */
    public static function colorAt(string $palette, float $t): string
    {
        $t = max(0, min(1, $t));
        $stops = self::stops($palette);

        for ($i = 0; $i < count($stops) - 1; $i++) {
            [$p0, $c0] = $stops[$i];
            [$p1, $c1] = $stops[$i + 1];
            if ($t >= $p0 && $t <= $p1) {
                $local = ($t - $p0) / max(1e-9, ($p1 - $p0));
                return self::lerpHex($c0, $c1, $local);
            }
        }

        return $stops[$t < 0.5 ? 0 : count($stops) - 1][1];
    }

    /** Options for the dropdown. */
    public static function options(): array
    {
        return [
            'viridis'  => 'Viridis (CVD-friendly)',
            'magma'    => 'Magma (CVD-aware)',
            'cividis'  => 'Cividis (CVD-aware)',
            'turbo'    => 'Turbo',
            'blues'    => 'Blues (mono)',
            'greys'    => 'Greys (mono)',
        ];
    }

    /** Define palettes as [position(0..1), hex] stops. */
    private static function stops(string $palette): array
    {
        return match ($palette) {
            'magma' => [
                [0.00, '#000004'], [0.20, '#3b0f70'], [0.40, '#8c2981'],
                [0.60, '#de4968'], [0.80, '#fe9f6d'], [1.00, '#fcfdbf'],
            ],
            'cividis' => [
                [0.00, '#00204c'], [0.20, '#1e3653'], [0.40, '#3e4f5a'],
                [0.60, '#626e5e'], [0.80, '#8b9b61'], [1.00, '#d1e05b'],
            ],
            'turbo' => [
                [0.00, '#30123b'], [0.17, '#3a53a4'], [0.33, '#21b1d7'],
                [0.50, '#29d17f'], [0.67, '#a7e62f'], [0.83, '#f5a100'],
                [1.00, '#b10a0a'],
            ],
            'blues' => [
                [0.00, '#f7fbff'], [0.20, '#deebf7'], [0.40, '#c6dbef'],
                [0.60, '#9ecae1'], [0.80, '#6baed6'], [1.00, '#2171b5'],
            ],
            'greys' => [
                [0.00, '#f9fafb'], [0.20, '#e5e7eb'], [0.40, '#d1d5db'],
                [0.60, '#9ca3af'], [0.80, '#6b7280'], [1.00, '#374151'],
            ],
            default /* viridis */ => [
                [0.00, '#440154'], [0.20, '#3b528b'], [0.40, '#21918c'],
                [0.60, '#5ec962'], [0.80, '#aadc32'], [1.00, '#fde725'],
            ],
        };
    }

    private static function lerpHex(string $hexA, string $hexB, float $t): string
    {
        [$r1, $g1, $b1] = self::hexToRgb($hexA);
        [$r2, $g2, $b2] = self::hexToRgb($hexB);

        $r = (int) round($r1 + ($r2 - $r1) * $t);
        $g = (int) round($g1 + ($g2 - $g1) * $t);
        $b = (int) round($b1 + ($b2 - $b1) * $t);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = "{$hex[0]}{$hex[0]}{$hex[1]}{$hex[1]}{$hex[2]}{$hex[2]}";
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
