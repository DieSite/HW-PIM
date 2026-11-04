<?php

use App\Services\AdminMenu;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * @return list<string>
 */
function menuNames(?string $mode): array
{
    if ($mode !== null) {
        request()->cookies->set(AdminMenu::cookieName(), $mode);
    }

    return collect(app(AdminMenu::class)->tree()->items)->pluck('name')->map(fn (string $name): string => __($name))->values()->all();
}

beforeEach(function () {
    $this->withoutVite();
});

it('shows the full menu by default', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    expect(menuNames(null))->toContain('Tools', 'Bol.com')
        ->and(app(AdminMenu::class)->mode())->toBe(AdminMenu::FULL);
});

it('shows exactly the simplified items, in order, after switching', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    expect(menuNames(AdminMenu::SIMPLE))->toBe([
        'Producten',
        'Productvoorraad updaten',
        'Showroom voorraad updaten',
        'Producten met fouten',
        'Hordeuren concurrentie-analyse',
        'Concurrentieprijzen',
        'Levertijden',
    ]);
});

it('stores the choice in a cookie and returns to the page', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    $this->from(route('admin.tools.delivery-times.index'))
        ->post(route('admin.menu-mode.update'), ['mode' => AdminMenu::SIMPLE])
        ->assertRedirect(route('admin.tools.delivery-times.index'))
        ->assertCookie(AdminMenu::cookieName(), AdminMenu::SIMPLE);

    $this->post(route('admin.menu-mode.update'), ['mode' => 'iets-anders'])
        ->assertSessionHasErrors('mode');
});

it('renders the simplified menu and the switch in the sidebar', function () {
    $this->actingAs(Admin::factory()->create(), 'admin');

    $this->withCookie(AdminMenu::cookieName(), AdminMenu::SIMPLE)
        ->get(route('admin.tools.delivery-times.index'))
        ->assertOk()
        ->assertSee('Concurrentieprijzen')
        ->assertSee('value="eenvoudig"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertDontSee('Bulk bewerken');

    $this->withCookie(AdminMenu::cookieName(), AdminMenu::FULL)
        ->get(route('admin.tools.delivery-times.index'))
        ->assertSee('Bulk bewerken');
});

it('still respects the permissions of the role', function () {
    $role = Role::factory()->create(['permission_type' => 'custom', 'permissions' => ['dashboard', 'catalog', 'catalog.products']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $role->id]), 'admin');

    expect(menuNames(AdminMenu::SIMPLE))->toBe(['Producten']);
});
