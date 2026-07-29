<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the `extra_korting` attribute: a per-rug discount percentage
     * applied on top of the price the competitor logic computes.
     *
     * It mirrors `uitverkoop_15_korting` — the existing per-variant discount
     * field — so it lands in the same attribute-family groups and shows up
     * next to it in the product form. Read by
     * App\Services\CompetitorPricingService::recompute().
     */
    public function up(): void
    {
        if (DB::table('attributes')->where('code', 'extra_korting')->exists()) {
            return;
        }

        $sibling = DB::table('attributes')->where('code', 'uitverkoop_15_korting')->first();

        if (! $sibling) {
            /**
             * The custom discount attributes only exist on installed instances
             * (they are not part of the installer seed). On a bare database
             * there is nothing to mirror, and the pricing logic reads the
             * product `values` JSON rather than this attribute row, so
             * skipping keeps a fresh install working.
             */
            return;
        }

        $now = now();

        $attributeId = DB::table('attributes')->insertGetId([
            'code'              => 'extra_korting',
            'type'              => $sibling->type,
            'visible_on'        => $sibling->visible_on,
            'swatch_type'       => $sibling->swatch_type,
            'validation'        => $sibling->validation,
            'regex_pattern'     => $sibling->regex_pattern,
            'position'          => $sibling->position,
            'is_required'       => 0,
            'is_unique'         => 0,
            'value_per_locale'  => $sibling->value_per_locale,
            'value_per_channel' => $sibling->value_per_channel,
            'default_value'     => null,
            'enable_wysiwyg'    => 0,
            'usable_in_grid'    => $sibling->usable_in_grid,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        DB::table('attribute_translations')->insert([
            ['attribute_id' => $attributeId, 'locale' => 'en_US', 'name' => 'Extra korting (%)'],
            ['attribute_id' => $attributeId, 'locale' => 'nl_NL', 'name' => 'Extra korting (%)'],
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
        $attributeId = DB::table('attributes')->where('code', 'extra_korting')->value('id');

        if (! $attributeId) {
            return;
        }

        DB::table('attribute_group_mappings')->where('attribute_id', $attributeId)->delete();
        DB::table('attribute_translations')->where('attribute_id', $attributeId)->delete();
        DB::table('attributes')->where('id', $attributeId)->delete();
    }
};
