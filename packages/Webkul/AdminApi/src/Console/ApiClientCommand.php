<?php

namespace Webkul\AdminApi\Console;

use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Webkul\AdminApi\Repositories\ClientRepository as AdminApiClientRepository;
use Laravel\Passport\Console\ClientCommand as Passport;
use Webkul\User\Repositories\AdminRepository;

class ApiClientCommand extends Passport
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'unopim:passport:client
            {--user_name= : The user Name the client should be assigned to }
            {--name= : The name of the client}
            {--provider= : The name of the user provider}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a client for issuing access tokens';

    public function __construct(
        protected AdminRepository $adminRepository,
        protected AdminApiClientRepository $adminApiClients
    )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(ClientRepository $clients): void
    {
        $client = $this->createAdminPasswordClient();

        if (! $client) {
            return;
        }

        $this->components->info('Password grant client created successfully.');
        $this->components->twoColumnDetail('Client ID', $client->getKey());
        $this->components->twoColumnDetail('Client Secret', $client->plainSecret);
        $this->components->warn('The client secret will not be shown again, so don\'t lose it!');
    }

    /**
     * Create a new password grant client.
     */
    protected function createAdminPasswordClient(): ?Client
    {
        $userName = $this->option('user_name') ?: $this->ask(
            'Which user Name should the client be assigned to?'
        );

        $user = $this->adminRepository->findByField('email', $userName)->first();
        if (! $user) {
            $this->error('User not found.');

            return null;
        }

        $name = $this->option('name') ?: $this->ask(
            'What should we name the password grant client?',
            config('app.name').' Password Grant Client'
        );

        $providers = array_keys(config('auth.providers'));

        $provider = $providers[0];

        return $this->adminApiClients->createPasswordGrantClientForAdmin($user->id, $name, $provider);
    }
}
