<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PullFromSFTP extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:pull-from-sftp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get eurogros voorraad file from SFTP server';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to pull from SFTP');
        $sftp = Storage::disk('sftp');
        $local = Storage::disk('local');
        $localPath = '/private/eurogros/Voorraad_Eurogros.csv';
        $remotePath = '/Voorraad/Voorraadlijst/Voorraad_Eurogros.csv';

        if ($sftp->exists($remotePath)) {
            $content = $sftp->get($remotePath);
            $local->put($localPath, $content);
            $this->info('File downloaded successfully');
        } else {
            $this->error('File not found on SFTP server');
        }
    }
}
