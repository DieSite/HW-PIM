<?php

namespace App\Console\Commands;

use App\Imports\ProductsImport;
use Illuminate\Console\Command;

class ImportProductsCommand extends Command
{
    protected $signature = 'import:products {file}';

    protected $description = 'Import products from Excel file';

    public function handle()
    {
        $this->output->title('Starting import');
        (new ProductsImport)->withOutput($this->output)->import($this->argument('file'));
        $this->output->success('Import successful');
    }
}
