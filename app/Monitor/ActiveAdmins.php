<?php

namespace App\Monitor;

use Illuminate\Support\Facades\Cache;

/**
 * "Live bezoekers" on the TV board: the PIM has no public visitors, so this
 * counts admins that made a request in the last few minutes, as recorded by
 * {@see \App\Http\Middleware\TrackAdminActivity}.
 */
class ActiveAdmins
{
    public const CACHE_KEY = 'diesite-monitor:admin-activity';

    public const WINDOW_SECONDS = 300;

    public function __invoke(): int
    {
        return count(self::active());
    }

    /**
     * Admin id => unix timestamp of the last request, pruned to the window.
     *
     * @return array<int, int>
     */
    public static function active(): array
    {
        $threshold = now()->timestamp - self::WINDOW_SECONDS;

        return array_filter(
            (array) Cache::get(self::CACHE_KEY, []),
            fn (mixed $seenAt): bool => (int) $seenAt >= $threshold
        );
    }

    public static function touch(int $adminId): void
    {
        $active = self::active();

        if (($active[$adminId] ?? 0) > now()->timestamp - 60) {
            return;
        }

        $active[$adminId] = now()->timestamp;

        Cache::put(self::CACHE_KEY, $active, self::WINDOW_SECONDS);
    }
}
