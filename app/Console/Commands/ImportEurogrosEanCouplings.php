<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\EurogrosOmschrParser;
use App\Services\EurogrosProductMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportEurogrosEanCouplings extends Command
{
    private const CSV = 'private/eurogros/Voorraad_Eurogros.csv';

    /**
     * @var string
     */
    protected $signature = 'import:eurogros-ean-couplings
        {--dry-run : Report what would change without writing}
        {--limit=0 : Only process the first N CSV rows (0 = all)}';

    /**
     * @var string
     */
    protected $description = 'Couple Eurogros EANs to PIM products by collection + article number + size + shape';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        if (! Storage::disk('local')->exists(self::CSV)) {
            $this->error('CSV not found at storage/app/'.self::CSV.' — run the voorraad import first.');

            return self::FAILURE;
        }

        $index = $this->buildProductIndex();
        $this->info('Indexed '.array_sum(array_map('count', $index)).' products under '.count($index).' article numbers.');

        $rows = $this->csvRows();
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $stats = ['set' => 0, 'already' => 0, 'conflict' => 0, 'ambiguous' => 0, 'unmatched' => 0, 'unparsed' => 0, 'needs_repair' => 0];
        $report = fopen(Storage::disk('local')->path('eurogros_ean_couplings_report.csv'), 'w');
        fputcsv($report, ['status', 'ean', 'omschr', 'sku', 'pim_productnaam', 'pim_maat', 'huidige_ean'], ';');

        $bar = $this->output->createProgressBar(count($rows));
        foreach ($rows as [$omschr, $ean]) {
            $bar->advance();

            $parsed = EurogrosOmschrParser::resolveMatch($omschr);
            if ($parsed === null) {
                $stats['unparsed']++;
                fputcsv($report, ['unparsed', $ean, $omschr, '', '', '', ''], ';');

                continue;
            }

            $csvDesc = EurogrosProductMatcher::describe($parsed['productnaam'], $parsed['maat']);
            $number = $csvDesc[1];
            $candidates = $number !== null ? ($index[$number] ?? []) : [];

            $matched = array_values(array_filter($candidates, fn ($p) => $p['maat'] === $parsed['maat']
                && EurogrosProductMatcher::isMatch($csvDesc, $p['desc'])));

            $designs = array_unique(array_map(fn ($p) => $p['productnaam'], $matched));

            if (count($matched) === 0) {
                $stats['unmatched']++;
                fputcsv($report, ['unmatched', $ean, $omschr, '', '', '', ''], ';');

                continue;
            }

            if (count($designs) > 1) {
                $stats['ambiguous']++;
                fputcsv($report, ['ambiguous', $ean, $omschr, implode(',', array_map(fn ($p) => $p['sku'], $matched)), implode(' | ', $designs), '', ''], ';');

                continue;
            }

            foreach ($matched as $p) {
                $current = (string) $p['ean'];
                if ($current !== '' && $current === (string) $ean) {
                    $stats['already']++;

                    continue;
                }
                if ($current !== '') {
                    $stats['conflict']++;
                    fputcsv($report, ['conflict', $ean, $omschr, $p['sku'], $p['productnaam'], $p['maat'], $current], ';');

                    continue;
                }
                if ($p['double']) {
                    $stats['needs_repair']++;
                    fputcsv($report, ['needs_repair', $ean, $omschr, $p['sku'], $p['productnaam'], $p['maat'], ''], ';');

                    continue;
                }

                $stats['set']++;
                fputcsv($report, ['set', $ean, $omschr, $p['sku'], $p['productnaam'], $p['maat'], ''], ';');

                if (! $dryRun) {
                    $this->writeEan($p['id'], (string) $ean);
                }
            }
        }

        $bar->finish();
        $this->newLine(2);
        fclose($report);

        $this->table(['Resultaat', 'Aantal'], [
            [($dryRun ? 'zou koppelen' : 'gekoppeld').' (set)', $stats['set']],
            ['al correct', $stats['already']],
            ['conflict (andere EAN aanwezig)', $stats['conflict']],
            ['dubbelzinnig (meerdere designs)', $stats['ambiguous']],
            ['geen product gevonden', $stats['unmatched']],
            ['titel onparsebaar', $stats['unparsed']],
            ['nog double-encoded (repair eerst)', $stats['needs_repair']],
        ]);
        $this->info('Rapport: storage/app/eurogros_ean_couplings_report.csv');

        if ($stats['needs_repair'] > 0) {
            $this->warn('Run `php artisan fix:double-encoded-product-values` first so those products can be coupled.');
        }
        if ($stats['set'] > 0 && ! $dryRun) {
            $this->warn('EANs written without firing sync events — run a WooCommerce/Bol resync afterwards.');
        }

        return self::SUCCESS;
    }

    /**
     * Build an index of variant products keyed by article number.
     *
     * @return array<string, list<array{id:int, sku:string, productnaam:string, maat:string, ean:string, desc:array{0:string,1:?string,2:?string}, double:bool}>>
     */
    private function buildProductIndex(): array
    {
        $index = [];

        DB::table('products')->whereNotNull('parent_id')->select(['id', 'sku', 'values'])
            ->orderBy('id')->chunk(5000, function ($chunk) use (&$index): void {
                foreach ($chunk as $row) {
                    $double = str_starts_with(trim((string) $row->values), '"');
                    $values = $this->decodeValues($row->values);
                    $common = $values['common'] ?? [];
                    $productnaam = trim((string) ($common['productnaam'] ?? ''));
                    $maat = trim((string) ($common['maat'] ?? ''));
                    if ($productnaam === '' || $maat === '') {
                        continue;
                    }

                    $desc = EurogrosProductMatcher::describe($productnaam, $maat);
                    if ($desc[1] === null) {
                        continue;
                    }

                    $index[$desc[1]][] = [
                        'id'          => $row->id,
                        'sku'         => $row->sku,
                        'productnaam' => $productnaam,
                        'maat'        => $maat,
                        'ean'         => (string) ($common['ean'] ?? ''),
                        'desc'        => $desc,
                        'double'      => $double,
                    ];
                }
            });

        return $index;
    }

    /**
     * @return array<int, array{0:string, 1:string}> [omschr, ean]
     */
    private function csvRows(): array
    {
        $content = Storage::disk('local')->get(self::CSV);
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        array_shift($lines); // header

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line, ';');
            if (count($cols) < 2 || trim($cols[0]) === '') {
                continue;
            }
            $rows[] = [trim($cols[0]), trim($cols[1])];
        }

        return $rows;
    }

    private function writeEan(int $productId, string $ean): void
    {
        $product = Product::find($productId);
        if ($product === null) {
            return;
        }

        $values = $product->values;
        if (! is_array($values)) {
            return; // still double-encoded; skip defensively
        }

        $values['common']['ean'] = $ean;
        $product->values = $values;
        $product->save();
    }

    private function decodeValues(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        while (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
