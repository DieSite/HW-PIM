<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Create the cushion variation attributes (kussenmaat, kleur, vulling) in
     * WooCommerce and map them, so the first cushion sync can use them.
     *
     * Production only: the default WooCommerce credential in a local copy of
     * the database points at the live shop, so running this anywhere else
     * would write to the real shop. Elsewhere, run
     * `woocommerce:setup-cushion-attributes` by hand against a test shop.
     *
     * A WooCommerce error does not fail the deploy: the command is idempotent
     * and can be run again.
     */
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        try {
            if (Artisan::call('woocommerce:setup-cushion-attributes') !== 0) {
                throw new RuntimeException('Cushion WooCommerce attributes not set up: '.Artisan::output());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The shop attributes stay: deleting them would strip them from every
     * product that uses them.
     */
    public function down(): void {}
};
