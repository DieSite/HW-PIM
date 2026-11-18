<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The new cushion attributes. The three selects are the variant axes of a
     * cushion (size × colour × filling); option codes double as labels, like
     * the existing `onderkleed` and `maatgroep` options, because the
     * WooCommerce export sends the stored code to the shop as-is.
     *
     * @var array<string, array{name: string, type: string, options?: list<string>}>
     */
    private const ATTRIBUTES = [
        'kussenmaat' => [
            'name'    => 'Maat kussen',
            'type'    => 'select',
            'options' => ['30 x 50 cm', '40 x 40 cm', '40 x 60 cm', '45 x 45 cm', '50 x 50 cm', '60 x 60 cm'],
        ],
        'kleur' => [
            'name'    => 'Kleur',
            'type'    => 'select',
            'options' => [
                'Wit', 'Ecru', 'Beige', 'Taupe', 'Grijs', 'Antraciet', 'Zwart', 'Bruin', 'Cognac',
                'Terra', 'Oker', 'Geel', 'Roze', 'Rood', 'Groen', 'Blauw', 'Multicolor',
            ],
        ],
        'vulling' => [
            'name'    => 'Vulling',
            'type'    => 'select',
            'options' => ['Zonder vulling', 'Met vulling'],
        ],
        'vulling_materiaal' => ['name' => 'Materiaal vulling', 'type' => 'text'],
        'sluiting'          => ['name' => 'Sluiting', 'type' => 'text'],
        'wasvoorschrift'    => ['name' => 'Wasvoorschrift', 'type' => 'text'],
    ];

    /**
     * Rug attributes a cushion shares, per existing attribute group code, in
     * form order. Everything rug-only (maat, onderkleed, poolhoogte, maatwerk)
     * is left out, so the cushion form never shows it.
     *
     * @var array<string, list<string>>
     */
    private const SHARED = [
        'general' => [
            'sku', 'ean', 'productnaam', 'afbeelding', 'afbeelding_zonder_logo', 'merk', 'collectie', 'in_collectie', 'dob',
            'levertijd_voorradig', 'levertijd_niet_voorradig', 'beschrijving_l', 'beschrijving_k',
            'prijs', 'adviesverkoopprijs', 'sale_prijs', 'materiaal', 'kleuren', 'patroon', 'gewicht',
            'onderhoudsadvies', 'productieland', 'garantie', 'sorteer_volgorde', 'meta_titel', 'meta_beschrijving',
        ],
        'Gegenereerd' => ['voorraad_5_korting'],
        'Voorraad'    => ['voorraad_eurogros', 'voorraad_5_korting_handmatig', 'voorraad_hw_5_korting', 'uitverkoop_15_korting', 'levertijd', 'extra_korting'],
    ];

    private const FAMILY = 'kussens';

    private const GROUP = 'kussen';

    /**
     * Create the `kussens` attribute family: cushions sold next to the rugs,
     * as configurable products with kussenmaat × kleur × vulling variants.
     */
    public function up(): void
    {
        if (DB::table('attribute_families')->where('code', self::FAMILY)->exists()) {
            return;
        }

        /**
         * The shared rug attributes only exist on installed instances (they
         * are not part of the installer seed); on a bare database there is
         * nothing to build the family from.
         */
        $textSibling = DB::table('attributes')->where('code', 'materiaal')->first();
        $selectSibling = DB::table('attributes')->where('code', 'onderkleed')->first();

        if (! $textSibling || ! $selectSibling) {
            return;
        }

        $attributeIds = [];

        foreach (self::ATTRIBUTES as $code => $definition) {
            $attributeIds[$code] = DB::table('attributes')->where('code', $code)->value('id')
                ?? $this->createAttribute($code, $definition, $definition['type'] === 'select' ? $selectSibling : $textSibling);
        }

        $familyId = DB::table('attribute_families')->insertGetId(['code' => self::FAMILY, 'status' => 0]);

        DB::table('attribute_family_translations')->insert([
            ['attribute_family_id' => $familyId, 'locale' => 'en_US', 'name' => 'Kussens'],
            ['attribute_family_id' => $familyId, 'locale' => 'nl_NL', 'name' => 'Kussens'],
        ]);

        $groupId = DB::table('attribute_groups')->where('code', self::GROUP)->value('id')
            ?? DB::table('attribute_groups')->insertGetId(['code' => self::GROUP, 'is_user_defined' => 1]);

        if (! DB::table('attribute_group_translations')->where('attribute_group_id', $groupId)->exists()) {
            DB::table('attribute_group_translations')->insert([
                ['attribute_group_id' => $groupId, 'locale' => 'en_US', 'name' => 'Kussen'],
                ['attribute_group_id' => $groupId, 'locale' => 'nl_NL', 'name' => 'Kussen'],
            ]);
        }

        $groups = self::SHARED + [self::GROUP => array_keys(self::ATTRIBUTES)];
        $position = 1;

        foreach ($groups as $groupCode => $codes) {
            $familyGroupId = DB::table('attribute_family_group_mappings')->insertGetId([
                'attribute_family_id' => $familyId,
                'attribute_group_id'  => $groupCode === self::GROUP ? $groupId : DB::table('attribute_groups')->where('code', $groupCode)->value('id'),
                'position'            => $position++,
            ]);

            $ids = DB::table('attributes')->whereIn('code', $codes)->pluck('id', 'code');

            foreach (array_values(array_filter($codes, fn (string $code): bool => $ids->has($code))) as $index => $code) {
                DB::table('attribute_group_mappings')->insert([
                    'attribute_id'              => $ids[$code],
                    'attribute_family_group_id' => $familyGroupId,
                    'position'                  => $index + 1,
                ]);
            }
        }
    }

    public function down(): void
    {
        $familyId = DB::table('attribute_families')->where('code', self::FAMILY)->value('id');

        if ($familyId) {
            $familyGroupIds = DB::table('attribute_family_group_mappings')->where('attribute_family_id', $familyId)->pluck('id');

            DB::table('attribute_group_mappings')->whereIn('attribute_family_group_id', $familyGroupIds)->delete();
            DB::table('attribute_family_group_mappings')->where('attribute_family_id', $familyId)->delete();
            DB::table('attribute_family_translations')->where('attribute_family_id', $familyId)->delete();
            DB::table('attribute_families')->where('id', $familyId)->delete();
        }

        $groupId = DB::table('attribute_groups')->where('code', self::GROUP)->value('id');

        if ($groupId && ! DB::table('attribute_family_group_mappings')->where('attribute_group_id', $groupId)->exists()) {
            DB::table('attribute_group_translations')->where('attribute_group_id', $groupId)->delete();
            DB::table('attribute_groups')->where('id', $groupId)->delete();
        }

        foreach (array_keys(self::ATTRIBUTES) as $code) {
            $attributeId = DB::table('attributes')->where('code', $code)->value('id');

            if (! $attributeId || DB::table('attribute_group_mappings')->where('attribute_id', $attributeId)->exists()) {
                continue;
            }

            $optionIds = DB::table('attribute_options')->where('attribute_id', $attributeId)->pluck('id');

            DB::table('attribute_option_translations')->whereIn('attribute_option_id', $optionIds)->delete();
            DB::table('attribute_options')->where('attribute_id', $attributeId)->delete();
            DB::table('attribute_translations')->where('attribute_id', $attributeId)->delete();
            DB::table('attributes')->where('id', $attributeId)->delete();
        }
    }

    /**
     * @param  array{name: string, type: string, options?: list<string>}  $definition
     */
    private function createAttribute(string $code, array $definition, object $sibling): int
    {
        $now = now();

        $attributeId = DB::table('attributes')->insertGetId([
            'code'              => $code,
            'type'              => $definition['type'],
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
            ['attribute_id' => $attributeId, 'locale' => 'en_US', 'name' => $definition['name']],
            ['attribute_id' => $attributeId, 'locale' => 'nl_NL', 'name' => $definition['name']],
        ]);

        foreach ($definition['options'] ?? [] as $sortOrder => $option) {
            $optionId = DB::table('attribute_options')->insertGetId([
                'attribute_id' => $attributeId,
                'code'         => $option,
                'sort_order'   => $sortOrder,
            ]);

            DB::table('attribute_option_translations')->insert([
                ['attribute_option_id' => $optionId, 'locale' => 'en_US', 'label' => $option],
                ['attribute_option_id' => $optionId, 'locale' => 'nl_NL', 'label' => $option],
            ]);
        }

        return $attributeId;
    }
};
