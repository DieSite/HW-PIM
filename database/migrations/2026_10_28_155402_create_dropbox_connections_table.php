<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Dropbox account connected under Tools → Dropbox (at most one row).
     * The refresh token lived in Redis before, where a flush of Redis on
     * production silently disconnected Dropbox; only the short-lived access
     * token is still kept there (App\Services\DropboxTokenStore).
     */
    public function up(): void
    {
        Schema::create('dropbox_connections', function (Blueprint $table) {
            $table->id();
            $table->text('refresh_token');
            $table->string('account_name')->nullable();
            $table->string('account_email')->nullable();
            $table->string('connected_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dropbox_connections');
    }
};
