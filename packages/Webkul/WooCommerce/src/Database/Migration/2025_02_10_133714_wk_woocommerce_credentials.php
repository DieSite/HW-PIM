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
        Schema::create('wk_woocommerce_credentials', function (Blueprint $table) {
            $table->id()->autoIncrement();
            $table->string('shopUrl')->unique();
            $table->string('consumerKey');
            $table->string('consumerSecret');
            $table->boolean('active')->default(false);
            $table->boolean('defaultSet')->default(false);
            $table->json('extras')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wk_woocommerce_credentials');
    }
};
