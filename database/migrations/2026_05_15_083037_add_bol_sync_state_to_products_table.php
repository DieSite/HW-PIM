<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('bol_sync_state', 48)->nullable()->index();
            $table->timestamp('bol_sync_state_at')->nullable();
            $table->unsignedBigInteger('bol_last_event_id')->nullable();

            $table->foreign('bol_last_event_id')
                ->references('id')
                ->on('bol_com_sync_events')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['bol_last_event_id']);
            $table->dropColumn(['bol_sync_state', 'bol_sync_state_at', 'bol_last_event_id']);
        });
    }
};
