<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Latest scraped competitor price per (SKU, shop). This is the current
     * snapshot used to compute the lowest competitor price and to detect
     * changes between imports for the price-history reason.
     */
    public function up(): void
    {
        Schema::create('competitor_prices', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->index();
            $table->unsignedInteger('product_id')->nullable();
            $table->string('shop');
            $table->decimal('price', 10, 2);
            $table->text('url')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['sku', 'shop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_prices');
    }
};
