<?php

namespace App\Console\Commands;

use App\Models\BolComCredential;
use App\Services\Bol\BolEconomicOperatorService;
use Illuminate\Console\Command;

class BolEconomicOperatorCommand extends Command
{
    protected $signature = 'bolcom:economic-operator
        {action : refresh | list}
        {credential : Bol.com credential id}';

    protected $description = "Manage Bol.com economic operators (marktdeelnemers). Operators are created in Bol's seller portal; this command pulls the registered list into the local cache. Per-product matching is done by operator name == product brand (values.common.merk).";

    public function handle(BolEconomicOperatorService $service): int
    {
        $credential = BolComCredential::find((int) $this->argument('credential'));
        if (! $credential) {
            $this->error('Credential not found.');

            return self::FAILURE;
        }

        return match ($this->argument('action')) {
            'refresh' => $this->refresh($service, $credential),
            'list'    => $this->list($credential),
            default   => $this->failUnknown(),
        };
    }

    private function refresh(BolEconomicOperatorService $service, BolComCredential $credential): int
    {
        $operators = $service->refreshCache($credential);
        $this->info(sprintf('Cached %d operator(s) for credential %s.', count($operators), $credential->name));

        return self::SUCCESS;
    }

    private function list(BolComCredential $credential): int
    {
        $operators = $credential->economicOperators()->orderBy('name')->get();

        if ($operators->isEmpty()) {
            $this->warn("No operators cached yet. Create one in Bol's seller portal first, then run: bolcom:economic-operator refresh ".$credential->id);

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'External Ref', 'Bol UUID', 'Status'],
            $operators->map(fn ($o) => [$o->name, $o->external_reference ?? '-', $o->bol_operator_id, $o->status ?? '-'])->all(),
        );
        $this->newLine();
        $this->line('  Matching to products: operator.name == product.values.common.merk (case-insensitive).');

        return self::SUCCESS;
    }

    private function failUnknown(): int
    {
        $this->error('Unknown action. Use: refresh, list.');

        return self::FAILURE;
    }
}
