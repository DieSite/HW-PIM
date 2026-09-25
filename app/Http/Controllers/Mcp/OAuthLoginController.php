<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Login step of the OAuth authorization flow used by MCP clients.
 *
 * The admin login page itself overwrites `url.intended` with the previous
 * admin URL, which would drop the pending /oauth/authorize request. This page
 * shows the same form but leaves `url.intended` alone, so the admin's
 * login (admin.session.store) lands back on the consent screen.
 */
class OAuthLoginController extends Controller
{
    public function __invoke(): View|RedirectResponse
    {
        if (auth()->guard('admin')->check()) {
            return redirect()->intended(route('admin.dashboard.index'));
        }

        return view('admin::users.sessions.create');
    }
}
