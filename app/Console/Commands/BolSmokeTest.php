<?php

namespace App\Console\Commands;

use App\Clients\BolApiClient;
use App\Models\BolComCredential;
use App\Services\Bol\BolContractValidator;
use Illuminate\Console\Command;

/**
 * Verify our assumptions about the Bol.com retailer API against real responses.
 *
 * Runs a set of read-only calls (auth probe, demo offer, categories list,
 * process-status of a known id if provided) and asserts each response against
 * the snapshotted OpenAPI spec. Writes sanitized samples to
 * tests/Fixtures/bolcom/live/ so future schema drift is visible in git.
 */
class BolSmokeTest extends Command
{
    protected $signature = 'bolcom:smoke-test
        {credential : Bol.com credential id (bol_com_credentials.id)}
        {--write : Write captured response samples to tests/Fixtures/bolcom/live/}
        {--process= : Optional Bol.com process-status id to fetch}';

    protected $description = 'Probe the Bol.com retailer API with read-only calls and validate responses against the OpenAPI spec.';

    public function handle(): int
    {
        $credential = BolComCredential::find((int) $this->argument('credential'));
        if (! $credential) {
            $this->error('Credential not found.');

            return self::FAILURE;
        }

        $apiClient = new BolApiClient($credential, true);
        $validator = new BolContractValidator([
            base_path('docs/bol-api-spec/retailer-v10.json'),
            base_path('docs/bol-api-spec/shared-v10.json'),
        ]);
        $mediaType = 'application/vnd.retailer.v10+json';

        $fixtureDir = base_path('tests/Fixtures/bolcom/live');
        $write = (bool) $this->option('write');

        if ($write && ! is_dir($fixtureDir)) {
            mkdir($fixtureDir, 0755, true);
        }

        $checks = [
            ['name' => 'auth_probe', 'method' => 'GET', 'endpoint' => '/retailer-demo/offers/13722de8-8182-d161-5422-4a0a1caab5c8', 'path' => null],
            ['name' => 'categories', 'method' => 'GET', 'endpoint' => '/retailer/products/categories', 'path' => '/retailer/products/categories'],
        ];

        $processId = $this->option('process');
        if ($processId) {
            $checks[] = ['name' => 'process_status', 'method' => 'GET', 'endpoint' => '/shared/process-status/'.$processId, 'path' => '/shared/process-status/{process-status-id}'];
        }

        $rows = [];
        $failed = false;

        foreach ($checks as $check) {
            $line = ['check' => $check['name'], 'endpoint' => $check['endpoint']];

            try {
                $response = $apiClient->get($check['endpoint']);
                $line['status'] = '200/OK';

                if ($check['path'] !== null && is_array($response)) {
                    $violations = $validator->validateResponse($check['method'], $check['path'], 200, $response, $mediaType);
                    $line['contract'] = $violations === [] ? 'OK' : (count($violations).' violations');
                    if ($violations !== []) {
                        $this->warn($check['name'].': '.implode("\n  ", $violations));
                        $failed = true;
                    }
                } else {
                    $line['contract'] = 'n/a';
                }

                if ($write && $response !== null) {
                    $file = $fixtureDir.'/'.$check['name'].'.json';
                    file_put_contents($file, json_encode($this->sanitize($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $this->info('  → wrote '.$file);
                }
            } catch (\Throwable $e) {
                $line['status'] = 'ERROR';
                $line['contract'] = substr($e->getMessage(), 0, 80);
                $failed = true;
            }

            $rows[] = $line;
        }

        $this->table(['Check', 'Endpoint', 'HTTP', 'Contract'], array_map(fn ($r) => [$r['check'], $r['endpoint'], $r['status'], $r['contract']], $rows));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Strip personally identifying fields before writing fixtures.
     */
    private function sanitize(array $payload): array
    {
        $keysToScrub = ['emailAddress', 'phoneNumber', 'firstName', 'surname', 'street', 'houseNumber', 'zipCode', 'city'];

        array_walk_recursive($payload, function (&$value, $key) use ($keysToScrub) {
            if (in_array((string) $key, $keysToScrub, true)) {
                $value = '<redacted>';
            }
        });

        return $payload;
    }
}
