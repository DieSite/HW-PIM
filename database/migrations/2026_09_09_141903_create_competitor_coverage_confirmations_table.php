<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kleden waarvan iemand heeft bevestigd dat geen enkele concurrent ze voert.
 *
 * Het rapportblok "kleden zonder enige concurrentprijs" telt er duizenden en
 * verandert nauwelijks, dus zonder zo'n bevestiging staat elke nacht dezelfde
 * lijst in de mail en wordt hij niet meer gelezen. Eén klik in het rapport zet
 * een kleed hier neer en dan is het blok voortaan alleen nog wat er nieuw of
 * nog onbekeken is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_coverage_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->timestamp('confirmed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_coverage_confirmations');
    }
};
