<?php

namespace App\Console\Commands;

use App\Services\WooCommerce\WooCommerceAttributeSync;
use Illuminate\Console\Command;
use Throwable;

class SetupCushionWooCommerceAttributes extends Command
{
    protected $signature = 'woocommerce:setup-cushion-attributes {--dry-run : Only list the attributes without a WooCommerce mapping}';

    protected $description = 'Create the cushion variation attributes (config cushions.variation_attributes) in WooCommerce and map them';

    public function handle(WooCommerceAttributeSync $attributeSync): int
    {
        $failed = false;

        foreach ((array) config('cushions.variation_attributes', []) as $code) {
            $mapped = $attributeSync->isMapped($code);

            if ($mapped === null) {
                $this->error('No default WooCommerce credential is configured.');

                return self::FAILURE;
            }

            if ($mapped) {
                $this->line("{$code} is already mapped");

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would create {$code} ({$attributeSync->name($code)})");

                continue;
            }

            try {
                $this->info("{$code} → WooCommerce attribute {$attributeSync->ensure($code)}");
            } catch (Throwable $e) {
                report($e);
                $this->error("{$code}: {$e->getMessage()}");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
