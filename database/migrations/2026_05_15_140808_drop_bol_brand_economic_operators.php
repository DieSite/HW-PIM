<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bol_brand_economic_operators');
    }

    public function down(): void
    {
        Schema::create('bol_brand_economic_operators', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 255);
            $table->unsignedBigInteger('bol_com_credential_id');
            $table->string('bol_operator_id', 64);
            $table->timestamps();

            $table->foreign('bol_com_credential_id')->references('id')->on('bol_com_credentials')->cascadeOnDelete();
            $table->unique(['brand', 'bol_com_credential_id'], 'bol_brand_op_brand_cred_unique');
        });
    }
};
