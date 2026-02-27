<?php

namespace App\Jobs;

use App\Imports\EurogrosVoorraadImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ImportVoorraadEurogrosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $path = 'private/eurogros/Voorraad_Eurogros.csv';

    public function handle(): void
    {
        if (! $this->pullFromSftp()) {
            return;
        }

        $this->importData();
    }

    private function pullFromSftp(): bool
    {
        $sftp = Storage::disk('sftp');
        $local = Storage::disk('local');
        $remotePath = '/Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';

        if ($sftp->exists($remotePath)) {
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

    public function tags(): array
    {
        return [self::class];
    }
}
