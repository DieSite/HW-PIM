<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('wk_woocommerce_data_transfer_mappings', function (Blueprint $table) {
            $table->string('apiUrl')->after('credentialId');
        });

        DB::statement('UPDATE wk_woocommerce_data_transfer_mappings AS dtm
                        JOIN wk_woocommerce_credentials AS wc 
                        ON dtm.credentialId = wc.id 
                        SET dtm.apiUrl = wc.shopUrl');

        Schema::table('wk_woocommerce_data_transfer_mappings', function (Blueprint $table) {
            $table->dropColumn('credentialId');
        });
    }

    public function down() {}
};
