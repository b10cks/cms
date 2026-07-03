<?php

namespace App\Services\Asset;

use Jcupitt\Vips\Image as VipsImage;

/**
 * Extracts the dominant color and a small palette from raster images.
 *
 * The image is downscaled to a small sample before pixels are histogrammed
 * (4 bits per channel buckets), so extraction cost is bounded regardless of
 * the source image size. Downscaling prefers libvips (shrink-on-load, wider
 * format support) and falls back to a full GD decode guarded by a pixel cap.
 * Mostly-transparent pixels are ignored so logos and cut-outs on transparent
 * canvases yield their actual subject color.
 */
class DominantColorExtractor
{
    /** Longest edge of the downscaled sample the histogram runs on. */
    private const SAMPLE_SIZE = 64;

    /** Maximum number of palette entries returned (including the dominant color). */
    private const PALETTE_SIZE = 5;

    /** Pixels above this alpha (0 = opaque, 127 = transparent) are ignored. */
    private const ALPHA_CUTOFF = 64;

    /** Minimum RGB distance between palette entries so the palette stays diverse. */
    private const MIN_PALETTE_DISTANCE = 40;

    /** Images beyond this many pixels are skipped to bound decode memory. */
    private const MAX_PIXELS = 40_000_000;

    /**
     * WCAG contrast stats for a hex color, so consumers can pick an
     * accessible overlay/text color for content rendered on top of the
     * image. `scheme` is 'dark' when white text contrasts better (treat the
     * image as a dark surface) and 'light' otherwise.
     *
     * @return array{scheme: 'dark'|'light', luminance: float, contrast_white: float, contrast_black: float}
     */
    public static function a11yStats(string $hex): array
    {
        [$r, $g, $b] = sscanf(ltrim($hex, '#'), '%02x%02x%02x');

        $luminance = 0.2126 * self::linearize($r)
            + 0.7152 * self::linearize($g)
            + 0.0722 * self::linearize($b);

        $contrastWhite = 1.05 / ($luminance + 0.05);
        $contrastBlack = ($luminance + 0.05) / 0.05;

        return [
            'scheme' => $contrastWhite >= $contrastBlack ? 'dark' : 'light',
            'luminance' => round($luminance, 4),
            'contrast_white' => round($contrastWhite, 2),
            'contrast_black' => round($contrastBlack, 2),
        ];
    }

    private static function linearize(int $channel): float
    {
        $c = $channel / 255;

        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    public static function supports(?string $mimeType): bool
    {
        return match ($mimeType) {
            'image/jpeg', 'image/pjpeg', 'image/png', 'image/gif',
            'image/bmp', 'image/x-ms-bmp' => true,
            'image/webp' => function_exists('imagecreatefromwebp') || self::vipsAvailable(),
            'image/avif' => function_exists('imagecreatefromavif') || self::vipsAvailable(),
            'image/tiff', 'image/heic', 'image/heif' => self::vipsAvailable(),
            default => false,
        };
    }

    private static function vipsAvailable(): bool
    {
        return class_exists(VipsImage::class);
    }

    /**
     * @return array{dominant_color: string, palette: list<string>, a11y: array{scheme: 'dark'|'light', luminance: float, contrast_white: float, contrast_black: float}}|null
     */
    public function extract(string $path, ?string $mimeType = null): ?array
    {
        if ($mimeType !== null && ! self::supports($mimeType)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return $this->extractFromString($contents);
    }

    /**
     * @return array{dominant_color: string, palette: list<string>, a11y: array{scheme: 'dark'|'light', luminance: float, contrast_white: float, contrast_black: float}}|null
     */
    public function extractFromString(string $contents): ?array
    {
        try {
            $image = $this->decodeSample($contents);

            if (! $image) {
                return null;
            }

            try {
                imagepalettetotruecolor($image);

                $sample = $this->downscale($image);

                try {
                    return $this->histogram($sample);
                } finally {
                    if ($sample !== $image) {
                        imagedestroy($sample);
                    }
                }
            } finally {
                imagedestroy($image);
            }
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Decode the source into a GD image ready for sampling. libvips is
     * preferred: thumbnail_buffer shrinks on load, so huge sources never get
     * fully decoded, and the tiny PNG it emits normalizes any colourspace or
     * band layout for the GD histogram. Without vips, GD decodes the full
     * image, so a pixel cap guards against decompression bombs.
     */
    private function decodeSample(string $contents): ?\GdImage
    {
        if (self::vipsAvailable()) {
            try {
                $sample = VipsImage::thumbnail_buffer($contents, self::SAMPLE_SIZE, [
                    'height' => self::SAMPLE_SIZE,
                ]);

                $image = @imagecreatefromstring($sample->writeToBuffer('.png'));

                if ($image) {
                    return $image;
                }
            } catch (\Throwable) {
                // Fall through to the GD path.
            }
        }

        $info = @getimagesizefromstring($contents);

        if (! $info || ($info[0] * $info[1]) > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        return $image ?: null;
    }

    private function downscale(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::SAMPLE_SIZE) {
            return $image;
        }

        $scale = self::SAMPLE_SIZE / $longest;
        $sample = imagescale(
            $image,
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
            IMG_BILINEAR_FIXED
        );

        return $sample ?: $image;
    }

    /**
     * @return array{dominant_color: string, palette: list<string>, a11y: array{scheme: 'dark'|'light', luminance: float, contrast_white: float, contrast_black: float}}|null
     */
    private function histogram(\GdImage $image): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Bucket pixels by their top 4 bits per channel, accumulating exact
        // channel sums so each bucket can report its true average color.
        $buckets = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($image, $x, $y);

                if ((($rgba >> 24) & 0x7F) > self::ALPHA_CUTOFF) {
                    continue;
                }

                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);

                if (! isset($buckets[$key])) {
                    $buckets[$key] = [0, 0, 0, 0];
                }

                $buckets[$key][0]++;
                $buckets[$key][1] += $r;
                $buckets[$key][2] += $g;
                $buckets[$key][3] += $b;
            }
        }

        if (! $buckets) {
            return null;
        }

        uasort($buckets, fn (array $a, array $b) => $b[0] <=> $a[0]);

        $palette = [];

        foreach ($buckets as [$count, $sumR, $sumG, $sumB]) {
            $color = [
                (int) round($sumR / $count),
                (int) round($sumG / $count),
                (int) round($sumB / $count),
            ];

            foreach ($palette as $existing) {
                if ($this->distance($existing, $color) < self::MIN_PALETTE_DISTANCE) {
                    continue 2;
                }
            }

            $palette[] = $color;

            if (count($palette) >= self::PALETTE_SIZE) {
                break;
            }
        }

        $hexPalette = array_map(
            fn (array $rgb) => sprintf('#%02x%02x%02x', ...$rgb),
            $palette
        );

        return [
            'dominant_color' => $hexPalette[0],
            'palette' => $hexPalette,
            'a11y' => self::a11yStats($hexPalette[0]),
        ];
    }

    /**
     * @param  array{int, int, int}  $a
     * @param  array{int, int, int}  $b
     */
    private function distance(array $a, array $b): float
    {
        return sqrt(
            ($a[0] - $b[0]) ** 2
            + ($a[1] - $b[1]) ** 2
            + ($a[2] - $b[2]) ** 2
        );
    }
}
