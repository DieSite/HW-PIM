<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAdminMenuRequest;
use App\Services\AdminMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;

/**
 * Switches the sidebar between the full and the simplified menu for this
 * browser, then returns to the page the user was on.
 */
class AdminMenuController extends Controller
{
    public function update(UpdateAdminMenuRequest $request): RedirectResponse
    {
        return back()->withCookie(Cookie::forever(AdminMenu::cookieName(), $request->validated('mode')));
    }
}
