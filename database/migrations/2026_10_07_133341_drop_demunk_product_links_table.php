<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('demunk_product_links');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('demunk_product_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->string('demunk_collectie')->nullable();
            $table->string('demunk_kwaliteit')->nullable();
            $table->string('demunk_kleur')->nullable();
            $table->string('demunk_vorm')->nullable();
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->enum('source', ['auto', 'manual'])->default('auto');
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->unique('product_id');
            $table->index(['demunk_collectie', 'demunk_kwaliteit', 'demunk_kleur'], 'demunk_links_article_index');

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }
};
