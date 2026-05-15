<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bol_com_credentials', function (Blueprint $table) {
            $table->string('economic_operator_id', 64)->nullable();
            $table->string('economic_operator_name', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bol_com_credentials', function (Blueprint $table) {
            $table->dropColumn(['economic_operator_id', 'economic_operator_name']);
        });
    }
};
