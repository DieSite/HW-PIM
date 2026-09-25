<?php

namespace Webkul\AdminApi\Models;

use Laravel\Passport\Client as PassportClient;
use Webkul\User\Models\AdminProxy;

class Client extends PassportClient
{
    /**
     * Get the admins.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admins()
    {
        return $this->belongsTo(AdminProxy::modelClass(), 'user_id');
    }
}
