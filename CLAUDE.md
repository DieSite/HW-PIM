# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

HW-PIM is a customized fork of [UnoPim](https://unopim.com/), an open-source Product Information Management (PIM) system built on Laravel 12. It is extended with integrations for Bol.com, WooCommerce, Eurogros (a vendor), and Digital Ocean Spaces for file storage.

## Common Commands

### Development

```bash
# Start local dev server
php artisan serve

# Start queue worker (required for async jobs)
php artisan queue:work

# Build frontend assets
npm run dev       # development (Vite with hot reload)
npm run build     # production

# Docker Compose (web + MySQL + queue worker containers)
docker-compose up -d
```

### Testing

```bash
# Run all tests
php artisan test

# Run a specific test suite
php artisan test --testsuite="Core Unit Test"
php artisan test --testsuite="Api Feature Test"
php artisan test --testsuite="User Feature Test"
php artisan test --testsuite="Admin Feature Test"

# Run a single test file
php artisan test packages/Webkul/Core/tests/Unit/SomeTest.php
```

Test suites are defined in `phpunit.xml`. Tests live inside each package under `packages/Webkul/*/tests/`.

### Artisan Commands (custom)

```bash
php artisan unopim:install                  # Initial installation
php artisan user:create                     # Create admin user

# Bol.com sync
php artisan fetch:bol-categories
php artisan fetch:bol-catalog-product-details
php artisan fetch:bol-content-status
php artisan fetch:bol-upload-report

# Product updates
php artisan update:all-products
php artisan update:all-parent-products
php artisan update:products-by-file

# Eurogros vendor sync
php artisan import:eurogros-voorraad
php artisan import:eurogros-ean

# Misc
php artisan pull:from-sftp
php artisan pull:from-do
php artisan pull:missing-ean-numbers
php artisan calculate:met-onderkleed-prices
```

## Architecture

### Modular Package Structure

The core application logic lives in `packages/Webkul/` as independent Laravel packages (Concord/module architecture). Each package has its own routes, models, migrations, controllers, and tests.

Key packages:
- `Admin/` — Admin UI: DataGrids, controllers, Blade views
- `Product/` — Product models, repositories, business logic
- `Attribute/` — Product attribute system
- `Category/` — Category tree management
- `DataTransfer/` — Import/Export pipeline (CSV/XLSX)
- `AdminApi/` — REST API endpoints (OAuth2 via Passport)
- `DAM/` — Digital Asset Management
- `MagicAI/` — AI content generation (OpenAI)
- `WooCommerce/` — WooCommerce channel integration
- `Core/` — Shared utilities and base database tables

### App-Level Code (`app/`)

Custom code specific to this HW deployment lives in `app/`:

- `Services/BolComProductService.php` — Core logic for syncing products to Bol.com
- `Services/ProductService.php` — General product operations
- `Clients/BolApiClient.php` — Bol.com API HTTP client
- `Jobs/` — Queue jobs: `SyncProductWithBolComJob`, `BulkSyncProductsWithBolComJob`, `ImportProductsJob`, `ImportVoorraadEurogrosJob`
- `Imports/` — Maatwebsite Excel importers for products and Eurogros inventory
- `Http/Controllers/CustomBolComController.php` — Manages per-credential Bol.com sync
- `Http/Controllers/ProductHelperController.php` — Helpers: SKU generation, pricing, meta fields, frontend redirect
- `Models/BolComCredential.php` — Model for storing per-account Bol.com API credentials

### Data Flow: Bol.com Sync

1. User triggers sync in the admin UI via `CustomBolComController`
2. `SyncProductWithBolComJob` (or `BulkSyncProductsWithBolComJob`) is dispatched to the queue
3. `BolComProductService::syncProduct()` handles the business logic
4. `BolApiClient` makes authenticated HTTP calls to the Bol.com API
5. Result is stored in the database; email sent on success/failure

### Storage

- Uploaded files go to **Digital Ocean Spaces** (S3-compatible), configured in `config/filesystems.php`
- Vendor data pulled via **SFTP** (`PullFromSFTP` command)
- Local filesystem used for queue, cache, logs

### Queue

Laravel Horizon manages background jobs. The queue driver is `database`. Run `php artisan horizon` for the dashboard or `php artisan queue:work` for a plain worker.

### Routes

- `routes/web.php` — Custom product helper endpoints (pricing, meta fields, SKU, frontend redirect)
- `routes/api.php` — API routes
- Package routes are registered via service providers in each `packages/Webkul/*/src/Providers/`

### External Integrations

| Integration | Config | Purpose |
|---|---|---|
| Bol.com | `config/bolcom.php` | Product sync, catalog fetching |
| Eurogros | `config/eurogros.php` | Inventory/EAN import via SFTP |
| WooCommerce | `packages/Webkul/WooCommerce/` | Channel integration |
| Digital Ocean Spaces | `.env` / `config/filesystems.php` | File/image storage |
| Elasticsearch | `config/elasticsearch.php` | Product search |
| OpenAI | `packages/Webkul/MagicAI/` | AI content generation |
| Sentry | `.env` | Error tracking |
