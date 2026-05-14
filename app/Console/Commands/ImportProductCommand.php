<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportProductCommand extends Command
{
    protected $signature = 'product:import {file : JSON file produced by product:export} {--force : Allow running outside of local environment}';

    protected $description = 'Import a product JSON dump produced by product:export, upserting by SKU.';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to run product:import in production. Pass --force to override.');

            return self::FAILURE;
        }

        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);

        if (! is_array($payload) || ! isset($payload['products'])) {
            $this->error('Invalid export file: missing "products" key.');

            return self::FAILURE;
        }

        $remoteToLocal = [];

        DB::transaction(function () use ($payload, &$remoteToLocal) {
            $products = $this->orderProductsByParent($payload['products']);

            foreach ($products as $remote) {
                $remoteId = (int) $remote['id'];
                $remoteParentId = $remote['parent_id'] !== null ? (int) $remote['parent_id'] : null;

                $familyCode = $remote['_attribute_family_code'] ?? null;
                $row = $remote;
                unset($row['id'], $row['_attribute_family_code']);

                foreach (['values', 'additional'] as $jsonField) {
                    if (array_key_exists($jsonField, $row) && ! is_string($row[$jsonField])) {
                        $row[$jsonField] = $row[$jsonField] === null
                            ? null
                            : json_encode($row[$jsonField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }

                if ($familyCode !== null) {
                    $localFamilyId = DB::table('attribute_families')->where('code', $familyCode)->value('id');

                    if (! $localFamilyId) {
                        $localFamilyId = DB::table('attribute_families')->insertGetId([
                            'code'   => $familyCode,
                            'status' => 1,
                        ]);
                        $this->warn("Created placeholder attribute_family '{$familyCode}' (id={$localFamilyId}). Populate its groups/mappings to edit products fully.");
                    }

                    $row['attribute_family_id'] = $localFamilyId;
                }

                if ($remoteParentId !== null) {
                    if (! isset($remoteToLocal[$remoteParentId])) {
                        throw new \RuntimeException(
                            "Parent product with remote ID {$remoteParentId} was not in the export."
                        );
                    }
                    $row['parent_id'] = $remoteToLocal[$remoteParentId];
                }

                $existing = DB::table('products')->where('sku', $row['sku'])->first();

                if ($existing) {
                    DB::table('products')->where('id', $existing->id)->update($row);
                    $localId = (int) $existing->id;
                } else {
                    $localId = (int) DB::table('products')->insertGetId($row);
                }

                $remoteToLocal[$remoteId] = $localId;
            }

            $this->replaceSuperAttributes($payload['product_super_attributes'] ?? [], $remoteToLocal);
            $this->replaceRelations($payload['product_relations'] ?? [], $remoteToLocal);
            $this->replaceBolCredentials($payload['product_bol_com_credentials'] ?? [], $remoteToLocal);
        });

        $this->info(sprintf(
            'Imported %d product(s). ID map: %s',
            count($remoteToLocal),
            json_encode($remoteToLocal)
        ));

        return self::SUCCESS;
    }

    /**
     * Sort so parents are inserted before their children.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    protected function orderProductsByParent(array $products): array
    {
        usort($products, function ($a, $b) {
            $aHasParent = ! empty($a['parent_id']);
            $bHasParent = ! empty($b['parent_id']);

            return $aHasParent <=> $bHasParent;
        });

        return $products;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $remoteToLocal
     */
    protected function replaceSuperAttributes(array $rows, array $remoteToLocal): void
    {
        if (empty($rows)) {
            return;
        }

        $localProductIds = [];
        $insert = [];

        foreach ($rows as $row) {
            $remoteProductId = (int) $row['product_id'];

            if (! isset($remoteToLocal[$remoteProductId])) {
                continue;
            }

            $localProductId = $remoteToLocal[$remoteProductId];
            $attributeCode = $row['_attribute_code'] ?? null;

            if (! $attributeCode) {
                continue;
            }

            $localAttributeId = DB::table('attributes')->where('code', $attributeCode)->value('id');

            if (! $localAttributeId) {
                $this->warn("Skipping super_attribute: attribute '{$attributeCode}' not found locally.");

                continue;
            }

            $localProductIds[] = $localProductId;
            $insert[] = [
                'product_id'   => $localProductId,
                'attribute_id' => $localAttributeId,
            ];
        }

        if (empty($insert)) {
            return;
        }

        DB::table('product_super_attributes')
            ->whereIn('product_id', array_unique($localProductIds))
            ->delete();

        DB::table('product_super_attributes')->insert($insert);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $remoteToLocal
     */
    protected function replaceRelations(array $rows, array $remoteToLocal): void
    {
        if (empty($rows)) {
            return;
        }

        $insert = [];
        $touchedParents = [];

        foreach ($rows as $row) {
            $parent = $remoteToLocal[(int) $row['parent_id']] ?? null;
            $child = $remoteToLocal[(int) $row['child_id']] ?? null;

            if (! $parent || ! $child) {
                continue;
            }

            $touchedParents[] = $parent;
            $insert[] = [
                'parent_id' => $parent,
                'child_id'  => $child,
            ];
        }

        if (empty($insert)) {
            return;
        }

        DB::table('product_relations')
            ->whereIn('parent_id', array_unique($touchedParents))
            ->delete();

        DB::table('product_relations')->insert($insert);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $remoteToLocal
     */
    protected function replaceBolCredentials(array $rows, array $remoteToLocal): void
    {
        if (empty($rows)) {
            return;
        }

        $insert = [];
        $touchedProducts = [];

        foreach ($rows as $row) {
            $localProductId = $remoteToLocal[(int) $row['product_id']] ?? null;
            $clientId = $row['_bol_com_credential_client_id'] ?? null;

            if (! $localProductId || ! $clientId) {
                continue;
            }

            $localCredentialId = DB::table('bol_com_credentials')->where('client_id', $clientId)->value('id');

            if (! $localCredentialId) {
                $this->warn("Skipping bol credential pivot: client_id '{$clientId}' not found locally.");

                continue;
            }

            $touchedProducts[] = $localProductId;
            $insert[] = [
                'product_id'            => $localProductId,
                'bol_com_credential_id' => $localCredentialId,
                'delivery_code'         => $row['delivery_code'] ?? null,
                'reference'             => $row['reference'] ?? null,
                'created_at'            => $row['created_at'] ?? now(),
                'updated_at'            => $row['updated_at'] ?? now(),
            ];
        }

        if (empty($insert)) {
            return;
        }

        DB::table('product_bol_com_credentials')
            ->whereIn('product_id', array_unique($touchedProducts))
            ->delete();

        DB::table('product_bol_com_credentials')->insert($insert);
    }
}
