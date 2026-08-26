<?php

use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

function loginAsProductAdmin(): Admin
{
    $admin = Admin::factory()->create();

    test()->actingAs($admin, 'admin');

    return $admin;
}

it('redirects a known sku to the admin product edit page', function () {
    loginAsProductAdmin();

    $product = Product::factory()->simple()->create(['sku' => 'ERG1234']);

    $this->get(route('product.by-sku', ['sku' => 'ERG1234']))
        ->assertRedirect(route('admin.catalog.products.edit', ['id' => $product->id]));
});

it('handles skus containing a dot', function () {
    loginAsProductAdmin();

    $product = Product::factory()->simple()->create(['sku' => 'ERG1234.O']);

    $this->get(route('product.by-sku', ['sku' => 'ERG1234.O']))
        ->assertRedirect(route('admin.catalog.products.edit', ['id' => $product->id]));
});

it('returns a 404 for an unknown sku', function () {
    loginAsProductAdmin();

    $this->get(route('product.by-sku', ['sku' => 'DOES-NOT-EXIST']))
        ->assertNotFound();
});

it('sends guests to the admin login', function () {
    Product::factory()->simple()->create(['sku' => 'ERG1234']);

    $this->get(route('product.by-sku', ['sku' => 'ERG1234']))
        ->assertRedirect(route('admin.session.create'));
});
