<?php

namespace Tests\Feature\Mcp;

use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * A small rug catalogue for the MCP tests: family "mcp_rugs" with the same
 * shape as production (text, select axes, price, WYSIWYG, asset and one
 * locale-specific attribute) and helpers for admins and products.
 */
class McpCatalogFixture
{
    public const FAMILY = 'mcp_rugs';

    public static function install(): void
    {
        $familyId = DB::table('attribute_families')->insertGetId(['code' => self::FAMILY, 'status' => 1]);
        $groupId = DB::table('attribute_groups')->insertGetId(['code' => 'mcp_general', 'is_user_defined' => 1]);
        $familyGroupId = DB::table('attribute_family_group_mappings')->insertGetId([
            'attribute_family_id' => $familyId,
            'attribute_group_id'  => $groupId,
            'position'            => 1,
        ]);

        $attributes = [
            'sku'            => ['type' => 'text', 'is_required' => 1, 'is_unique' => 1],
            'merk'           => ['type' => 'text'],
            'productnaam'    => ['type' => 'text'],
            'materiaal'      => ['type' => 'text'],
            'ean'            => ['type' => 'text', 'is_unique' => 1],
            'voorraad_eurogros' => ['type' => 'text'],
            'maatgroep'      => ['type' => 'select', 'options' => ['160 cm x 230 cm', '200 cm x 290 cm']],
            'onderkleed'     => ['type' => 'select', 'options' => ['Zonder onderkleed', 'Met onderkleed']],
            'prijs'          => ['type' => 'price'],
            'beschrijving_l' => ['type' => 'textarea', 'enable_wysiwyg' => 1],
            'afbeelding'     => ['type' => 'asset'],
            'mcp_tagline'    => ['type' => 'text', 'value_per_locale' => 1],
        ];

        $position = 1;

        foreach ($attributes as $code => $definition) {
            $attributeId = DB::table('attributes')->where('code', $code)->value('id')
                ?? DB::table('attributes')->insertGetId([
                    'code'              => $code,
                    'type'              => $definition['type'],
                    'position'          => $position,
                    'is_required'       => $definition['is_required'] ?? 0,
                    'is_unique'         => $definition['is_unique'] ?? 0,
                    'value_per_locale'  => $definition['value_per_locale'] ?? 0,
                    'value_per_channel' => 0,
                    'enable_wysiwyg'    => $definition['enable_wysiwyg'] ?? 0,
                    'usable_in_grid'    => 0,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

            foreach ($definition['options'] ?? [] as $sortOrder => $option) {
                DB::table('attribute_options')->insert([
                    'attribute_id' => $attributeId,
                    'code'         => $option,
                    'sort_order'   => $sortOrder,
                ]);
            }

            DB::table('attribute_group_mappings')->insert([
                'attribute_id'              => $attributeId,
                'attribute_family_group_id' => $familyGroupId,
                'position'                  => $position++,
            ]);
        }
    }

    public static function familyId(): int
    {
        return (int) DB::table('attribute_families')->where('code', self::FAMILY)->value('id');
    }

    /**
     * @param  list<string>|null  $permissions  null = a role with every permission
     */
    public static function admin(?array $permissions = null): Admin
    {
        $role = Role::query()->create([
            'name'            => 'MCP '.uniqid(),
            'description'     => 'MCP test role',
            'permission_type' => $permissions === null ? 'all' : 'custom',
            'permissions'     => $permissions ?? [],
        ]);

        return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
    }

    /**
     * A configurable rug with one variant, created the way the admin does.
     *
     * @param  array<string, mixed>  $parentCommon
     * @param  array<string, mixed>  $variantCommon
     * @return array{0: Product, 1: Product}
     */
    public static function rug(string $sku, array $parentCommon = [], array $variantCommon = []): array
    {
        $parent = new Product();
        $parent->attribute_family_id = self::familyId();
        $parent->sku = $sku;
        $parent->type = 'configurable';
        $parent->status = 1;
        $parent->values = ['common' => ['sku' => $sku] + $parentCommon];
        $parent->save();

        $parent->super_attributes()->attach(
            DB::table('attributes')->whereIn('code', ['onderkleed', 'maatgroep'])->pluck('id')->all()
        );

        $variant = new Product();
        $variant->attribute_family_id = self::familyId();
        $variant->sku = $sku.'.1';
        $variant->type = 'simple';
        $variant->parent_id = $parent->id;
        $variant->status = 1;
        $variant->values = ['common' => [
            'sku'        => $sku.'.1',
            'onderkleed' => 'Zonder onderkleed',
            'maatgroep'  => '160 cm x 230 cm',
        ] + $parentCommon + $variantCommon];
        $variant->save();

        return [$parent->refresh(), $variant->refresh()];
    }
}
