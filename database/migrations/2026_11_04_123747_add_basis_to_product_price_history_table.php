<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What set the price at the time of the row: a competitor (with every
     * shop at that lowest price), the adviesprijs or the discount floor.
     * Lets the next run log a change of basis or a shop joining the lowest
     * price even when our own price does not move.
     */
    public function up(): void
    {
        Schema::table('product_price_history', function (Blueprint $table) {
            $table->string('basis', 20)->nullable()->after('competitor_url');
            $table->json('basis_shops')->nullable()->after('basis');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_history', function (Blueprint $table) {
            $table->dropColumn(['basis', 'basis_shops']);
        });
    }
};
