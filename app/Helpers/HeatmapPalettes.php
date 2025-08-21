<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Utilities for mapping normalized values [0..1] to color palettes and
 * generating CSS gradients for legends.
 */
final class HeatmapPalettes
{
    /**
     * Minimum/maximum number of samples used when building a gradient string.
     * - MIN_SAMPLES=3 ensures we always include start/middle/end colors.
     * - MAX_SAMPLES=256 caps string length and prevents needlessly large CSS.
     */
    private const MIN_SAMPLES = 3;
    private const MAX_SAMPLES = 256;

    /**
     * Small epsilon to guard division-by-zero when two consecutive color stops
     * share the same position (rare, but defensive).
     */
    private const EPSILON = 1e-9;

    /**
     * Build a left→right CSS linear-gradient for a palette.
     *
     * We "sample" the palette at evenly-spaced positions along [0..1] and
     * join them into a CSS gradient string. The sample count is clamped
     * to a safe range to avoid overly long style attributes.
     *
     * @param  string $palette      Palette key (see self::options()).
     * @param  int    $sampleCount  Number of discrete samples across [0..1].
     * @return string               e.g. 'linear-gradient(to right, #000 0%, ...)'
     */
    public static function gradientCss(string $palette, int $sampleCount = 24): string
    {
        $sampleCount = max(self::MIN_SAMPLES, min(self::MAX_SAMPLES, $sampleCount));

        // Collect hex colors sampled along the palette.
        $sampledHexColors = [];
        for ($i = 0; $i < $sampleCount; $i++) {
            // Normalize index i into [0..1]. Using ($sampleCount - 1) ensures we
            // include both endpoints exactly (0 and 1).
            $normalizedPosition = $i / ($sampleCount - 1);
            $sampledHexColors[] = self::colorAt($palette, $normalizedPosition);
        }

        // Build "color pct%" segments for CSS. We round to 2 decimals to keep
        // the string compact while being visually indistinguishable.
        $cssStops = [];
        foreach ($sampledHexColors as $i => $hex) {
            $percent = ($i === $sampleCount - 1)
                ? 100
                : round(($i / ($sampleCount - 1)) * 100, 2);

            $cssStops[] = "{$hex} {$percent}%";
        }

        return 'linear-gradient(to right, ' . implode(', ', $cssStops) . ')';
    }

    /**
     * Return a hex color for a normalized position in [0..1] for the palette.
     *
     * We find the two surrounding color stops (start and end) such that
     * start.pos <= t <= end.pos, compute the local interpolation fraction, and
     * linearly blend (lerp) the two colors.
     *
     * @param  string $palette
     * @param  float  $normalizedPosition  Value in [0..1] (will be clamped).
     * @return string Hex color like '#aabbcc'
     */
    public static function colorAt(string $palette, float $normalizedPosition): string
    {
        // Clamp to [0,1] to protect against minor numerical drift or caller error.
        $t = max(0.0, min(1.0, $normalizedPosition));

        $colorStops = self::paletteStops($palette);
        $stopCount  = count($colorStops);

        // Scan for the stop segment that contains $t.
        for ($i = 0; $i < $stopCount - 1; $i++) {
            [$startPos, $startHex] = $colorStops[$i];
            [$endPos,   $endHex]   = $colorStops[$i + 1];

            if ($t >= $startPos && $t <= $endPos) {
                // Local fraction within [startPos..endPos].
                $range = max(self::EPSILON, $endPos - $startPos);
                $localPosition = ($t - $startPos) / $range;

                return self::interpolateHex($startHex, $endHex, $localPosition);
            }
        }

        // If $t is outside the explicit ranges (due to floating rounding), fall
        // back to nearest end color.
        return $t < 0.5 ? $colorStops[0][1] : $colorStops[$stopCount - 1][1];
    }

    /**
     * Palette dropdown options (value => label).
     * Note: Viridis, Magma, and Cividis are designed to be perceptually uniform
     * and color-vision-deficiency aware; prefer them for quantitative heatmaps.
     */
    public static function options(): array
    {
        return [
            'viridis' => 'Viridis (CVD-friendly)',
            'magma'   => 'Magma (CVD-aware)',
            'cividis' => 'Cividis (CVD-aware)',
            'turbo'   => 'Turbo',
            'blues'   => 'Blues (mono)',
            'greys'   => 'Greys (mono)',
        ];
    }

    /**
     * Palette definitions as [position(0..1), hex] stops.
     * Positions are chosen to produce smooth transitions matching known colormaps.
     *
     * @return array<int, array{0: float, 1: string}>
     */
    private static function paletteStops(string $palette): array
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

    /**
     * Linear interpolation between two hex colors.
     *
     * @param  string $hexStart '#rrggbb'
     * @param  string $hexEnd   '#rrggbb'
     * @param  float  $t        Fraction in [0..1]
     */
    private static function interpolateHex(string $hexStart, string $hexEnd, float $t): string
    {
        [$r1, $g1, $b1] = self::rgbFromHex($hexStart);
        [$r2, $g2, $b2] = self::rgbFromHex($hexEnd);

        $r = (int) round($r1 + ($r2 - $r1) * $t);
        $g = (int) round($g1 + ($g2 - $g1) * $t);
        $b = (int) round($b1 + ($b2 - $b1) * $t);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Convert '#rgb' or '#rrggbb' into [r,g,b].
     * Supports shorthand '#rgb' by expanding each nibble.
     *
     * @return array{0:int,1:int,2:int}
     */
    private static function rgbFromHex(string $hex): array
    {
        $hex = ltrim($hex, '#');

        // Expand shorthand '#rgb' → '#rrggbb'
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
