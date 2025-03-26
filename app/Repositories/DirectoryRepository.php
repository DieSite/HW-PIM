<?php

namespace App\Repositories;

use App\Models\Directory;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Directory as UnoPimDirectory;

class DirectoryRepository extends \Webkul\DAM\Repositories\DirectoryRepository
{
    protected $copyDirectory;

    /**
     * Specify model class name.
     */
    public function model(): string
    {
        return Directory::class;
    }

    // Method to find a directory with its children
    public function findWithChildren($id)
    {
        return Directory::with('children')->find($id);
    }

    /**
     * Create a directory with storage
     */
    public function createDirectoryWithStorage($newPath, $oldPath = null)
    {
        try {
            $newDirectory = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $newPath);

            if (! $oldPath) {
                Storage::disk(Directory::ASSETS_DISK)->makeDirectory($newDirectory);

                return;
            }

            $oldDirectory = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $oldPath);
            // Check if a directory exists
            if (Storage::disk(Directory::ASSETS_DISK)->exists($oldDirectory)) {
                Storage::disk(Directory::ASSETS_DISK)->move($oldDirectory, $newDirectory);
            } else {
                Storage::disk(Directory::ASSETS_DISK)->makeDirectory($newDirectory);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Delete a directory from storage
     */
    public function deleteDirectoryWithStorage($path)
    {
        $directory = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $path);

        if (Storage::disk(Directory::ASSETS_DISK)->exists($directory)) {
            Storage::disk(Directory::ASSETS_DISK)->deleteDirectory($directory);
        }
    }

    /**
     * Copy a directory with storage
     */
    public function copyDirectoryWithStorage($newPath, $oldPath)
    {
        $sourcePath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $oldPath);
        $destinationPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $newPath);
        if (Storage::disk(Directory::ASSETS_DISK)->exists($sourcePath)) {

        }
    }

    /**
     * Specify directory tree.
     *
     * @param  int  $id
     * @return UnoPimDirectory
     */
    public function getDirectoryTree($id = null)
    {
        return $id
            ? $this->model->where('id', '=', $id)->with(['assets', 'assets.directories'])->get()->toTree()
            : $this->model->with(['assets', 'assets.directories'])->get()->toTree();
    }

    /**
     * Check if a directory is writable in the file system.
     */
    public function isDirectoryWritable(UnoPimDirectory $directory, string $actionType = 'create', bool $hasParent = true): bool
    {
        $directoryPath = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, $hasParent ? $directory->generatePath() : '');

        if (! $directory->isWritable($directoryPath)) {
            throw new \Exception(trans('dam::app.admin.dam.index.directory.not-writable', [
                'type'       => 'directory',
                'actionType' => $actionType,
                'path'       => $directoryPath,
            ]));
        }

        return true;
    }
}
