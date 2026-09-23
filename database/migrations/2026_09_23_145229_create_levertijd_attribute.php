<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the `levertijd` attribute: the delivery time of one variant,
     * e.g. "2 tot 3 dagen". It is computed from the brand rules on
     * Tools → Levertijden and the variant's stock by
     * App\Services\DeliveryTimeService, and sent to WooCommerce as the
     * variation's `levertijd` meta.
     *
     * It mirrors `voorraad_hw_5_korting` so it shows up in the Voorraad group
     * of the product form, next to the stock it is derived from.
     */
    public function up(): void
    {
        if (DB::table('attributes')->where('code', 'levertijd')->exists()) {
            return;
        }

        $sibling = DB::table('attributes')->where('code', 'voorraad_hw_5_korting')->first();

        if (! $sibling) {
            /**
             * The stock attributes only exist on installed instances (they
             * are not part of the installer seed). The service reads and
             * writes the product `values` JSON rather than this attribute
             * row, so skipping keeps a fresh install working.
             */
            return;
        }

        $now = now();

        $attributeId = DB::table('attributes')->insertGetId([
            'code'              => 'levertijd',
            'type'              => 'text',
            'visible_on'        => $sibling->visible_on,
            'swatch_type'       => $sibling->swatch_type,
            'validation'        => null,
            'regex_pattern'     => null,
            'position'          => $sibling->position,
            'is_required'       => 0,
            'is_unique'         => 0,
            'value_per_locale'  => 0,
            'value_per_channel' => 0,
            'default_value'     => null,
            'enable_wysiwyg'    => 0,
            'usable_in_grid'    => $sibling->usable_in_grid,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        DB::table('attribute_translations')->insert([
            ['attribute_id' => $attributeId, 'locale' => 'en_US', 'name' => 'Levertijd (automatisch)'],
            ['attribute_id' => $attributeId, 'locale' => 'nl_NL', 'name' => 'Levertijd (automatisch)'],
        ]);

        $mappings = DB::table('attribute_group_mappings')
            ->where('attribute_id', $sibling->id)
            ->get();

        foreach ($mappings as $mapping) {
            DB::table('attribute_group_mappings')->insert([
                'attribute_id'              => $attributeId,
                'attribute_family_group_id' => $mapping->attribute_family_group_id,
                'position'                  => ($mapping->position ?? 0) + 1,
            ]);
        }
    }

    public function down(): void
    {
        $attributeId = DB::table('attributes')->where('code', 'levertijd')->value('id');

        if (! $attributeId) {
            return;
        }

        DB::table('attribute_group_mappings')->where('attribute_id', $attributeId)->delete();
        DB::table('attribute_translations')->where('attribute_id', $attributeId)->delete();
        DB::table('attributes')->where('id', $attributeId)->delete();
    }
};
