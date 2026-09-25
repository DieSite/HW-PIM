<?php

use App\Http\Controllers\Mcp\OAuthLoginController;
use App\Mcp\Servers\ProductsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| OAuth 2.1 (Passport) protects every server: clients discover the
| authorization server via /.well-known, register themselves, and send the
| admin through /mcp/login and the consent screen.
|
*/

Route::middleware('throttle:30,1')->group(fn () => Mcp::oauthRoutes());

Route::middleware('web')
    ->get('mcp/login', OAuthLoginController::class)
    ->name('mcp.oauth.login');

Mcp::web('/mcp/products', ProductsServer::class)
    ->middleware(['auth:api', 'scopes:mcp:use']);
