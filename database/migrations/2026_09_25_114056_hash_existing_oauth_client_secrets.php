<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * Passport 13 always verifies client secrets against a hash. Secrets created
     * under Passport 12 were stored in plain text, so hash them once; the command
     * skips secrets that are already hashed. Integrations keep their plain secret.
     */
    public function up(): void
    {
        Artisan::call('passport:hash', ['--force' => true]);
    }

    /**
     * Hashing cannot be undone.
     */
    public function down(): void {}
};
