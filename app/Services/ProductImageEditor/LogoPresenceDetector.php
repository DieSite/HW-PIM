<?php

namespace App\Services\ProductImageEditor;

/**
 * Detects whether an image already carries the HW logo in its bottom-left
 * corner by template-matching the configured icon against that region.
 *
 * Exists for images stamped manually in the past, which the bookkeeping in
 * asset_logo_variants knows nothing about. Matching is done at a reduced
 * resolution (config logo_detection.match_width) because ImageMagick's
 * subimage search is brute force; at ~32px template width a match is still
 * unambiguous while the search stays in the millisecond range.
 */
class LogoPresenceDetector
{
    public function hasLogo(string $imageContents, string $iconContents): bool
    {
        $config = config('product_image_editor');
        $settings = $config['logo_detection'] ?? [];

        if (! ($settings['enabled'] ?? false) || ! extension_loaded('imagick')) {
            return false;
        }

        // All geometry below is proportional to the image dimensions, so JPEGs
        // may be decoded downsampled (huge speedup on multi-MP photos); the
        // working window ends up ~match_width/0.14 px wide anyway.
        $image = new \Imagick();
        $image->setOption('jpeg:size', '1024x1024');
        $image->readImageBlob($imageContents);

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        $windowWidth = max(1, (int) round($width * (float) $settings['window']['width']));
        $windowHeight = max(1, (int) round($height * (float) $settings['window']['height']));

        $window = $image;
        $window->cropImage($windowWidth, $windowHeight, 0, $height - $windowHeight);
        $window->setImagePage(0, 0, 0, 0);
        $this->flatten($window);

        $icon = new \Imagick();
        $icon->readImageBlob($iconContents);
        $this->flatten($icon);

        $expectedIconWidth = $width * (int) $config['icon']['width'] / max(1, (int) $config['output']['width']);
        $matchWidth = max(8, (int) ($settings['match_width'] ?? 32));
        $threshold = (float) ($settings['threshold'] ?? 0.2);

        if ($expectedIconWidth < 8) {
            return false;
        }

        // Downscale the window ONCE to the working resolution (expected icon
        // width -> match_width px) and vary the template size per scale factor
        // instead; re-resizing the full window per factor dominates runtime.
        $downScale = min(1.0, $matchWidth / $expectedIconWidth);

        $window->resizeImage(
            max(1, (int) round($windowWidth * $downScale)),
            max(1, (int) round($windowHeight * $downScale)),
            \Imagick::FILTER_LANCZOS,
            1,
        );

        foreach ($this->scaleFactors($settings) as $factor) {
            $templateWidth = (int) round($expectedIconWidth * $downScale * (float) $factor);

            $template = clone $icon;
            $template->resizeImage(
                max(1, $templateWidth),
                max(1, (int) round($templateWidth * $icon->getImageHeight() / $icon->getImageWidth())),
                \Imagick::FILTER_LANCZOS,
                1,
            );

            if ($templateWidth < 6
                || $template->getImageWidth() >= $window->getImageWidth()
                || $template->getImageHeight() >= $window->getImageHeight()) {
                continue;
            }

            $similarity = null;

            try {
                $window->subimageMatch($template, $offset, $similarity);
            } catch (\ImagickException) {
                continue;
            }

            if ($similarity !== null && $similarity <= $threshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, float>
     */
    private function scaleFactors(array $settings): array
    {
        $range = $settings['scale_range'] ?? ['min' => 0.6, 'max' => 1.45, 'step' => 0.025];

        $factors = [];
        $step = max(0.005, (float) $range['step']);

        for ($factor = (float) $range['min']; $factor <= (float) $range['max'] + 1e-9; $factor += $step) {
            $factors[] = round($factor, 4);
        }

        return $factors;
    }

    /**
     * Remove alpha by compositing over white and normalise to sRGB so JPEG
     * photos and the PNG icon compare in the same space.
     */
    private function flatten(\Imagick $image): void
    {
        $image->setImageBackgroundColor('white');
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
    }
}
