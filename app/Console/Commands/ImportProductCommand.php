<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportProductCommand extends Command
{
    protected $signature = 'product:import {file : JSON file produced by product:export}';

    protected $description = 'Import a product JSON dump produced by product:export, upserting by SKU.';

    public function handle(): int
    {
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

                $row = $remote;
                unset($row['id']);

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

            $this->replacePivot(
                'product_super_attributes',
                $payload['product_super_attributes'] ?? [],
                $remoteToLocal,
                ['product_id'],
                ['product_id', 'attribute_id']
            );

            $this->replacePivot(
                'product_relations',
                $payload['product_relations'] ?? [],
                $remoteToLocal,
                ['parent_id', 'child_id'],
                ['parent_id', 'child_id']
            );

            $this->replacePivot(
                'product_bol_com_credentials',
                $payload['product_bol_com_credentials'] ?? [],
                $remoteToLocal,
                ['product_id'],
                ['product_id', 'bol_com_credential_id'],
                dropColumns: ['id']
            );
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
     * Re-insert pivot rows after remapping product_id columns from remote to local IDs.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, int>  $remoteToLocal
     * @param  array<int, string>  $productIdColumns
     * @param  array<int, string>  $uniqueColumns
     * @param  array<int, string>  $dropColumns
     */
    protected function replacePivot(
        string $table,
        array $rows,
        array $remoteToLocal,
        array $productIdColumns,
        array $uniqueColumns,
        array $dropColumns = []
    ): void {
        if (empty($rows)) {
            return;
        }

        $remapped = [];

        foreach ($rows as $row) {
            foreach ($dropColumns as $column) {
                unset($row[$column]);
            }

            foreach ($productIdColumns as $column) {
                $remoteId = (int) $row[$column];

                if (! isset($remoteToLocal[$remoteId])) {
                    continue 2;
                }

                $row[$column] = $remoteToLocal[$remoteId];
            }

            $remapped[] = $row;
        }

        if (empty($remapped)) {
            return;
        }

        DB::table($table)
            ->whereIn($productIdColumns[0], array_unique(array_column($remapped, $productIdColumns[0])))
            ->delete();

        foreach (array_chunk($remapped, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
