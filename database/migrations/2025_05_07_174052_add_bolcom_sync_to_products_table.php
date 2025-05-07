<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBolComSyncToProductsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('bol_com_sync')->default(false);
            $table->string('bol_com_reference')->nullable();
            $table->unsignedBigInteger('bol_com_credential_id')->nullable();

            $table->foreign('bol_com_credential_id')
                ->references('id')
                ->on('bol_com_credentials')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['bol_com_credential_id']);
            $table->dropColumn(['bol_com_sync', 'bol_com_reference', 'bol_com_credential_id']);
        });
    }
};
