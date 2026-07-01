<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_sync_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->string('action', 64)->nullable();
            $table->string('status', 32);
            $table->string('message', 1024)->nullable();
            $table->string('customer_message', 1024)->nullable();
            $table->string('external_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index(['product_id', 'created_at']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_sync_events');
    }
};
