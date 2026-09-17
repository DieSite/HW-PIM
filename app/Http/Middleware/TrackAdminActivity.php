<?php

namespace App\Http\Middleware;

use App\Monitor\ActiveAdmins;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records which admins are active, for the DieSite TV board's live counter.
 */
class TrackAdminActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if ($adminId = auth('admin')->id()) {
                ActiveAdmins::touch((int) $adminId);
            }
        } catch (\Throwable) {
        }

        return $response;
    }
}
