<?php

namespace Webkul\AdminApi\Repositories;

use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository as BaseClientRepository;
use Laravel\Passport\Passport;

class ClientRepository extends BaseClientRepository
{
    /**
     * Get a client by the given ID.
     *
     * When the request carries a `username` (password grant), the client is only
     * returned if it belongs to that admin.
     */
    public function find(string|int $id): ?Client
    {
        $client = Passport::client();

        $client = $client->where($client->getKeyName(), $id)->first();
        if (request()->has('username')) {
            $username = request()->get('username');
            $user = $client?->admins()->where('email', $username)->get()->first();

            if (! $user) {
                $client = null;
            }
        }

        return $client;
    }

    /**
     * Get an active client by the given ID.
     */
    public function findActive(string|int $id): ?Client
    {
        $client = $this->find($id);

        return $client && ! $client->revoked ? $client : null;
    }

    /**
     * Create a confidential password grant client owned by the given admin.
     */
    public function createPasswordGrantClientForAdmin(int $adminId, string $name, ?string $provider = null): Client
    {
        $client = $this->createPasswordGrantClient($name, $provider, confidential: true);

        $client->forceFill(['user_id' => $adminId])->save();

        return $client;
    }
}
