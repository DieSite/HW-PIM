<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The connected Dropbox account (at most one row), read and written through
 * App\Services\DropboxTokenStore. The refresh token is encrypted with the
 * app key.
 */
class DropboxConnection extends Model
{
    protected $fillable = [
        'refresh_token',
        'account_name',
        'account_email',
        'connected_by',
    ];

    protected $hidden = [
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
        ];
    }
}
