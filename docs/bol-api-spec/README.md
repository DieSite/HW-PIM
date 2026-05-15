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
curl -sS -L -o docs/bol-api-spec/shared-v10.json  "https://api.bol.com/retailer/public/apispec/Shared%20API%20-%20v10"
curl -sS -L -o docs/bol-api-spec/offers-v11.yaml  "https://api.bol.com/registry/api-definitions/offers/offers-v11.yaml"
```

Re-run the test suite after refreshing to catch any contract changes.

## Open migration

**POST / PUT / DELETE `/retailer/offers`** are marked `deprecated: true` in v10. The v11 spec (`offers-v11.yaml`) is the long-term home for these endpoints. Same field names, only the `Accept` / `Content-Type` media type changes from `application/vnd.retailer.v10+json` to `application/vnd.retailer.v11+json`.

Tracked in `BolApiClient::request()` — when we migrate, the client must route offer endpoints through the v11 media type while keeping content endpoints on v10.
