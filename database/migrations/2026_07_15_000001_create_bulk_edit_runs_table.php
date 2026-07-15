<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_edit_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('target_attribute');
            $table->json('filters');
            $table->json('operation');
            $table->boolean('sync_woo')->default(false);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('changed_count')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_edit_runs');
    }
};
