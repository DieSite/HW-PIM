<?php

namespace App\Services\WooCommerce;

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
 */
class WooCommerceCategorySync
{
    public function __construct(private WooCommerceService $connector) {}

    /**
     * The WooCommerce term id of the category, creating the category (and its
     * parents) in the shop when needed. Null when no shop is configured.
     */
    public function ensure(Category $category): ?int
    {
        $credential = $this->connector->getCredentialForQuickExport();

        if (! $credential) {
            return null;
        }

        $mapped = $this->mappedId($category->code, $credential['shopUrl']);

        if ($mapped !== null) {
            return $mapped;
        }

        $parentId = $category->parent_id ? $this->ensure(Category::query()->findOrFail($category->parent_id)) : null;
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
     * The display name: the nl_NL name, unless it is the "[code]" placeholder
     * UnoPim shows for a missing translation.
     */
    public function name(Category $category): string
    {
        $name = trim((string) ($category->additional_data['locale_specific']['nl_NL']['name'] ?? ''));

        return $name !== '' && $name !== "[{$category->code}]" ? $name : $category->code;
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
