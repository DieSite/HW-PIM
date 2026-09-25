<?php

namespace App\Mcp;

use Webkul\User\Models\Admin;

/**
 * The admin ACL check for MCP calls. Admin::hasPermission() fails on roles
 * with permission_type "all", whose permissions list is empty.
 */
class AdminPermission
{
    public static function allows(?Admin $admin, string $permission): bool
    {
        $role = $admin?->role;

        if ($role === null) {
            return false;
        }

        return $role->permission_type === 'all'
            || in_array($permission, (array) $role->permissions, true);
    }
}
