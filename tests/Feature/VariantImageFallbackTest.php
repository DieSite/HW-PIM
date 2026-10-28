<?php

use Illuminate\Support\Facades\Storage;
use Webkul\WooCommerce\Helpers\Exporters\Product\Exporter;

/**
 * Run the exporter's protected firstImageUrl() without the exporter's
 * dependency-heavy constructor.
 */
function firstImageUrl(mixed $images): string
{
    $exporter = (new ReflectionClass(Exporter::class))->newInstanceWithoutConstructor();

    return (new ReflectionMethod($exporter, 'firstImageUrl'))->invoke($exporter, $images);
}

beforeEach(function () {
    Storage::fake('private');
});

it('gives a variant an empty no-logo image when its parent has no images', function (mixed $images) {
    expect(firstImageUrl($images))->toBe('');
})->with([
    'missing'      => [null],
    'empty string' => [''],
    'only commas'  => [' , '],
    'empty array'  => [[]],
]);

it('uses the first image of the parent', function () {
    expect(firstImageUrl(' ,product/ERG0411/vloerkleed.jpg,product/ERG0411/detail.jpg'))
        ->toBe(Storage::disk('private')->url('product/ERG0411/vloerkleed.jpg'));
});
