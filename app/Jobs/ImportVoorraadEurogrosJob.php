<?php

namespace App\Jobs;

use App\Imports\EurogrosVoorraadImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Sentry;
use Throwable;

class ImportVoorraadEurogrosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $path = 'private/eurogros/Voorraad_Eurogros.csv';

    public function handle(): void
    {
        Log::info('ImportVoorraadEurogrosJob: starting (attempt '.$this->attempts().')');

        if (! $this->pullFromSftp()) {
            Log::warning('ImportVoorraadEurogrosJob: remote file not found on SFTP, aborting');
            $this->fail();
            return;
        }

        $this->importData();

        Log::info('ImportVoorraadEurogrosJob: completed successfully');
    }

    public function failed(Throwable $exception): void
    {
        Sentry::captureException($exception);
        Log::error('ImportVoorraadEurogrosJob: permanently failed', [
            'exception' => $exception->getMessage(),
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'trace'     => $exception->getTraceAsString(),
        ]);
    }

    public function tags(): array
    {
        return [self::class];
    }

    private function pullFromSftp(): bool
    {
        Log::info('ImportVoorraadEurogrosJob: connecting to SFTP');

        $sftp = Storage::disk('sftp');
        $local = Storage::disk('local');
        $remotePath = '/Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';

        if ($sftp->exists($remotePath)) {
            Log::info('ImportVoorraadEurogrosJob: downloading file from SFTP');
            $content = $sftp->get($remotePath);
            $local->put($this->path, $content);

            return true;
        }

        return false;
    }

    private function importData(): void
    {
        $fullPath = storage_path('app/'.$this->path);

        if (file_exists($fullPath)) {
            Log::info('ImportVoorraadEurogrosJob: queuing import');
            (new EurogrosVoorraadImport())->queue($fullPath);
        }
    }

    private function cleanupFile(): void
    {
        $local = Storage::disk('local');

        if ($local->exists($this->path)) {
            $local->delete($this->path);
        }
    }
}
