<?php

namespace App\Jobs\Concerns;

/**
 * Shared process environment for jobs that invoke the Node/Playwright
 * hordeuren scraper in `competitor-analysis/`.
 */
trait HordeurenScraperEnvironment
{
    /**
     * Directory holding a matched node/npx/npm toolchain. The queue worker's
     * inherited PATH may lead with an ancient Node (v14) whose bundled npm
     * cannot even parse modern npm's source, so the toolchain must be pinned.
     */
    private function nodeBin(): string
    {
        return rtrim((string) config('competitor_pricing.hordeuren.node_bin'), '/');
    }

    /**
     * @return array<string, string>
     */
    private function processEnv(): array
    {
        $currentPath = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        return [
            'PLAYWRIGHT_BROWSERS_PATH' => (string) config('competitor_pricing.hordeuren.browsers_path'),
            'PATH'                     => $this->nodeBin().':'.$currentPath,
        ];
    }
}
