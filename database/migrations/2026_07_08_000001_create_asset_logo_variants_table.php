<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_logo_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_asset_id')->unique()->constrained('dam_assets')->cascadeOnDelete();
            $table->foreignId('variant_asset_id')->index()->constrained('dam_assets')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_logo_variants');
    }
};
