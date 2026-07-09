<?php

namespace App\Console\Commands;

use App\Jobs\ImportVoorraadDeMunkJob;
use Illuminate\Console\Command;

class ImportDeMunkVoorraadCommand extends Command
{
    protected $signature = 'import:demunk';

    protected $description = 'Read De Munk stock from the dealer portal and sync it onto linked PIM products';

    public function handle(): int
    {
        ImportVoorraadDeMunkJob::dispatch();

        $this->info('De Munk voorraad import gestart.');

        return self::SUCCESS;
    }
}
