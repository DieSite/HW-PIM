<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\WooCommerce\Models\Credential;
use Webkul\WooCommerce\Models\DataTransferMapping;
use Webkul\WooCommerce\Services\WooCommerceService;

/**
 * Makes sure a PIM attribute exists in WooCommerce as a global (taxonomy)
 * attribute and is mapped, so the product export can use it as a variation
 * attribute. This does the per-attribute part of the manual attribute export
 * job: add the code to the credential's custom fields, then create or adopt
 * `pa_<code>` in the shop.
 *
 * Options (terms) are not sent: the product export sends option names and
 * WooCommerce creates missing terms itself when it saves the product.
 *
 * An existing shop attribute with the same slug is adopted instead of created
 * twice. Without a default WooCommerce credential nothing is sent.
 */
class WooCommerceAttributeSync
{
    public function __construct(private WooCommerceService $connector) {}

    /**
     * The WooCommerce attribute id, creating the attribute in the shop when
     * needed. Null when no shop is configured.
     */
    public function ensure(string $code): ?int
    {
        $credential = $this->connector->getCredentialForQuickExport();

        if (! $credential) {
            return null;
        }

        $this->addCustomField((int) $credential['id'], $code);

        $mapped = $this->mappedId($code, $credential['shopUrl']);

        if ($mapped !== null) {
            return $mapped;
        }

        $existing = collect($this->connector->requestApiAction('getAttributes', [], ['credential' => $credential['id']]))
            ->first(fn ($attribute): bool => is_array($attribute) && ($attribute['slug'] ?? null) === "pa_{$code}");

        $externalId = $existing['id'] ?? null;

        if (! $externalId) {
            $created = $this->connector->requestApiAction('addAttribute', [
                'name' => $this->name($code),
                'slug' => $code,
                'type' => 'select',
            ], ['credential' => $credential['id']]);

            $externalId = $created['id'] ?? $created['data']['resource_id'] ?? null;

            if (! $externalId) {
                throw new RuntimeException("WooCommerce did not create attribute {$code}: ".json_encode($created));
            }
        }

        DataTransferMapping::query()->create([
            'entityType'    => 'attribute',
            'code'          => $code,
            'externalId'    => $externalId,
            'jobInstanceId' => 0,
            'apiUrl'        => $credential['shopUrl'],
        ]);

        return (int) $externalId;
    }

    /**
     * Whether the attribute already has a WooCommerce mapping, or null when
     * no shop is configured.
     */
    public function isMapped(string $code): ?bool
    {
        $credential = $this->connector->getCredentialForQuickExport();

        return $credential ? $this->mappedId($code, $credential['shopUrl']) !== null : null;
    }

    /**
     * The nl_NL attribute name, falling back to the code.
     */
    public function name(string $code): string
    {
        $name = DB::table('attribute_translations')
            ->join('attributes', 'attributes.id', '=', 'attribute_translations.attribute_id')
            ->where('attributes.code', $code)
            ->where('attribute_translations.locale', 'nl_NL')
            ->value('attribute_translations.name');

        return trim((string) $name) !== '' ? trim((string) $name) : $code;
    }

    /**
     * Add the code to the credential's custom fields: the list the attribute
     * export and the credential screen work from.
     */
    private function addCustomField(int $credentialId, string $code): void
    {
        $credential = Credential::query()->find($credentialId);

        if (! $credential) {
            return;
        }

        $extras = (array) $credential->extras;
        $fields = (array) ($extras['custom_field'] ?? []);

        if (in_array($code, $fields, true)) {
            return;
        }

        $extras['custom_field'] = [...$fields, $code];
        $credential->extras = $extras;
        $credential->save();
    }

    private function mappedId(string $code, string $shopUrl): ?int
    {
        $externalId = DataTransferMapping::query()
            ->where('entityType', 'attribute')
            ->where('code', $code)
            ->where('apiUrl', $shopUrl)
            ->whereNotNull('externalId')
            ->value('externalId');

        return $externalId ? (int) $externalId : null;
    }
}
