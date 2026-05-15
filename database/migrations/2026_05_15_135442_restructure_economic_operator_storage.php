<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The earlier migration added these per-credential columns. We're moving
        // to a per-product configuration with a per-brand default, so drop them.
        Schema::table('bol_com_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('bol_com_credentials', 'economic_operator_id')) {
                $table->dropColumn(['economic_operator_id', 'economic_operator_name']);
            }
        });

        // Local cache of economic operators that have been registered with Bol.
        // Bol assigns the UUID (`bol_operator_id`); we copy the canonical fields
        // so the admin can show a picker without hitting the API on every page.
        Schema::create('bol_economic_operators', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bol_com_credential_id');
            $table->string('bol_operator_id', 64);
            $table->string('name', 255);
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 32)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('bol_com_credential_id')->references('id')->on('bol_com_credentials')->cascadeOnDelete();
            $table->unique(['bol_com_credential_id', 'bol_operator_id'], 'bol_eco_op_cred_op_unique');
        });

        // Per-product override. Stores Bol's UUID directly — no foreign key,
        // because credentials/operators may be re-registered.
        Schema::table('products', function (Blueprint $table) {
            $table->string('bol_economic_operator_id', 64)->nullable()->index();
        });

        // Brand -> default operator mapping. The product picks this up when its
        // own override is null. Brand is the free-text `values.common.merk`
        // value from the parent product, which is why it's a string key.
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

    public function down(): void
    {
        Schema::dropIfExists('bol_brand_economic_operators');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('bol_economic_operator_id');
        });
        Schema::dropIfExists('bol_economic_operators');
        Schema::table('bol_com_credentials', function (Blueprint $table) {
            $table->string('economic_operator_id', 64)->nullable();
            $table->string('economic_operator_name', 255)->nullable();
        });
    }
};
