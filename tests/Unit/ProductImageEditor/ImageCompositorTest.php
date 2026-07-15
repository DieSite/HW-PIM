<?php

use App\Services\ProductImageEditor\ImageCompositor;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

function compositorPng(int $width, int $height, string $color): string
{
    return (string) app(ImageManager::class)->create($width, $height)->fill($color)->toPng();
}

function compositorHexAt(ImageInterface $image, int $x, int $y): string
{
    return ltrim($image->pickColor($x, $y)->toHex(), '#');
}

function compositorTransform(array $overrides = []): array
{
    return array_merge([
        'scale'    => 1.0,
        'offset_x' => 0,
        'offset_y' => 0,
        'rotation' => 0.0,
        'resize'   => true,
        'padding'  => true,
        'icon'     => true,
    ], $overrides);
}

/**
 * A portrait image whose top half is red and bottom half is blue, so rotation
 * can be detected by which colour ends up where.
 */
function compositorTwoTone(): string
{
    $image = app(ImageManager::class)->create(400, 600)->fill('0000ff');
    $image->place(app(ImageManager::class)->create(400, 300)->fill('ff0000'), 'top-left', 0, 0);

    return (string) $image->toPng();
}

beforeEach(function () {
    config()->set('product_image_editor.output', ['width' => 917, 'height' => 1094]);
    config()->set('product_image_editor.rug_rect', ['x' => 126, 'y' => 65, 'width' => 665, 'height' => 964]);
    config()->set('product_image_editor.icon', ['width' => 129, 'margin' => 40]);

    $this->source = compositorPng(400, 600, 'ff0000');
    $this->icon = compositorPng(50, 50, '0000ff');
    $this->compositor = app(ImageCompositor::class);
});

it('outputs the configured size with white padding and the rug centered', function () {
    $out = $this->compositor->render($this->source, compositorTransform(), $this->icon, true);

    expect($out->width())->toBe(917)
        ->and($out->height())->toBe(1094)
        // Corner is outside the rug rectangle -> white padding.
        ->and(compositorHexAt($out, 5, 5))->toBe('ffffff')
        // Center sits inside the rug rectangle -> rug colour.
        ->and(compositorHexAt($out, 458, 400))->toBe('ff0000');
});

it('overlays the HW icon in the bottom-left corner only when enabled', function () {
    // (60, 1000) is left of the rug rectangle (white) but inside the icon box.
    $withIcon = $this->compositor->render($this->source, compositorTransform(), $this->icon, true);
    expect(compositorHexAt($withIcon, 60, 1000))->toBe('0000ff');

    $noIcon = $this->compositor->render($this->source, compositorTransform(), $this->icon, false);
    expect(compositorHexAt($noIcon, 60, 1000))->toBe('ffffff');
});

it('honours the icon toggle independently of the withIcon flag', function () {
    $out = $this->compositor->render($this->source, compositorTransform(['icon' => false]), $this->icon, true);

    expect(compositorHexAt($out, 60, 1000))->toBe('ffffff');
});

it('produces a no-logo variant identical to the main image apart from the icon', function () {
    $transform = compositorTransform();

    $main = $this->compositor->render($this->source, $transform, $this->icon, true);
    $noLogo = $this->compositor->render($this->source, $transform, $this->icon, false);

    // Everywhere outside the icon box the two variants are pixel-for-pixel equal:
    // rug interior, white padding corners, and padding above the icon box.
    foreach ([[458, 400], [5, 5], [900, 10], [850, 1080]] as [$x, $y]) {
        expect(compositorHexAt($noLogo, $x, $y))->toBe(compositorHexAt($main, $x, $y));
    }

    // The only difference is the bottom-left icon: present on the main, absent on the no-logo.
    expect(compositorHexAt($main, 60, 1000))->toBe('0000ff')
        ->and(compositorHexAt($noLogo, 60, 1000))->toBe('ffffff');
});

it('resizes onto a white canvas when padding is disabled but resize is on', function () {
    $out = $this->compositor->render($this->source, compositorTransform(['padding' => false]), null, false);

    expect($out->width())->toBe(917)
        ->and($out->height())->toBe(1094)
        // A portrait 400x600 contained in 917x1094 leaves white pillarboxing at the far edges.
        ->and(compositorHexAt($out, 2, 547))->toBe('ffffff');
});

it('leaves the source untouched when both resize and padding are disabled', function () {
    $out = $this->compositor->render(
        $this->source,
        compositorTransform(['padding' => false, 'resize' => false]),
        null,
        false,
    );

    expect($out->width())->toBe(400)
        ->and($out->height())->toBe(600);
});

it('masks the rug into the shape silhouette', function () {
    $rondRect = config('product_image_editor.shapes.rond.rect');

    $out = $this->compositor->render(
        $this->source,
        compositorTransform(['shape' => 'rond', 'rect' => $rondRect, 'outline' => false]),
        null,
        false,
    );

    // Centre of the circle is rug; far corner and a rect-corner outside the
    // circle are white (proving the silhouette mask, not just a rectangle).
    expect($out->width())->toBe(917)
        ->and($out->height())->toBe(1094)
        ->and(compositorHexAt($out, 458, 547))->toBe('ff0000')
        ->and(compositorHexAt($out, 5, 5))->toBe('ffffff')
        ->and(compositorHexAt($out, 60, 150))->toBe('ffffff');
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('stamps the icon proportionally onto an arbitrary image, preserving its size', function () {
    // Twice the 917px reference width -> the icon and margin must scale 2x.
    $source = compositorPng(1834, 1200, 'ff0000');

    $out = $this->compositor->stampIcon($source, $this->icon);

    expect($out->width())->toBe(1834)
        ->and($out->height())->toBe(1200)
        // Inside the scaled icon box (margin 80, icon 258x258 -> x 80..338, y 862..1120).
        ->and(compositorHexAt($out, 100, 1000))->toBe('0000ff')
        // Away from the icon the image is untouched.
        ->and(compositorHexAt($out, 900, 100))->toBe('ff0000');
});

it('places the rug into a supplied shape rectangle', function () {
    $rect = ['x' => 600, 'y' => 50, 'width' => 250, 'height' => 250];

    $out = $this->compositor->render($this->source, compositorTransform(['rect' => $rect]), null, false);

    // Inside the supplied rect -> rug colour; far outside -> white padding.
    expect(compositorHexAt($out, 720, 170))->toBe('ff0000')
        ->and(compositorHexAt($out, 100, 800))->toBe('ffffff');
});

it('rotates the rug 180 degrees inside the white padding rectangle', function () {
    $source = compositorTwoTone();

    $upright = $this->compositor->render($source, compositorTransform(), null, false);
    // Upright: red top half, blue bottom half within the rug rectangle.
    expect(compositorHexAt($upright, 458, 200))->toBe('ff0000')
        ->and(compositorHexAt($upright, 458, 900))->toBe('0000ff');

    $rotated = $this->compositor->render($source, compositorTransform(['rotation' => 180]), null, false);
    // Rotated 180°: the halves swap.
    expect(compositorHexAt($rotated, 458, 200))->toBe('0000ff')
        ->and(compositorHexAt($rotated, 458, 900))->toBe('ff0000');
});

it('rotates the rug when padding is disabled (contained on a white canvas)', function () {
    $source = compositorTwoTone();

    $upright = $this->compositor->render($source, compositorTransform(['padding' => false]), null, false);
    expect(compositorHexAt($upright, 458, 100))->toBe('ff0000')
        ->and(compositorHexAt($upright, 458, 1000))->toBe('0000ff');

    $rotated = $this->compositor->render($source, compositorTransform(['padding' => false, 'rotation' => 180]), null, false);
    expect(compositorHexAt($rotated, 458, 100))->toBe('0000ff')
        ->and(compositorHexAt($rotated, 458, 1000))->toBe('ff0000');
});

it('rotates the rug inside a shape silhouette', function () {
    $rondRect = config('product_image_editor.shapes.rond.rect');
    $source = compositorTwoTone();

    $transform = ['shape' => 'rond', 'rect' => $rondRect, 'outline' => false];

    $upright = $this->compositor->render($source, compositorTransform($transform), null, false);
    // Just above / below the circle centre: red on top, blue on the bottom.
    expect(compositorHexAt($upright, 458, 350))->toBe('ff0000')
        ->and(compositorHexAt($upright, 458, 750))->toBe('0000ff');

    $rotated = $this->compositor->render($source, compositorTransform($transform + ['rotation' => 180]), null, false);
    expect(compositorHexAt($rotated, 458, 350))->toBe('0000ff')
        ->and(compositorHexAt($rotated, 458, 750))->toBe('ff0000');
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('rotates clockwise for the 90 and 270 set points', function () {
    $rondRect = config('product_image_editor.shapes.rond.rect');
    $source = compositorTwoTone(); // red top half, blue bottom half
    $base = ['shape' => 'rond', 'rect' => $rondRect, 'outline' => false];

    // 90° clockwise: the red top edge swings to the right of the circle.
    $cw90 = $this->compositor->render($source, compositorTransform($base + ['rotation' => 90]), null, false);
    expect(compositorHexAt($cw90, 670, 547))->toBe('ff0000')
        ->and(compositorHexAt($cw90, 250, 547))->toBe('0000ff');

    // 270° clockwise: the red top edge swings to the left.
    $cw270 = $this->compositor->render($source, compositorTransform($base + ['rotation' => 270]), null, false);
    expect(compositorHexAt($cw270, 250, 547))->toBe('ff0000')
        ->and(compositorHexAt($cw270, 670, 547))->toBe('0000ff');
})->skip(! extension_loaded('imagick'), 'Shape masking requires Imagick.');

it('pivots zoom and rotation on the frame centre regardless of pan', function () {
    // Red top / blue bottom; pan up so a solid red region sits under the rect
    // centre. Whatever the zoom or rotation, that pixel must stay red because
    // both operations pivot on the frame centre.
    $source = compositorTwoTone();
    $pan = ['offset_x' => 0, 'offset_y' => 250];
    [$fx, $fy] = [458, 547];

    $reference = $this->compositor->render($source, compositorTransform($pan), null, false);
    expect(compositorHexAt($reference, $fx, $fy))->toBe('ff0000');

    foreach ([['scale' => 1.8], ['rotation' => 90], ['rotation' => 180], ['scale' => 0.7, 'rotation' => 270]] as $extra) {
        $out = $this->compositor->render($source, compositorTransform($pan + $extra), null, false);
        expect(compositorHexAt($out, $fx, $fy))->toBe('ff0000');
    }
});

it('keeps the rug clipped inside the rectangle when scaled up', function () {
    $out = $this->compositor->render($this->source, compositorTransform(['scale' => 2.0]), null, false);

    // Even zoomed 2x, nothing may bleed into the white padding corner.
    expect(compositorHexAt($out, 5, 5))->toBe('ffffff')
        ->and(compositorHexAt($out, 458, 400))->toBe('ff0000');
});
