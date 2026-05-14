<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportProductCommand extends Command
{
    protected $signature = 'product:export {id : Product ID to export} {file : Output JSON file path}';

    protected $description = 'Export a single product (and its parent/variants) to a JSON file for cross-environment transfer.';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $file = $this->argument('file');

        $product = DB::table('products')->where('id', $id)->first();

        if (! $product) {
            $this->error("Product with ID {$id} not found.");

            return self::FAILURE;
        }

        $ids = $this->collectProductIds($product);

        $products = DB::table('products')
            ->whereIn('id', $ids)
            ->orderByRaw('parent_id IS NULL DESC, id ASC')
            ->get()
            ->map(function ($row) {
                $row = (array) $row;

                foreach (['values', 'additional'] as $jsonField) {
                    if (isset($row[$jsonField]) && is_string($row[$jsonField])) {
                        $decoded = json_decode($row[$jsonField], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $row[$jsonField] = $decoded;
                        }
                    }
                }

                $row['_attribute_family_code'] = $row['attribute_family_id'] === null
                    ? null
                    : DB::table('attribute_families')->where('id', $row['attribute_family_id'])->value('code');

                return $row;
            })
            ->all();

        $superAttributes = DB::table('product_super_attributes')
            ->whereIn('product_id', $ids)
            ->get()
            ->map(function ($row) {
                $row = (array) $row;
                $row['_attribute_code'] = DB::table('attributes')
                    ->where('id', $row['attribute_id'])
                    ->value('code');

                return $row;
            })
            ->all();

        $relations = DB::table('product_relations')
            ->where(fn ($q) => $q->whereIn('parent_id', $ids)->orWhereIn('child_id', $ids))
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();

        $bolCredentials = DB::table('product_bol_com_credentials')
            ->whereIn('product_id', $ids)
            ->get()
            ->map(function ($row) {
                $row = (array) $row;
                $row['_bol_com_credential_client_id'] = DB::table('bol_com_credentials')
                    ->where('id', $row['bol_com_credential_id'])
                    ->value('client_id');

                return $row;
            })
            ->all();

        $payload = [
            'version'                     => 2,
            'exported_at'                 => now()->toIso8601String(),
            'root_product_id'             => $id,
            'products'                    => $products,
            'product_super_attributes'    => $superAttributes,
            'product_relations'           => $relations,
            'product_bol_com_credentials' => $bolCredentials,
        ];

        $dir = dirname($file);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            'Exported %d product(s) to %s',
            count($payload['products']),
            $file
        ));

        return self::SUCCESS;
    }

    /**
     * Collect the product ID plus its parent (if any) and all descendants.
     *
     * @return array<int, int>
     */
    protected function collectProductIds(object $product): array
    {
        $ids = [(int) $product->id];

        if (! empty($product->parent_id)) {
            $ids[] = (int) $product->parent_id;
        }

        $descendants = DB::table('products')
            ->where('parent_id', $product->id)
            ->pluck('id')
            ->all();

        foreach ($descendants as $childId) {
            $ids[] = (int) $childId;
        }

        return array_values(array_unique($ids));
    }
}
