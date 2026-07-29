<?php

namespace App\Jobs;

use App\Imports\ProductsImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;
use App\Jobs\Middleware\DisconnectsIdleRedis;

class ImportProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /**
     * May run past the Redis idle timeout without issuing a Redis command of
     * its own, so the delete() that retires it on success would find a closed
     * socket. {@see DisconnectsIdleRedis}
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new DisconnectsIdleRedis()];
    }

    public function handle()
    {
        Excel::import(new ProductsImport(), $this->filePath);
    }

    public function failed(\Throwable $exception)
    {
        \Log::error('Import failed: '.$exception->getMessage());
    }
}
