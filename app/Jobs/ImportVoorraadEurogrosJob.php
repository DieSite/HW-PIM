<?php

namespace App\Jobs;

use App\Imports\EurogrosVoorraadImport;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Sentry;
use Throwable;

class ImportVoorraadEurogrosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * With failOnTimeout and maxExceptions = 1, the second attempt is reached
     * exclusively when the first attempt died silently (worker killed on
     * deploy/restart, OOM) and the reservation expired: a genuine timeout or
     * exception still fails immediately. The import overwrites, so a re-run
     * is safe.
     */
    public int $tries = 2;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 1;

    protected $path = 'private/eurogros/Voorraad_Eurogros.csv';

    /**
     * expireAfter must stay at or below the connection's retry_after: the
     * silent-death retry arrives exactly retry_after seconds after dispatch,
     * and an unexpired lock combined with dontRelease() would swallow that
     * retry without a trace.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('import-eurogros-voorraad'))->expireAfter($this->timeout)->dontRelease()];
    }

    public function handle(): void
    {
        Log::info('ImportVoorraadEurogrosJob: starting (attempt '.$this->attempts().')');

        try {
            if (! $this->pullFromSftp()) {
                throw new RuntimeException('Remote file not found on SFTP: /Voorraad/Voorraadlijst/Voorraad_Eurogros.csv');
            }

            $this->importData();
        } catch (Throwable $e) {
            Sentry::withScope(function ($scope) use ($e): void {
                $scope->setContext('eurogros_import', [
                    'attempt'     => $this->attempts(),
                    'remote_path' => '/Voorraad/Voorraadlijst/Voorraad_Eurogros.csv',
                    'local_path'  => $this->path,
                ]);
                Sentry::captureException($e);
            });

            throw $e;
        }

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
            $startedAt = CarbonImmutable::now();
            (new EurogrosVoorraadImport())->queue($fullPath)->chain([
                new NotifyMissingEurogrosEansJob($startedAt),
            ]);
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
