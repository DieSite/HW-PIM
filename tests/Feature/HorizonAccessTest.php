<?php

use Illuminate\Support\Facades\Gate;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * @param  list<string>  $permissions
 */
function horizonAdminWith(array $permissions): Admin
{
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => $permissions]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

it('sends a guest to the admin login, whatever IP address it comes from', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '87.215.222.51'])
        ->get(route('horizon.index'))
        ->assertRedirect(route('admin.session.create'));

    expect(Gate::check('viewHorizon', [null]))->toBeFalse();
});

it('lets a full administrator in', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    expect(Gate::check('viewHorizon', [null]))->toBeTrue();

    $this->get(route('horizon.index'))->assertOk();
});

it('lets a custom role in only with the horizon permission', function () {
    $this->actingAs(horizonAdminWith(['dashboard', 'horizon']), 'admin');

    expect(Gate::check('viewHorizon', [null]))->toBeTrue();

    $this->get(route('horizon.index'))->assertOk();
});

it('keeps a custom role without the permission out of the dashboard and its API', function () {
    $this->actingAs(horizonAdminWith(['dashboard', 'simple-stock']), 'admin');

    expect(Gate::check('viewHorizon', [null]))->toBeFalse();

    $this->get(route('horizon.index'))->assertUnauthorized();
    $this->getJson(route('horizon.stats.index'))->assertForbidden();
});

it('refuses an admin whose account was deactivated', function () {
    $this->actingAs(Admin::factory()->create(['status' => 0]), 'admin');

    expect(Gate::check('viewHorizon', [null]))->toBeFalse();
});
