<?php

namespace App\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;

class ProductService
{
    public function copyStockValuesOnderkleed(Product $product, bool $withUpdatedEvent = true): void
    {
        if (is_null($product->parent)) {
            return;
        }

        $underrug = $product->values['common']['onderkleed'] ?? null;

        Log::debug("Underrug: $underrug");

        if (is_null($underrug)) {
            return;
        }

        $otherUnderrug = match ($underrug) {
            'Zonder onderkleed' => 'Met onderkleed',
            default             => 'Zonder onderkleed',
        };

        Log::debug("Underrug: $otherUnderrug");

        $size = $product->values['common']['maat'] ?? null;

        // Get other inverse of onderkleed

        $otherRug = $product->parent->variants()->where('values->common->maat', $size)->where('values->common->onderkleed', $otherUnderrug)->first();
        if (is_null($otherRug)) {
            return;
        }

        $stockEurogros = $product->values['common']['voorraad_eurogros'] ?? 0;
        $stockDeMunk = $product->values['common']['voorraad_5_korting_handmatig'] ?? 0;
        $stockHW = $product->values['common']['voorraad_hw_5_korting'] ?? 0;
        $stockSale = $product->values['common']['uitverkoop_15_korting'] ?? 0;

        $otherRugValues = $otherRug->values;
        $otherRugValues['common']['voorraad_eurogros'] = $stockEurogros;
        $otherRugValues['common']['voorraad_5_korting_handmatig'] = $stockDeMunk;
        $otherRugValues['common']['voorraad_hw_5_korting'] = $stockHW;
        $otherRugValues['common']['uitverkoop_15_korting'] = $stockSale;
        $otherRug->values = $otherRugValues;
        $saved = $otherRug->save();
        Log::debug("Saved: $saved");

        if ($withUpdatedEvent) {
            Event::dispatch('catalog.product.update.after', $otherRug);
        }
    }
}
