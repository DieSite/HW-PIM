<?php

namespace Webkul\WooCommerce\Console\Commands;

use Illuminate\Console\Command;

class WooCommerceInstaller extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'woocommerce-package:install';

    protected $description = 'Install the Woocommerce package';

    public function handle()
    {
        $this->info('Installing Unopim woocommerce connector...');

        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->call('migrate');
        }

        $this->call('vendor:publish', [
            '--tag' => 'woocommerce-config',
        ]);

        $this->info('Unopim Woocommerce connector installed successfully!');
    }
}
