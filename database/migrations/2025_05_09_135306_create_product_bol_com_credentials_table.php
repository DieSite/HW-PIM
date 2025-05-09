<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_bol_com_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('bol_com_credential_id');
            $table->string('delivery_code')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('bol_com_credential_id')->references('id')->on('bol_com_credentials')->onDelete('cascade');

            $table->unique(['product_id', 'bol_com_credential_id'], 'unique_product_credential');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('bol_com_sync')->default(false)->change();
            $table->dropForeign('products_bol_com_credential_id_foreign');
            $table->dropColumn(['bol_com_credential_id', 'bol_com_delivery_code', 'bol_com_reference']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('bol_com_credential_id')->nullable();
            $table->string('bol_com_delivery_code')->nullable();
            $table->string('bol_com_reference')->nullable();
        });

        Schema::dropIfExists('product_bol_com_credentials');
    }
};
