<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetFamilyAttributesTool;
use App\Mcp\Tools\GetProductsTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Mcp\Tools\UpsertProductsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('HW PIM Products')]
#[Version('1.0.0')]
#[Instructions(<<<'MD'
    Read, create and update products in the HW Huis & Wonen PIM (UnoPim), typically to enter manufacturer data in bulk. Products cannot be deleted.

    Data model:
    - A rug is a configurable parent product with variants (simple products with a parent). The variant axes ("super attributes") are usually onderkleed, maatgroep and afwerking_beschikbaar.
    - Product-level data (productnaam, merk, collectie, materiaal, beschrijving_l, ...) lives on the parent. Size-level data (maat, prijs, adviesverkoopprijs, ean, stock) lives on each variant. A new variant starts as a copy of its parent's values; later parent changes are NOT copied to existing variants, so update variants explicitly when a value must change there too.
    - Values are grouped in sections: common, locale_specific, channel_specific, channel_locale_specific, plus categories (a list of category codes). get-family-attributes tells you the section, type and options of every attribute. Almost all attributes are common.
    - Attribute codes are Dutch. Select attributes take an option code; price attributes take {"EUR": "329"}; WYSIWYG textareas take HTML.

    Workflow:
    1. get-family-attributes (without arguments, then with the family) to learn the attribute codes and options.
    2. search-products / get-products to find what already exists.
    3. upsert-products with dry_run: true, check the reported before/after changes and errors, then run it again without dry_run.

    Every saved product is synced to WooCommerce (and Bol.com when enabled), so prefer one well-checked batch over many small corrections.
    MD)]
class ProductsServer extends Server
{
    protected array $tools = [
        GetFamilyAttributesTool::class,
        SearchProductsTool::class,
        GetProductsTool::class,
        UpsertProductsTool::class,
    ];
}
