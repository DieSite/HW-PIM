<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A log of competitor couplings that disappeared.
 *
 * `competitor_prices` is a live snapshot: the nightly `--prune` deletes what
 * the scrape no longer reports, and with it the only evidence that we ever had
 * a price there. That is the one event the report could not show — "dit kleed
 * vinden we niet meer bij de concurrent" — while it is exactly the event that
 * pushes a selling price back up to the adviesprijs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_price_removals', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->index();
            $table->string('shop');
            $table->decimal('price', 12, 2)->nullable();
            $table->text('url')->nullable();
            $table->timestamp('scraped_at')->nullable();
            $table->timestamp('removed_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_price_removals');
    }
};
