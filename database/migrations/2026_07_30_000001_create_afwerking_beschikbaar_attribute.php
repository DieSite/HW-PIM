<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Options of the `afwerking_beschikbaar` select, in display order.
     *
     * @var array<string, string>
     */
    private const OPTIONS = [
        'automatisch' => 'Automatisch (op basis van merk)',
        'ja'          => 'Ja, altijd',
        'nee'         => 'Nee, nooit',
    ];

    /**
     * Create the `afwerking_beschikbaar` attribute: the manual override for
     * whether a rug offers finishing options (festonneren, banderen, ...).
     *
     * Empty behaves as `automatisch`, which derives availability from the brand
     * and whether the product has a maatwerk variant. `ja` overrules the brand
     * check, `nee` switches finishings off for that one rug. Read by
     * App\Services\AfwerkingOptieService::isBeschikbaar().
     *
     * It mirrors `minimale_prijs` — a maatwerk-only field — so it lands in the
     * same `maatwerk_kleden` groups of both attribute families.
     */
    public function up(): void
    {
        if (DB::table('attributes')->where('code', 'afwerking_beschikbaar')->exists()) {
            return;
        }

        $sibling = DB::table('attributes')->where('code', 'minimale_prijs')->first();

        if (! $sibling) {
            /**
             * The maatwerk attributes only exist on installed instances (they
             * are not part of the installer seed). On a bare database there is
             * nothing to mirror, and AfwerkingOptieService reads the product
             * `values` JSON rather than this attribute row, so skipping keeps a
             * fresh install working.
             */
            return;
        }

        $now = now();

        $attributeId = DB::table('attributes')->insertGetId([
            'code'              => 'afwerking_beschikbaar',
            'type'              => 'select',
            'visible_on'        => $sibling->visible_on,
            'swatch_type'       => null,
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
            ['attribute_id' => $attributeId, 'locale' => 'en_US', 'name' => 'Afwerkingen beschikbaar'],
            ['attribute_id' => $attributeId, 'locale' => 'nl_NL', 'name' => 'Afwerkingen beschikbaar'],
        ]);

        $sortOrder = 0;

        foreach (self::OPTIONS as $code => $label) {
            $optionId = DB::table('attribute_options')->insertGetId([
                'attribute_id' => $attributeId,
                'code'         => $code,
                'sort_order'   => $sortOrder++,
            ]);

            DB::table('attribute_option_translations')->insert([
                ['attribute_option_id' => $optionId, 'locale' => 'en_US', 'label' => $label],
                ['attribute_option_id' => $optionId, 'locale' => 'nl_NL', 'label' => $label],
            ]);
        }

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
        $attributeId = DB::table('attributes')->where('code', 'afwerking_beschikbaar')->value('id');

        if (! $attributeId) {
            return;
        }

        $optionIds = DB::table('attribute_options')->where('attribute_id', $attributeId)->pluck('id');

        DB::table('attribute_option_translations')->whereIn('attribute_option_id', $optionIds)->delete();
        DB::table('attribute_options')->where('attribute_id', $attributeId)->delete();
        DB::table('attribute_group_mappings')->where('attribute_id', $attributeId)->delete();
        DB::table('attribute_translations')->where('attribute_id', $attributeId)->delete();
        DB::table('attributes')->where('id', $attributeId)->delete();
    }
};
