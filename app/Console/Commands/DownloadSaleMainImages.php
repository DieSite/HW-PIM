<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DownloadSaleMainImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:download-sale-main-images';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $products = Product::whereExists(function ($query) {
            $query->selectRaw('1')
                ->from('products as p2')
                ->whereColumn('p2.parent_id', 'products.id')
                ->where('p2.values->common->voorraad_eurogros', '>', 0)
                ->orWhere('p2.values->common->voorraad_5_korting_handmatig', '>', 0)
                ->orWhere('p2.values->common->voorraad_hw_5_korting', '>', 0)
                ->orWhere('p2.values->common->uitverkoop_15_korting', '>', 0);
        })->get();

        $progressBar = $this->output->createProgressBar(count($products));
        foreach ($products as $product) {
            $progressBar->advance();
            $images = json_decode($product->values, true)['common']['afbeelding'];
            $images = explode(',', $images);
            if (count($images) === 0) {
                continue;
            }

            $mainImage = $images[0];

            $damAsset = \DB::table('dam_assets')->where('id', $mainImage)->first();

            if ($damAsset && ! empty($damAsset->path)) {
                $image = $damAsset->path;
            }

            $imageData = Storage::disk('private')->get($image);
            $fileName = basename($image);
            Storage::disk('local')->put("stock_products/$fileName", $imageData);
        }

        $progressBar->finish();
    }
}
