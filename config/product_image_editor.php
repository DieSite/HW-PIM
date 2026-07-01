<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Primary image editor
    |--------------------------------------------------------------------------
    |
    | Geometry and attribute mapping used when compositing the primary product
    | image. All coordinates are expressed in output pixels. The defaults were
    | measured from the reference rug image (917x1094) and can be overridden
    | here without touching code. The HW icon itself is configured through the
    | admin "Hoofdafbeelding" settings (core config key image_editor.settings.general.hw_icon).
    |
    */

    'enabled' => true,

    /**
     * Asset attribute that holds the primary (with-logo) product image.
     */
    'primary_attribute' => 'afbeelding',

    /**
     * Asset attribute that holds the no-logo variant (used by Bol).
     */
    'no_logo_attribute' => 'afbeelding_zonder_logo',

    /**
     * Disk on which DAM assets are stored.
     */
    'asset_disk' => 'private',

    /**
     * Final composited image dimensions.
     */
    'output' => [
        'width'  => 917,
        'height' => 1094,
    ],

    /**
     * Default white-padding rectangle (fallback when no shape is resolved).
     * Matches the "rechthoek" shape.
     */
    'rug_rect' => [
        'x'      => 126,
        'y'      => 65,
        'width'  => 665,
        'height' => 964,
    ],

    /**
     * Shape selected when the product's "vorm" cannot be matched.
     */
    'default_shape' => 'rechthoek',

    /**
     * Per-shape white-padding rectangles (in output pixels) the rug is fitted
     * into. Rectangles were measured from the reference images per shape. The
     * "label"/"aliases" are matched (case-insensitive) against the product's
     * "vorm" attribute to pre-select the shape in the editor.
     *
     * @var array<string, array{label: string, aliases?: array<int, string>, rect: array{x: int, y: int, width: int, height: int}}>
     */
    'shapes' => [
        'rechthoek'   => ['label' => 'Rechthoek', 'rect' => ['x' => 126, 'y' => 65, 'width' => 665, 'height' => 964]],
        'rond'        => ['label' => 'Rond', 'rect' => ['x' => 51, 'y' => 140, 'width' => 813, 'height' => 813]],
        'organic'     => ['label' => 'Organic', 'rect' => ['x' => 61, 'y' => 205, 'width' => 792, 'height' => 688]],
        'plaza'       => ['label' => 'Plaza', 'rect' => ['x' => 80, 'y' => 312, 'width' => 756, 'height' => 545]],
        'wing'        => ['label' => 'Wing', 'rect' => ['x' => 58, 'y' => 276, 'width' => 797, 'height' => 542]],
        'eclipse'     => ['label' => 'Eclipse', 'rect' => ['x' => 46, 'y' => 294, 'width' => 823, 'height' => 584]],
        'eye'         => ['label' => 'Eye', 'rect' => ['x' => 68, 'y' => 274, 'width' => 776, 'height' => 623]],
        'leaf'        => ['label' => 'Leaf', 'rect' => ['x' => 128, 'y' => 229, 'width' => 659, 'height' => 658]],
        'dice'        => ['label' => 'Dice', 'rect' => ['x' => 139, 'y' => 270, 'width' => 638, 'height' => 637]],
        'oval'        => ['label' => 'Oval', 'aliases' => ['Ovaal'], 'rect' => ['x' => 71, 'y' => 296, 'width' => 773, 'height' => 580]],
        'hexagon'     => ['label' => 'Hexagon', 'rect' => ['x' => 116, 'y' => 265, 'width' => 683, 'height' => 631]],
        'ellips'      => ['label' => 'Ellips', 'rect' => ['x' => 59, 'y' => 302, 'width' => 798, 'height' => 568]],
        'kei'         => ['label' => 'Kei', 'rect' => ['x' => 99, 'y' => 352, 'width' => 722, 'height' => 473]],
        'deens-ovaal' => ['label' => 'Deens ovaal', 'rect' => ['x' => 85, 'y' => 337, 'width' => 746, 'height' => 508]],
        'nier'        => ['label' => 'Nier', 'rect' => ['x' => 201, 'y' => 313, 'width' => 509, 'height' => 626]],
        'ei'          => ['label' => 'Ei', 'rect' => ['x' => 204, 'y' => 256, 'width' => 508, 'height' => 662]],
        'vierkant'    => ['label' => 'Vierkant', 'rect' => ['x' => 171, 'y' => 299, 'width' => 573, 'height' => 574]],
        'veelhoek'    => ['label' => 'Veelhoek', 'rect' => ['x' => 118, 'y' => 308, 'width' => 681, 'height' => 567]],
        'vlieger'     => ['label' => 'Vlieger', 'rect' => ['x' => 110, 'y' => 352, 'width' => 696, 'height' => 453]],
        'rots'        => ['label' => 'Rots', 'rect' => ['x' => 133, 'y' => 300, 'width' => 653, 'height' => 648]],
        'pebble'      => ['label' => 'Pebble', 'rect' => ['x' => 51, 'y' => 301, 'width' => 813, 'height' => 566]],
        'cloud'       => ['label' => 'Cloud', 'rect' => ['x' => 130, 'y' => 228, 'width' => 658, 'height' => 659]],
    ],

    /**
     * HW icon placement. The icon is scaled to "width" preserving aspect ratio
     * and anchored in the bottom-left corner at "margin" pixels from the edges.
     */
    'icon' => [
        'width'  => 129,
        'margin' => 40,
    ],

    /**
     * Silhouette masks: for every shape except "rechthoek" a PNG silhouette
     * (white shape on black) lives in public/{masks_path}/{shape}.png. The rug
     * is clipped to it so e.g. "rond" becomes a real circle. "rechthoek" has no
     * mask and keeps the plain rectangular placement.
     */
    'masks_path' => 'product-image-editor/masks',

    /**
     * Black outline drawn along the shape edge (matches the reference images).
     * "enabled" is the default for the per-upload toggle; width/color are fixed.
     */
    'outline' => [
        'enabled' => true,
        'width'   => 4,
        'color'   => '#1a1a1a',
    ],

    /**
     * JPEG quality for generated assets.
     */
    'quality' => 90,
];
