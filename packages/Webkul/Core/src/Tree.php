<?php

namespace Webkul\Core;

use Illuminate\Support\Facades\Request;

class Tree
{
    /**
     * Contains tree item
     *
     * @var array
     */
    public $items = [];

    /**
     * Contains acl roles
     *
     * @var array
     */
    public $roles = [];

    /**
     * Contains current item route
     *
     * @var string
     */
    public $current;

    /**
     * Contains current item key
     *
     * @var string
     */
    public $currentKey;

    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->current = Request::url();
    }

    /**
     * Shortcut method for create a Config with a callback.
     * This will allow you to do things like fire an event on creation.
     *
     * @param  callable  $callback  Callback to use after the Config creation
     * @return object
     */
    public static function create($callback = null)
    {
        $tree = new Tree();

        if ($callback) {
            $callback($tree);
        }

        return $tree;
    }

    /**
     * Add a Config item to the item stack
     *
     * @param  string  $item
     * @return void
     */
    public function add($item, $type = '')
    {
        $item['children'] = [];

        if ($type == 'menu') {
            $item['url'] = route($item['route'], $item['params'] ?? []);

            if (strpos($this->current, $item['url']) !== false) {
                $this->currentKey = $item['key'];
            }
        } elseif ($type == 'acl') {
            $item['name'] = trans($item['name']);

            /**
             * Entries that share a route but differ by params (the
             * configuration sections) get a route|params key; they only claim
             * the bare route when no other entry has, so one section's
             * permission no longer governs all of them.
             */
            if (! empty($item['params'])) {
                $this->roles[static::aclRouteKey($item['route'], $item['params'])] = $item['key'];

                $this->roles[$item['route']] ??= $item['key'];
            } else {
                $this->roles[$item['route']] = $item['key'];
            }
        }

        $children = str_replace('.', '.children.', $item['key']);

        core()->array_set($this->items, $children, $item);
    }

    /**
     * The roles key of an ACL entry bound to specific route parameters.
     *
     * @param  array<int|string, mixed>  $params
     */
    public static function aclRouteKey(string $route, array $params): string
    {
        return $route.'|'.implode('/', array_map('strval', array_values($params)));
    }

    /**
     * Method to find the active links
     *
     * @param  array  $item
     * @return string|void
     */
    public function getActive($item)
    {
        $url = trim($item['url'], '/');

        if (
            strpos($this->current, $url) !== false
            || (strpos($this->currentKey, $item['key']) === 0)
        ) {
            return true;
        }
    }

    /**
     * Remove unauthorized urls.
     */
    public function removeUnauthorizedUrls(): array
    {
        return collect($this->items)->map(function ($item) {
            $this->removeChildrenUnauthorizedUrls($item);

            return $item;
        })->toArray();
    }

    /**
     * Remove all children unauthorized urls. This will handle all levels.
     */
    private function removeChildrenUnauthorizedUrls(array &$item): void
    {
        if (! empty($item['children'])) {
            $firstChildrenItem = collect($item['children'])->first();

            $item['route'] = $firstChildrenItem['route'];

            $item['url'] = route($firstChildrenItem['route'], $firstChildrenItem['params'] ?? []);

            $this->removeChildrenUnauthorizedUrls($firstChildrenItem);
        }
    }
}
