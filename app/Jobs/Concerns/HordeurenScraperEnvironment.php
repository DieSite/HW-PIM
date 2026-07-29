<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\DisconnectsIdleRedis;

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
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function processEnv(array $extra = []): array
    {
        $currentPath = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin';

        return $extra + [
            'PLAYWRIGHT_BROWSERS_PATH' => (string) config('competitor_pricing.hordeuren.browsers_path'),
            'PATH'                     => $this->nodeBin().':'.$currentPath,
        ];
    }

    /**
     * Drop the worker's Redis sockets before handing control to a long-running
     * subprocess (an npm install, a Chromium download, a Playwright spec).
     *
     * Not middleware, unlike everywhere else this is used: the hordeuren jobs
     * go idle partway through handle() rather than for the whole of it, and
     * RunHordeurenAnalysisJob specifically has to reconnect between its
     * toolchain install and its batch dispatch. {@see DisconnectsIdleRedis}
     * carries the reasoning and the one implementation.
     */
    private function disconnectRedis(): void
    {
        DisconnectsIdleRedis::now();
    }
}
