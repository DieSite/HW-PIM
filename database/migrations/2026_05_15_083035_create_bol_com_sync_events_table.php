<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bol_com_sync_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('bol_com_credential_id')->nullable();
            $table->string('step', 64);
            $table->string('status', 32);
            $table->string('message', 1024)->nullable();
            $table->string('customer_message', 1024)->nullable();
            $table->string('bol_process_id', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('bol_com_credential_id')->references('id')->on('bol_com_credentials')->onDelete('set null');

            $table->index(['product_id', 'created_at']);
            $table->index(['bol_process_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bol_com_sync_events');
    }
};
