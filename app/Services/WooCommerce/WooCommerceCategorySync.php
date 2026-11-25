<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Category\Models\Category;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Services\WooCommerceService;

/**
 * Makes sure a PIM category exists in WooCommerce and is mapped. The product
 * export sends only mapped categories and silently drops the rest, so a
 * category that was never exported (the old manual category export job) never
 * reached the shop.
 *
 * An existing shop category with the same slug is adopted instead of created
 * twice. Without a default WooCommerce credential (local, tests) nothing is
 * sent.
 *
 * A mapping can go stale when its category is deleted in the shop; WooCommerce
 * then silently ignores the id on every product. With $verify the mapped id is
 * checked against the shop and a stale mapping is replaced.
 */
class WooCommerceCategorySync
{
    public function __construct(private WooCommerceService $connector) {}

    /**
     * The WooCommerce term id of the category, creating the category (and its
     * parents) in the shop when needed. Null when no shop is configured.
     */
    public function ensure(Category $category, bool $verify = false): ?int
    {
        $credential = $this->connector->getCredentialForQuickExport();

        if (! $credential) {
            return null;
        }

        $mapped = $this->mappedId($category->code, $credential['shopUrl']);

        if ($mapped !== null) {
            if (! $verify || $this->existsInShop($mapped, $credential['id'])) {
                return $mapped;
            }

            $this->forgetMapping($category->code, $credential['shopUrl']);
        }

        $parentId = $category->parent_id ? $this->ensure(Category::query()->findOrFail($category->parent_id), $verify) : null;
        $slug = Str::slug($category->code);

        $existing = $this->connector->requestApiAction('getCategory', [], ['credential' => $credential['id'], 'slug' => $slug]);
        $externalId = $existing[0]['id'] ?? null;

        if (! $externalId) {
            $created = $this->connector->requestApiAction('addCategory', array_filter([
                'name'   => $this->name($category),
                'slug'   => $slug,
                'parent' => $parentId,
            ]), ['credential' => $credential['id']]);

            $externalId = $created['id'] ?? $created['data']['resource_id'] ?? null;

            if (! $externalId) {
                throw new RuntimeException("WooCommerce did not create category {$category->code}: ".json_encode($created));
            }
        }

        DataTransferMapping::query()->create([
            'entityType'    => 'category',
            'code'          => $category->code,
            'externalId'    => $externalId,
            'jobInstanceId' => 0,
            'apiUrl'        => $credential['shopUrl'],
        ]);

        return (int) $externalId;
    }

    /**
     * Whether the category already has a WooCommerce mapping, or null when no
     * shop is configured.
     */
    public function isMapped(string $code): ?bool
    {
        $credential = $this->connector->getCredentialForQuickExport();

        return $credential ? $this->mappedId($code, $credential['shopUrl']) !== null : null;
    }

    /**
     * The mapped WooCommerce term id of the category in the default shop.
     */
    public function mappedExternalId(string $code): ?int
    {
        $credential = $this->connector->getCredentialForQuickExport();

        return $credential ? $this->mappedId($code, $credential['shopUrl']) : null;
    }

    /**
     * The display name: the nl_NL name, unless it is the "[code]" placeholder
     * UnoPim shows for a missing translation.
     */
    public function name(Category $category): string
    {
        $name = trim((string) ($category->additional_data['locale_specific']['nl_NL']['name'] ?? ''));

        return $name !== '' && $name !== "[{$category->code}]" ? $name : $category->code;
    }

    /**
     * Whether the shop still has a category with this id.
     */
    private function existsInShop(int $externalId, int|string $credentialId): bool
    {
        $found = $this->connector->requestApiAction('getCategory', [], ['credential' => $credentialId, 'include' => $externalId]);

        if (! is_array($found) || ! array_is_list($found)) {
            throw new RuntimeException("Could not check WooCommerce category {$externalId}: ".json_encode($found));
        }

        return collect($found)
            ->contains(fn (mixed $term): bool => is_array($term) && (int) ($term['id'] ?? 0) === $externalId);
    }

    private function forgetMapping(string $code, string $shopUrl): void
    {
        DataTransferMapping::query()
            ->where('entityType', 'category')
            ->where('code', $code)
            ->where('apiUrl', $shopUrl)
            ->delete();

        Cache::forget("wc_mapping_category_{$code}_".md5($shopUrl));
    }

    private function mappedId(string $code, string $shopUrl): ?int
    {
        $externalId = DataTransferMapping::query()
            ->where('entityType', 'category')
            ->where('code', $code)
            ->where('apiUrl', $shopUrl)
            ->whereNotNull('externalId')
            ->value('externalId');

        return $externalId ? (int) $externalId : null;
    }
}
