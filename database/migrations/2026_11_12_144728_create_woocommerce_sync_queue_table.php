<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products waiting for a WooCommerce sync. They wait here instead of as
     * queued jobs in Redis, whose memory cap a bulk resync used to exhaust.
     */
    public function up(): void
    {
        Schema::create('woocommerce_sync_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id')->unique();
            $table->json('next_product_ids')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->boolean('rerun_requested')->default(false);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->index(['claimed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_sync_queue');
    }
};
