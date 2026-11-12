<?php

namespace Database\Factories;

use App\Models\WooCommerceSyncQueueEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Webkul\Product\Models\Product;

/**
 * @extends Factory<WooCommerceSyncQueueEntry>
 */
class WooCommerceSyncQueueEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id'       => Product::factory()->simple(),
            'next_product_ids' => [],
            'claimed_at'       => null,
            'rerun_requested'  => false,
        ];
    }

    public function claimed(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn (): array => ['claimed_at' => $at ?? now()]);
    }
}
