<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Webkul\Core\Models\CoreConfig;
use Webkul\User\Models\Admin;

function loginAsConfigAdmin(): Admin
{
    $admin = Admin::factory()->create([
        'password' => Hash::make('password'),
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

it('renders the HW icon upload field on the image editor configuration page', function () {
    loginAsConfigAdmin();

    $this->get(route('admin.configuration.edit', ['image_editor', 'settings']))
        ->assertOk()
        ->assertSeeText('HW icoon')
        ->assertSee('type="file"', false);
});

it('changes the HW icon from the configuration UI by uploading an image', function () {
    Storage::fake(config('filesystems.default'));

    loginAsConfigAdmin();

    $configItem = collect(config('core'))->firstWhere('key', 'image_editor.settings.general');

    expect($configItem)->not->toBeNull();

    $file = UploadedFile::fake()->image('hw-icon.png', 129, 129);

    $this->post(route('admin.configuration.store'), [
        'image_editor' => ['settings' => ['general' => ['hw_icon' => $file]]],
        'keys'         => [json_encode($configItem)],
    ])
        ->assertRedirect()
        ->assertSessionHas('success', trans('admin::app.configuration.index.save-message'));

    $storedValue = core()->getConfigData('image_editor.settings.general.hw_icon');

    expect($storedValue)
        ->not->toBeNull()
        ->toStartWith('configuration/');

    $this->assertDatabaseHas((new CoreConfig())->getTable(), [
        'code'  => 'image_editor.settings.general.hw_icon',
        'value' => $storedValue,
    ]);
});
