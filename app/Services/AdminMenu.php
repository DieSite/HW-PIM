<?php

namespace App\Services;

use Illuminate\Http\Request;
use Webkul\Core\Tree;

/**
 * Builds the admin sidebar: the full Webkul menu (config/menu.php), or the
 * simplified one (config/admin_simple_menu.php) when the user switched to it.
 * The choice lives in a cookie per browser, like dark mode.
 */
class AdminMenu
{
    public const SIMPLE = 'eenvoudig';

    public const FULL = 'volledig';

    public function __construct(private Request $request) {}

    public function mode(): string
    {
        return $this->request->cookie(self::cookieName()) === self::SIMPLE ? self::SIMPLE : self::FULL;
    }

    public function isSimple(): bool
    {
        return $this->mode() === self::SIMPLE;
    }

    public static function cookieName(): string
    {
        return (string) config('admin_simple_menu.cookie', 'admin_menu');
    }

    public function tree(): Tree
    {
        $tree = Tree::create();

        foreach ($this->isSimple() ? $this->simpleItems() : (array) config('menu.admin') as $item) {
            if (! bouncer()->hasPermission($item['permission'] ?? $item['key'])) {
                continue;
            }

            $tree->add($item, 'menu');
        }

        $tree->items = $tree->removeUnauthorizedUrls();

        $tree->items = core()->sortItems($tree->items);

        return $tree;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function simpleItems(): array
    {
        return collect((array) config('admin_simple_menu.items'))
            ->values()
            ->map(fn (array $item, int $index): array => $item + ['sort' => $index + 1])
            ->all();
    }
}
