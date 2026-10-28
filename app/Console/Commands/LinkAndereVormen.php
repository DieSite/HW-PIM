<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\AndereVormenLinker;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class LinkAndereVormen extends Command
{
    protected $signature = 'products:link-andere-vormen
                            {--dry-run : Show the groups without saving anything}
                            {--limit= : Maximum number of groups to link}';

    protected $description = 'Link every rug to the same rug in its other shapes (andere_vormen) and sync the changed products to WooCommerce';

    public function handle(AndereVormenLinker $linker): int
    {
        ['groups' => $groups, 'ambiguous' => $ambiguous] = $linker->groups();

        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $groups = $limit ? array_slice($groups, 0, $limit) : $groups;

        $todo = array_values(array_filter($groups, function (Collection $members) use ($linker): bool {
            $skus = $members->pluck('sku')->map(fn ($sku): string => (string) $sku)->sort()->values()->all();

            return $members->contains(fn (Product $product): bool => $linker->current($product) !== $skus);
        }));

        $this->info(count($groups).' group(s) of one rug in several shapes, '.count($todo).' not linked yet.');

        foreach ($todo as $members) {
            $this->line('  '.$this->describe($linker, $members));
        }

        if ($ambiguous !== []) {
            $this->warn(count($ambiguous).' group(s) with the same shape twice, skipped (link these by hand under "Andere vormen"):');

            foreach ($ambiguous as $members) {
                $this->line('  '.$this->describe($linker, $members));
            }
        }

        if ($this->option('dry-run') || $todo === []) {
            return self::SUCCESS;
        }

        $changed = 0;
        $this->output->progressStart(count($todo));

        foreach ($todo as $members) {
            $changed += $linker->connect($members);
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->info("{$changed} product(s) updated and queued for WooCommerce.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Product>  $members
     */
    private function describe(AndereVormenLinker $linker, Collection $members): string
    {
        return $members
            ->map(fn (Product $product): string => $product->sku.' ('.($linker->vorm($product) ?: '?').')')
            ->implode(', ');
    }
}
