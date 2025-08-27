<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Product\Repositories\ProductRepository;

class BoxRugSizes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:box-rug-sizes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    const SIZE_MAPPING = [
        '80 cm x 150 cm'  => '80 cm x 150 cm',
        '60 cm x 90 cm'   => '80 cm x 150 cm',
        '60 cm x 160 cm'  => '80 cm x 150 cm',
        '65 cm x 130 cm'  => '80 cm x 150 cm',
        '67 cm x 140 cm'  => '80 cm x 150 cm',
        '68 cm x 120 cm'  => '80 cm x 150 cm',
        '70 cm x 140 cm'  => '80 cm x 150 cm',
        '70 cm x 150 cm'  => '80 cm x 150 cm',
        '80 cm x 160 cm'  => '80 cm x 150 cm',
        '90 cm x 160 cm'  => '80 cm x 150 cm',
        '100 cm x 140 cm' => '80 cm x 150 cm',

        '120 cm x 170 cm' => '120 cm x 170 cm',
        '120 cm x 180 cm' => '120 cm x 170 cm',

        '140 cm x 200 cm' => '140 cm x 200 cm',
        '130 cm x 190 cm' => '140 cm x 200 cm',
        '130 cm x 200 cm' => '140 cm x 200 cm',
        '133 cm x 195 cm' => '140 cm x 200 cm',
        '135 cm x 200 cm' => '140 cm x 200 cm',
        '140 cm x 180 cm' => '140 cm x 200 cm',
        '150 cm x 200 cm' => '140 cm x 200 cm',

        '160 cm x 230 cm' => '160 cm x 230 cm',
        '155 cm x 230 cm' => '160 cm x 230 cm',
        '160 cm x 240 cm' => '160 cm x 230 cm',
        '170 cm x 230 cm' => '160 cm x 230 cm',
        '170 cm x 240 cm' => '160 cm x 230 cm',
        '185 cm x 230 cm' => '160 cm x 230 cm',

        '200 cm x 250 cm' => '200 cm x 250 cm',
        '180 cm x 250 cm' => '200 cm x 250 cm',
        '200 cm x 240 cm' => '200 cm x 250 cm',

        '200 cm x 300 cm' => '200 cm x 300 cm',
        '200 cm x 280 cm' => '200 cm x 300 cm',
        '200 cm x 290 cm' => '200 cm x 300 cm',

        '250 cm x 300 cm' => '250 cm x 300 cm',
        '240 cm x 300 cm' => '250 cm x 300 cm',

        '240 cm x 330 cm' => '240 cm x 330 cm',
        '230 cm x 330 cm' => '240 cm x 330 cm',
        '240 cm x 290 cm' => '240 cm x 330 cm',

        '250 cm x 350 cm' => '250 cm x 350 cm',
        '280 cm x 360 cm' => '250 cm x 350 cm',

        '300 cm x 400 cm' => '300 cm x 400 cm',
        '275 cm x 400 cm' => '300 cm x 400 cm',
        '280 cm x 380 cm' => '300 cm x 400 cm',
        '280 cm x 390 cm' => '300 cm x 400 cm',
        '290 cm x 390 cm' => '300 cm x 400 cm',
        '300 cm x 390 cm' => '300 cm x 400 cm',

        '350 cm x 400 cm' => '350 cm x 400 cm',
        '400 cm x 600 cm' => '350 cm x 400 cm',

        'Rond 80 cm'  => 'Rond 80 cm',
        'Rond 100 cm' => 'Rond 100 cm',
        'Rond 120 cm' => 'Rond 120 cm',
        'Rond 140 cm' => 'Rond 140 cm',
        'Rond 160 cm' => 'Rond 160 cm',
        'Rond 200 cm' => 'Rond 200 cm',
        'Rond 210 cm' => 'Rond 210 cm',
        'Rond 220 cm' => 'Rond 220 cm',
        'Rond 230 cm' => 'Rond 230 cm',
        'Rond 240 cm' => 'Rond 240 cm',
        'Rond 250 cm' => 'Rond 250 cm',
        'Rond 300 cm' => 'Rond 300 cm',
        'Rond 320 cm' => 'Rond 320 cm',

        'Maatwerk'      => 'Maatwerk',
        'Rond Maatwerk' => 'Rond Maatwerk',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $productRepository = app()->make(ProductRepository::class);

        $products = \App\Models\Product::where('type', 'simple')->get();

        $progressBar = $this->output->createProgressBar($products->count());

        foreach ($products as $product) {
            $_product = $productRepository->find($product->id);
            $values = $_product->values;
            if (!isset($values['common']['maat'])) {
                $this->info('No size found for product: ' . $product->sku);
                $progressBar->advance();
                continue;
            }
            $actualSize = $values['common']['maat'];

            $sizeGroup = self::SIZE_MAPPING[$actualSize] ?? 'Afwijkende afmetingen';
            $values['common']['maatgroep'] = $sizeGroup;

            $_product->values = $values;
            $_product->save();

            $progressBar->advance();
        }

        $progressBar->finish();
    }
}
