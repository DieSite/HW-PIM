<?php

namespace App\Console\Commands;

use App\Services\ProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;

class CalculateMetOnderkleedPrices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:calculate-met-onderkleed-prices';

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
        $productRepository = app(ProductRepository::class);
        $productService = app(ProductService::class);

        $builder = $productRepository->where('values->common->onderkleed', '=', 'Met onderkleed');
        $count = $builder->count();
        $this->output->progressStart($count);

        $count = 0;

        $builder->chunk(100, function ($products) use ($productService, $count) {
            foreach ($products as $product) {
                $price = $productService->calculateMetOnderkleedPrice($product);
                if ($product->values['common']['prijs']['EUR'] != $price || ($count < 5000 && $count > 3200)) {
                    $values = $product->values;
                    $values['common']['prijs']['EUR'] = $price;
                    $product->values = $values;
                    $product->save();
                    Event::dispatch('catalog.product.update.after', $product);
                    $count++;
                }
                $this->output->progressAdvance();
            }
        });

        $this->output->progressFinish();
    }
}
