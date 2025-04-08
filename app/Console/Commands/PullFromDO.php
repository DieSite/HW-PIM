<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Asset;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\DirectoryRepository;

class PullFromDO extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:pull-from-do';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private Filesystem $storage;

    private DirectoryRepository $repository;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->storage = Storage::disk('private');
        $this->repository = app(DirectoryRepository::class);

        $directory = $this->repository->find(1);
        $this->fetchFiles($directory);
        $this->pullSubDirectories($directory);
    }

    private function pullSubDirectories(Directory $parent): void
    {
        $parentPath = $parent->generatePath();
        if (! str_contains($parentPath, Directory::ASSETS_DIRECTORY)) {
            $parentPath = Directory::ASSETS_DIRECTORY.'/'.$parentPath;
        }
        if (! str_ends_with($parentPath, $parent->name)) {
            $parentPath .= "/$parent->name";
        }
        $parentPath = str_replace('//', '/', $parentPath);
        $this->info("Checking path $parentPath");
        $subDirectories = $this->storage->directories($parentPath);

        foreach ($subDirectories as $subDirectory) {
            $pathParts = explode('/', $subDirectory);
            $name = last($pathParts);

            $directoryModel = $this->repository->create([
                'name'      => $name,
                'parent_id' => $parent->id,
            ]);

            $this->info("Created directory $subDirectory");

            $this->pullSubDirectories($directoryModel);
        }
    }

    private function fetchFiles(Directory $directory)
    {
        $parentPath = $directory->generatePath();
        if (! str_contains($parentPath, Directory::ASSETS_DIRECTORY)) {
            $parentPath = Directory::ASSETS_DIRECTORY.'/'.$parentPath;
        }
        if (! str_ends_with($parentPath, $directory->name)) {
            $parentPath .= "/$directory->name";
        }
        $parentPath = str_replace('//', '/', $parentPath);
        $this->info("Fetching files for path $parentPath");

        $filesPath = $this->storage->files($parentPath);
        $assetIds = [];
        $this->withProgressBar($filesPath, function ($filePath) use (&$assetIds) {
            $info = pathinfo($filePath);
            $asset = Asset::createOrFirst([
                'path' => $filePath,
            ], [
                'file_name' => $info['filename'],
                'file_type' => $this->getFileType($filePath),
                'file_size' => $this->storage->size($filePath),
                'mime_type' => $this->storage->mimeType($filePath),
                'extension' => \File::extension($filePath),
            ]);
            $assetIds[] = $asset->id;
        });

        $this->mappedWithDirectory($assetIds, $directory);
    }

    private function getFileType($filePath): string
    {
        $mimeType = $this->storage->mimeType($filePath);

        if (str_contains($mimeType, 'image')) {
            return 'image';
        } elseif (str_contains($mimeType, 'video')) {
            return 'video';
        } elseif (str_contains($mimeType, 'audio')) {
            return 'audio';
        } else {
            return 'document';
        }
    }

    protected function mappedWithDirectory($assetIds, $directory): ?Directory
    {
        if (! $directory) {
            return null;
        }

        $directory->assets()->attach($assetIds);

        return $directory;
    }
}
