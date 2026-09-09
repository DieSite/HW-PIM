<?php

namespace App\Console\Commands;

use App\Models\AiDescriptionDraft;
use App\Services\AI\TextRepairer;
use Illuminate\Console\Command;

/**
 * Repairs drafts written before TextRepairer sat in the generation path.
 *
 * Only the draft is touched. A draft that was already published wrote its text
 * onto the product, so the product is reported rather than silently rewritten —
 * republishing is a decision for the reviewer, and it triggers a shop sync.
 */
class RepairAiDraftTextsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ai:repair-draft-texts {--dry-run : Report what would change without writing}';

    /**
     * @var string
     */
    protected $description = 'Repair mangled accents (gem#leerd, &ecirc;) in existing AI description drafts';

    public function handle(TextRepairer $repairer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $repaired = 0;
        $stillGarbled = [];
        $published = [];

        AiDescriptionDraft::query()
            ->whereNotNull('fields')
            ->orderBy('id')
            ->chunkById(200, function ($drafts) use ($repairer, $dryRun, &$repaired, &$stillGarbled, &$published): void {
                foreach ($drafts as $draft) {
                    $fields = $draft->fields ?? [];
                    $fixed = [];
                    $changed = false;

                    foreach ($fields as $code => $text) {
                        $fixed[$code] = $repairer->repair((string) $text);

                        if ($fixed[$code] !== $text) {
                            $changed = true;
                        }

                        foreach ($repairer->garbledWords($fixed[$code]) as $word) {
                            $stillGarbled[$word][] = $draft->id;
                        }
                    }

                    if (! $changed) {
                        continue;
                    }

                    $repaired++;

                    if ($draft->status === AiDescriptionDraft::STATUS_APPLIED) {
                        $published[] = $draft->id;
                    }

                    if (! $dryRun) {
                        $draft->update(['fields' => $fixed]);
                    }
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '').($dryRun ? 'Would repair' : 'Repaired')." {$repaired} drafts.");

        foreach ($stillGarbled as $word => $draftIds) {
            $this->warn("Still mangled: \"{$word}\" in draft(s) ".implode(', ', array_unique($draftIds)).' — add it to config/ai.php text_repairs.');
        }

        if ($published !== []) {
            $this->warn('These repaired drafts were already published, so the product still carries the mangled text: '.implode(', ', $published).'. Republish them from the review screen to push the fix to the shop.');
        }

        return self::SUCCESS;
    }
}
