# Bol.com API specs

Snapshots of the official Bol.com OpenAPI specifications we depend on. **Do not edit by hand** — re-fetch from source.

## Source

| Spec | URL | Saved as |
| --- | --- | --- |
| Retailer API v10 | https://api.bol.com/retailer/public/apispec/Retailer%20API%20-%20v10 | `retailer-v10.json` |
| Shared API v10 | https://api.bol.com/retailer/public/apispec/Shared%20API%20-%20v10 | `shared-v10.json` |
| Offers API v11 | https://api.bol.com/registry/api-definitions/offers/offers-v11.yaml | `offers-v11.yaml` |

Discovery page: https://api.bol.com/retailer/public/Retailer-API/index.html

## How we use these

The integration tests under `tests/Feature/Bol` and `tests/Unit/Bol` schema-validate every outgoing request body against these specs via `App\Services\Bol\BolContractValidator`. If the spec drifts, the tests break.

## Refresh procedure

```bash
curl -sS -L -o docs/bol-api-spec/retailer-v10.json "https://api.bol.com/retailer/public/apispec/Retailer%20API%20-%20v10"
curl -sS -L -o docs/bol-api-spec/shared-v10.json   "https://api.bol.com/retailer/public/apispec/Shared%20API%20-%20v10"
curl -sS -L -o docs/bol-api-spec/offers-v11.yaml   "https://api.bol.com/registry/api-definitions/offers/offers-v11.yaml"

# Convert the yaml spec to json so BolContractValidator can load it.
docker exec -i unopim-web php -r "require '/var/www/html/vendor/autoload.php'; \$y = Symfony\\Component\\Yaml\\Yaml::parseFile('/var/www/html/docs/bol-api-spec/offers-v11.yaml'); file_put_contents('/var/www/html/docs/bol-api-spec/offers-v11.json', json_encode(\$y, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));"
```

Re-run the test suite after refreshing to catch any contract changes.

## Active versions in the client

`BolApiClient::mediaTypeForEndpoint()` routes per endpoint family:

| Endpoint pattern | Media type | Source spec |
| --- | --- | --- |
| `/retailer/offers*` | `application/vnd.retailer.v11+json` | `offers-v11.json` |
| `/retailer-demo/offers*` | `application/vnd.retailer.v11+json` | `offers-v11.json` |
| `/retailer/content/*` | `application/vnd.retailer.v10+json` | `retailer-v10.json` |
| `/retailer/products/*` | `application/vnd.retailer.v10+json` | `retailer-v10.json` |
| `/shared/*` | `application/vnd.retailer.v10+json` | `shared-v10.json` |

The v10 offer endpoints still exist but are marked `deprecated: true` in the v10 spec; do not regress to them.
