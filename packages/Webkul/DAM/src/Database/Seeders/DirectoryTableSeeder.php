<?php

namespace Webkul\DAM\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Directory;

/*
 * Directory table seeder.
 */
class DirectoryTableSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @param  array  $parameters
     * @return void
     */
    public function run()
    {
        $now = Carbon::now();

        if (Directory::find(1)) {
            return;
        }

        DB::table('dam_directories')->insert([
            [
                'id'         => '1',
                '_lft'       => '1',
                '_rgt'       => '14',
                'name'       => 'uploads',
                'parent_id'  => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $newDirectory = sprintf('%s/%s', Directory::ASSETS_DIRECTORY, 'uploads');

        if (! Storage::disk(Directory::ASSETS_DISK)->exists($newDirectory)) {
            Storage::disk(Directory::ASSETS_DISK)->makeDirectory($newDirectory);
        }
    }
}
