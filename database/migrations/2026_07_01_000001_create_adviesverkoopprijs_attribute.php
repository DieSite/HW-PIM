<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the `adviesverkoopprijs` price attribute (the price ceiling).
     *
     * It mirrors the existing `prijs` attribute — same type/flags and the same
     * attribute-family group mappings — so it appears next to `prijs` in the
     * product form. The actual value backfill (copy prijs -> adviesverkoopprijs)
     * is done separately by `pricing:backfill-adviesverkoopprijs`.
     */
    public function up(): void
    {
        if (DB::table('attributes')->where('code', 'adviesverkoopprijs')->exists()) {
            return;
        }

        $prijs = DB::table('attributes')->where('code', 'prijs')->first();

        if (! $prijs) {
            // The custom `prijs` attribute is only present on installed instances
            // (it is not part of the installer seed). On a bare/fresh database
            // there is nothing to mirror, so skip — the pricing logic reads the
            // product `values` JSON, not this attribute row.
            return;
        }

        $now = now();

        $attributeId = DB::table('attributes')->insertGetId([
            'code'              => 'adviesverkoopprijs',
            'type'              => $prijs->type,
            'visible_on'        => $prijs->visible_on,
            'swatch_type'       => $prijs->swatch_type,
            'validation'        => $prijs->validation,
            'regex_pattern'     => $prijs->regex_pattern,
            'position'          => $prijs->position,
            'is_required'       => 0,
            'is_unique'         => 0,
            'value_per_locale'  => $prijs->value_per_locale,
            'value_per_channel' => $prijs->value_per_channel,
            'default_value'     => null,
            'enable_wysiwyg'    => 0,
            'usable_in_grid'    => $prijs->usable_in_grid,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        DB::table('attribute_translations')->insert([
            ['attribute_id' => $attributeId, 'locale' => 'en_US', 'name' => 'Adviesverkoopprijs'],
            ['attribute_id' => $attributeId, 'locale' => 'nl_NL', 'name' => 'Adviesverkoopprijs'],
        ]);

        // Replicate every group mapping that `prijs` has, placing the new
        // attribute directly after it within each group.
        $prijsMappings = DB::table('attribute_group_mappings')
            ->where('attribute_id', $prijs->id)
            ->get();

        foreach ($prijsMappings as $mapping) {
            DB::table('attribute_group_mappings')->insert([
                'attribute_id'              => $attributeId,
                'attribute_family_group_id' => $mapping->attribute_family_group_id,
                'position'                  => ($mapping->position ?? 0) + 1,
            ]);
        }
    }

    public function down(): void
    {
        $attributeId = DB::table('attributes')->where('code', 'adviesverkoopprijs')->value('id');

        if (! $attributeId) {
            return;
        }

        DB::table('attribute_group_mappings')->where('attribute_id', $attributeId)->delete();
        DB::table('attribute_translations')->where('attribute_id', $attributeId)->delete();
        DB::table('attributes')->where('id', $attributeId)->delete();
    }
};
