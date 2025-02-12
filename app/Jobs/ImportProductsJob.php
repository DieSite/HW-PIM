<?php

namespace App\Jobs;

use App\Imports\ProductsImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ImportProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 3; // Maximum number of attempts
    protected $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function handle()
    {
        ini_set('memory_limit', '756M'); // Increase memory limit if needed
        set_time_limit(3600); // Increase PHP timeout

        Excel::import(new ProductsImport, $this->filePath);
    }

    public function failed(\Throwable $exception)
    {
        // Log the failure or notify someone
        \Log::error('Import failed: ' . $exception->getMessage());
    }
}
