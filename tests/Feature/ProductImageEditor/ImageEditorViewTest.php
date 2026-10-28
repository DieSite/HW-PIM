<?php

use Webkul\Product\Models\Product;

it('renders the primary image editor with the outline config for the editor script', function () {
    $product = Product::factory()->simple()->create();

    $html = view('admin::custom.productImageEditor.editor', ['product' => $product])->render();

    preg_match('#<script type="application/json" id="hw-image-editor-config">(.*?)</script>#s', $html, $match);

    $editorConfig = json_decode($match[1] ?? 'null', true);

    expect($editorConfig)->toBeArray()
        ->and($editorConfig['outline'])->toBe(config('product_image_editor.outline'));
});
