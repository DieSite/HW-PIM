<?php

namespace App\Services\Bol;

use App\Clients\BolApiClient;
use App\Models\BolComCredential;
use App\Models\BolEconomicOperator;

/**
 * Wraps Bol's Economic Operator API (v1) and keeps a local cache so the
 * admin picker doesn't have to hit Bol on every page render.
 *
 * Spec: docs/bol-api-spec/economic-operators-v1.json
 */
class BolEconomicOperatorService
{
    public function refreshCache(BolComCredential $credential): array
    {
        $response = (new BolApiClient())
            ->setCredential($credential)
            ->get('/retailer/economic-operators');

        $operators = $response['economicOperators'] ?? $response['economic_operators'] ?? $response['operators'] ?? [];

        $seenIds = [];
        foreach ($operators as $operator) {
            $id = $operator['economicOperatorId'] ?? $operator['id'] ?? null;
            if (! $id) {
                continue;
            }

            BolEconomicOperator::updateOrCreate(
                ['bol_com_credential_id' => $credential->id, 'bol_operator_id' => $id],
                [
                    'name'               => $operator['name'] ?? 'Onbekend',
                    'external_reference' => $operator['externalReference'] ?? null,
                    'status'             => $operator['status'] ?? null,
                    'payload'            => $operator,
                ],
            );

            $seenIds[] = $id;
        }

        // Prune entries that no longer exist at Bol.
        BolEconomicOperator::where('bol_com_credential_id', $credential->id)
            ->when($seenIds !== [], fn ($q) => $q->whereNotIn('bol_operator_id', $seenIds))
            ->delete();

        return $operators;
    }
}
