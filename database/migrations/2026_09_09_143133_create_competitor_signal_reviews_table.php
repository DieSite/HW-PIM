<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menselijke oordelen over wat het dagrapport signaleert.
 *
 * Twee soorten, allebei geklikt vanuit de mail:
 *   rejected  – deze koppeling (sku + winkel) is fout; gooi hem weg en laat
 *               hem niet terugkomen. Zonder dat laatste maakt de scraper hem
 *               morgennacht gewoon opnieuw aan en stond de knop voor niets.
 *   confirmed – dit signaal klopt niet als probleem; niet meer tonen. `shop`
 *               is dan leeg: het oordeel gaat over het kleed, niet over één
 *               winkel.
 *
 * `shop` is NOT NULL met '' als "geen winkel", zodat de unieke sleutel werkt —
 * in MySQL botsen NULLs niet met elkaar en zou je hetzelfde oordeel eindeloos
 * kunnen dupliceren.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_signal_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('sku');
            $table->string('shop')->default('');
            $table->string('verdict', 16);
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->unique(['sku', 'shop']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_signal_reviews');
    }
};
