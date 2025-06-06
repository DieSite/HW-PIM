<?php

namespace Webkul\WooCommerce\Repositories;

use Webkul\Core\Eloquent\Repository;
use Webkul\WooCommerce\Contracts\Credential;

class CredentialRepository extends Repository
{
    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Credential::class;
    }

    public function resetDefaultSet($excludedId)
    {
        $this->model->where('id', '!=', $excludedId)->update(['defaultSet' => 0]);
    }
}
