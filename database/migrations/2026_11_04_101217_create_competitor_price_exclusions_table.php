<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A competitor price someone took out of the analysis. It is tied to the
     * exact amount: once the shop shows a different price for the size, the
     * exclusion no longer matches and the new price counts again.
     */
    public function up(): void
    {
        Schema::create('competitor_price_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 191);
            $table->string('shop', 191);
            $table->decimal('price', 10, 2);
            $table->string('reason', 500)->nullable();
            $table->unsignedInteger('admin_id')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'shop', 'price']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_price_exclusions');
    }
};
