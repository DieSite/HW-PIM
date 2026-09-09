<?php

namespace App\Console\Commands;

use App\Models\CompetitorCoverageConfirmation;
use App\Models\CompetitorSignalReview;
use Illuminate\Console\Command;

/**
 * Maakt de "al bekeken"-oordelen uit het dagrapport weer leeg.
 *
 * Twee knoppen in het rapport onderdrukken een signaal: "Klopt wel" haalt een
 * kleed uit de verdachte top-5, "Klopt, geen concurrent gevonden" haalt het uit
 * het dekkingsblok. Dat is precies de bedoeling — anders staat elke nacht
 * dezelfde lijst in de mail — maar het is ook toestand die daarna niemand meer
 * ziet. Assortimenten en prijzen wijzigen, dus een oordeel van maanden geleden
 * kan een inmiddels terecht signaal blijven verbergen. Vandaar deze knop.
 */
class ClearCompetitorReviewsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pricing:clear-competitor-reviews
                            {--sku= : Alleen de oordelen over dit kleed}
                            {--older-than= : Alleen oordelen ouder dan dit aantal dagen}
                            {--suspects : Alleen de "klopt wel"-oordelen uit de verdachte top-5}
                            {--coverage : Alleen de "geen concurrent gevonden"-bevestigingen}
                            {--dry-run : Toon wat er zou verdwijnen, verwijder niets}';

    /**
     * @var string
     */
    protected $description = 'Verwijder bekeken-oordelen uit het dagrapport, zodat die signalen weer getoond worden.';

    public function handle(): int
    {
        $alleen = match (true) {
            (bool) $this->option('suspects') => 'suspects',
            (bool) $this->option('coverage') => 'coverage',
            default                          => null,
        };

        $verwijderd = 0;

        if ($alleen !== 'coverage') {
            $verwijderd += $this->clear(
                CompetitorSignalReview::query(),
                'reviewed_at',
                'klopt wel (verdachte prijs)',
            );
        }

        if ($alleen !== 'suspects') {
            $verwijderd += $this->clear(
                CompetitorCoverageConfirmation::query(),
                'confirmed_at',
                'geen concurrent gevonden',
            );
        }

        if ($verwijderd === 0) {
            $this->info('Geen oordelen gevonden die aan deze selectie voldoen.');
        } elseif (! $this->option('dry-run')) {
            $this->info($verwijderd.' oordelen verwijderd. Die signalen komen vanaf het volgende rapport weer terug.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function clear($query, string $dateColumn, string $label): int
    {
        if ($sku = $this->option('sku')) {
            $query->where('sku', $sku);
        }

        if ($days = $this->option('older-than')) {
            $query->where($dateColumn, '<', now()->subDays((int) $days));
        }

        $rows = $query->get(['id', 'sku', $dateColumn]);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->line("\n<info>{$label}</info> — ".$rows->count().' stuks');
        $this->table(
            ['SKU', 'Beoordeeld op'],
            $rows->take(15)->map(fn ($r): array => [$r->sku, $r->{$dateColumn}?->format('d-m-Y')])->all(),
        );

        if ($rows->count() > 15) {
            $this->line('… en nog '.($rows->count() - 15).'.');
        }

        if ($this->option('dry-run')) {
            return $rows->count();
        }

        $query->getModel()->newQuery()->whereIn('id', $rows->pluck('id'))->delete();

        return $rows->count();
    }
}
