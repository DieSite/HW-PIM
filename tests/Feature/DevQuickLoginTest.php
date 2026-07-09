<?php

use Webkul\User\Models\Admin;

it('logs the first admin in when the app runs locally', function () {
    $this->app['env'] = 'local';

    $this->get('/dev/quick-login')
        ->assertRedirect(route('admin.dashboard.index'));

    expect(auth('admin')->id())->toBe(Admin::query()->first()->id);
});

it('is not available outside the local environment', function () {
    $this->get('/dev/quick-login')->assertNotFound();

    expect(auth('admin')->check())->toBeFalse();
});
