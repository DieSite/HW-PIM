<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wk_woocommerce_data_transfer_mappings', function (Blueprint $table) {
            $table->index(['entityType', 'code', 'apiUrl'], 'wc_mappings_entity_code_url_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wk_woocommerce_data_transfer_mappings', function (Blueprint $table) {
            $table->dropIndex('wc_mappings_entity_code_url_index');
        });
    }
};
