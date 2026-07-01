<?php

namespace App\Services\ProductImageEditor;

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Composites a source product image into the fixed HW frame.
 *
 * All geometry is expressed in output pixels so the browser preview and this
 * server-side render share one coordinate space. The same transform produced by
 * the editor (scale + offset) therefore reproduces the preview exactly.
 */
class ImageCompositor
{
    public function __construct(private ImageManager $imageManager) {}

    /**
     * Render the composited image.
     *
     * @param  string  $sourceContents  Raw bytes of the source (rug) image.
     * @param  array{scale?: float, offset_x?: int, offset_y?: int, resize?: bool, padding?: bool, icon?: bool, outline?: bool, shape?: string, rect?: array{x: int, y: int, width: int, height: int}}  $transform
     * @param  string|null  $iconContents  Raw bytes of the HW icon, or null when unavailable.
     * @param  bool  $withIcon  Whether the icon overlay is allowed for this variant (e.g. false for the no-logo image).
     */
    public function render(string $sourceContents, array $transform, ?string $iconContents, bool $withIcon): ImageInterface
    {
        $config = config('product_image_editor');

        $outputWidth = (int) $config['output']['width'];
        $outputHeight = (int) $config['output']['height'];

        $resize = (bool) ($transform['resize'] ?? true);
        $padding = (bool) ($transform['padding'] ?? true);
        $iconEnabled = $withIcon && (bool) ($transform['icon'] ?? true);

        $rect = $transform['rect'] ?? $config['rug_rect'];
        $maskPath = $this->maskPath($transform['shape'] ?? null);

        if ($padding && $maskPath !== null) {
            $canvas = $this->renderMaskedShape($sourceContents, $transform, $outputWidth, $outputHeight, $rect, $maskPath);
        } elseif ($padding) {
            $canvas = $this->renderWithPadding($this->imageManager->read($sourceContents), $transform, $outputWidth, $outputHeight, $rect);
        } elseif ($resize) {
            $canvas = $this->renderContained($this->imageManager->read($sourceContents), $outputWidth, $outputHeight);
        } else {
            $canvas = $this->imageManager->read($sourceContents);
        }

        if ($iconEnabled && $iconContents !== null && $iconContents !== '') {
            $this->overlayIcon($canvas, $iconContents, $config['icon']);
        }

        return $canvas;
    }

    /**
     * Absolute path to a shape's silhouette mask, or null when the shape has no
     * mask (e.g. "rechthoek"), the file is missing, or Imagick is unavailable.
     */
    private function maskPath(?string $shape): ?string
    {
        if (! $shape || ! extension_loaded('imagick')) {
            return null;
        }

        $path = public_path(config('product_image_editor.masks_path').'/'.$shape.'.png');

        return is_file($path) ? $path : null;
    }

    /**
     * Clip the rug to a shape silhouette (e.g. circle, organic blob) and draw an
     * optional outline along its edge. Uses Imagick directly for masking.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $rect
     */
    private function renderMaskedShape(
        string $sourceContents,
        array $transform,
        int $outputWidth,
        int $outputHeight,
        array $rect,
        string $maskPath
    ): ImageInterface {
        $rx = (int) $rect['x'];
        $ry = (int) $rect['y'];
        $rw = (int) $rect['width'];
        $rh = (int) $rect['height'];

        $scale = (float) ($transform['scale'] ?? 1.0);
        $offsetX = (int) round($transform['offset_x'] ?? 0);
        $offsetY = (int) round($transform['offset_y'] ?? 0);

        $rug = new \Imagick();
        $rug->readImageBlob($sourceContents);
        $sourceWidth = $rug->getImageWidth();
        $sourceHeight = $rug->getImageHeight();

        $cover = max($rw / $sourceWidth, $rh / $sourceHeight);
        $drawScale = $cover * max($scale, 0.01);
        $drawWidth = max(1, (int) round($sourceWidth * $drawScale));
        $drawHeight = max(1, (int) round($sourceHeight * $drawScale));
        $rug->resizeImage($drawWidth, $drawHeight, \Imagick::FILTER_LANCZOS, 1);

        $px = (int) round($rx + $rw / 2 + $offsetX - $drawWidth / 2);
        $py = (int) round($ry + $rh / 2 + $offsetY - $drawHeight / 2);

        // The rug on a full white frame (so any area not covered by the rug but
        // inside the shape ends up white rather than transparent).
        $rugLayer = new \Imagick();
        $rugLayer->newImage($outputWidth, $outputHeight, 'white');
        $rugLayer->setImageFormat('png');
        $rugLayer->compositeImage($rug, \Imagick::COMPOSITE_OVER, $px, $py);

        // The mask PNGs are alpha-based (shape opaque / bg transparent) so CSS
        // masking works in the editor. Extract the alpha back to a grayscale
        // (white shape on black) so COPYOPACITY and ERODE operate on intensity.
        $mask = new \Imagick($maskPath);
        $mask->resizeImage($outputWidth, $outputHeight, \Imagick::FILTER_BOX, 1);
        $mask->setImageAlphaChannel(\Imagick::ALPHACHANNEL_EXTRACT);

        $canvas = new \Imagick();
        $canvas->newImage($outputWidth, $outputHeight, 'white');
        $canvas->setImageFormat('png');

        $outlineConfig = config('product_image_editor.outline');
        $outlineEnabled = (bool) ($transform['outline'] ?? ($outlineConfig['enabled'] ?? true));

        if ($outlineEnabled && (int) $outlineConfig['width'] > 0) {
            // Coloured silhouette behind the rug; the eroded inner mask leaves a
            // ring of that colour around the rug, i.e. the outline.
            $silhouette = new \Imagick();
            $silhouette->newImage($outputWidth, $outputHeight, $outlineConfig['color']);
            $silhouette->compositeImage(clone $mask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
            $canvas->compositeImage($silhouette, \Imagick::COMPOSITE_OVER, 0, 0);

            $innerMask = clone $mask;
            $innerMask->morphology(\Imagick::MORPHOLOGY_ERODE, 1, \ImagickKernel::fromBuiltIn(\Imagick::KERNEL_DISK, (string) (int) $outlineConfig['width']));
            $rugLayer->compositeImage($innerMask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        } else {
            $rugLayer->compositeImage($mask, \Imagick::COMPOSITE_COPYOPACITY, 0, 0);
        }

        $canvas->compositeImage($rugLayer, \Imagick::COMPOSITE_OVER, 0, 0);
        $canvas->setImageBackgroundColor('white');
        $canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

        return $this->imageManager->read($canvas->getImageBlob());
    }

    /**
     * Place the source into the rug rectangle (cover-fit), honouring the manual
     * scale/offset, clipped so nothing bleeds into the white padding or icon.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $rect
     */
    private function renderWithPadding(
        ImageInterface $source,
        array $transform,
        int $outputWidth,
        int $outputHeight,
        array $rect
    ): ImageInterface {
        $canvas = $this->imageManager->create($outputWidth, $outputHeight)->fill('ffffff');

        $rx = (int) $rect['x'];
        $ry = (int) $rect['y'];
        $rw = (int) $rect['width'];
        $rh = (int) $rect['height'];

        $scale = (float) ($transform['scale'] ?? 1.0);
        $offsetX = (int) round($transform['offset_x'] ?? 0);
        $offsetY = (int) round($transform['offset_y'] ?? 0);

        $cover = max($rw / $source->width(), $rh / $source->height());
        $drawScale = $cover * max($scale, 0.01);

        $drawWidth = max(1, (int) round($source->width() * $drawScale));
        $drawHeight = max(1, (int) round($source->height() * $drawScale));

        $resized = $source->resize($drawWidth, $drawHeight);

        // Top-left of the scaled rug in output space (centered in the rect + manual offset).
        $px = (int) round($rx + $rw / 2 + $offsetX - $drawWidth / 2);
        $py = (int) round($ry + $rh / 2 + $offsetY - $drawHeight / 2);

        // Determine the portion of the scaled rug that is visible inside the rect,
        // cropping to avoid negative placement offsets.
        $srcLeft = max(0, $rx - $px);
        $srcTop = max(0, $ry - $py);
        $dstLeft = max(0, $px - $rx);
        $dstTop = max(0, $py - $ry);

        $visibleWidth = min($drawWidth - $srcLeft, $rw - $dstLeft);
        $visibleHeight = min($drawHeight - $srcTop, $rh - $dstTop);

        if ($visibleWidth > 0 && $visibleHeight > 0) {
            $crop = $resized->crop($visibleWidth, $visibleHeight, $srcLeft, $srcTop);
            $canvas->place($crop, 'top-left', $rx + $dstLeft, $ry + $dstTop);
        }

        return $canvas;
    }

    /**
     * Resize the source to fit (contain) inside the output on a white canvas.
     */
    private function renderContained(ImageInterface $source, int $outputWidth, int $outputHeight): ImageInterface
    {
        $canvas = $this->imageManager->create($outputWidth, $outputHeight)->fill('ffffff');

        $scale = min($outputWidth / $source->width(), $outputHeight / $source->height());

        $width = max(1, (int) round($source->width() * $scale));
        $height = max(1, (int) round($source->height() * $scale));

        $canvas->place($source->resize($width, $height), 'center');

        return $canvas;
    }

    /**
     * Overlay the HW icon in the bottom-left corner, scaled to a fixed width.
     *
     * @param  array{width: int, margin: int}  $iconConfig
     */
    private function overlayIcon(ImageInterface $canvas, string $iconContents, array $iconConfig): void
    {
        $icon = $this->imageManager->read($iconContents);

        $iconWidth = (int) $iconConfig['width'];
        $margin = (int) $iconConfig['margin'];

        $iconHeight = max(1, (int) round($icon->height() * ($iconWidth / $icon->width())));
        $icon = $icon->resize($iconWidth, $iconHeight);

        $x = $margin;
        $y = $canvas->height() - $margin - $iconHeight;

        $canvas->place($icon, 'top-left', $x, $y);
    }
}
