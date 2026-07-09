<?php

namespace App\Clients;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP client for the De Munk Carpets dealer portal (portal.demunkcarpets.nl).
 *
 * The portal has no public API. Its Carpetconfigurator is driven by an ASP.NET
 * AJAX web service at /Api/ConfiguratorService.aspx/<Method> that returns clean
 * JSON, including live stock per article. This client logs in (WebForms) and
 * replays the configurator wizard per collection+quality to read the in-stock
 * articles from GetCarpetAlternativesForCollection.
 *
 * @phpstan-type WebArticle array<string, mixed>
 */
class DeMunkPortalClient
{
    private CookieJar $cookies;

    private bool $authenticated = false;

    private string $configuratorUrl;

    public function __construct(
        private ?string $username = null,
        private ?string $password = null,
        private ?string $baseUrl = null,
        private ?bool $verifySsl = null,
        private ?int $timeout = null,
    ) {
        $this->username ??= config('demunk.username');
        $this->password ??= config('demunk.password');
        $this->baseUrl = rtrim($this->baseUrl ?? config('demunk.base_url'), '/');
        $this->verifySsl ??= (bool) config('demunk.verify_ssl');
        $this->timeout ??= (int) config('demunk.timeout');
        $this->cookies = new CookieJar();
        $this->configuratorUrl = $this->baseUrl.'/Secure/Configurator/';
    }

    /**
     * Authenticate against the WebForms login page and retain the session cookie.
     */
    public function login(): self
    {
        if (empty($this->username) || empty($this->password)) {
            throw new RuntimeException('De Munk portal credentials are not configured (DEMUNK_USERNAME / DEMUNK_PASSWORD).');
        }

        $loginUrl = $this->baseUrl.'/Auth/Login.aspx?ReturnUrl=%2fSecure%2f';

        $html = $this->http()->get($loginUrl)->body();

        $fields = $this->extractHiddenFields($html);
        $fields['ctl00$UserContent$txtUserName'] = $this->username;
        $fields['ctl00$UserContent$txtUserPass'] = $this->password;
        $fields['ctl00$UserContent$btnValidate'] = 'Login';

        $this->http()
            ->asForm()
            ->withHeaders(['Referer' => $loginUrl])
            ->post($loginUrl, $fields);

        $secure = $this->http()->get($this->baseUrl.'/Secure/')->body();

        if (! str_contains($secure, 'Carpetconfigurator')) {
            throw new RuntimeException('De Munk portal login failed (unexpected response after authentication).');
        }

        $this->authenticated = true;

        return $this;
    }

    /**
     * List the available collection codes (BASIC, BERBER, MODERN, ...).
     *
     * @return list<string>
     */
    public function collections(): array
    {
        $this->ensureAuthenticated();

        return array_values(array_filter(array_map(
            fn (array $row): ?string => $row['Code'] ?? null,
            $this->apiPost('GetCollecties')['d'] ?? [],
        )));
    }

    /**
     * List the quality codes within a collection (e.g. MODERN -> DIAMANTE, ...).
     *
     * @return list<string>
     */
    public function qualities(string $collection): array
    {
        $this->ensureAuthenticated();
        $this->resetConfigurator();

        $this->apiPost('StoreCarpetSetting', "{setting:'collectie',value:'{$collection}'}");

        return array_values(array_filter(array_map(
            fn (array $row): ?string => $row['Code'] ?? null,
            $this->apiPost('GetKwaliteiten')['d'] ?? [],
        )));
    }

    /**
     * Replay the wizard for a single collection+quality and return every
     * in-stock article for it (all colours and sizes), as raw WebArticles.
     *
     * @return list<WebArticle>
     */
    public function stockForQuality(string $collection, string $quality): array
    {
        $this->ensureAuthenticated();
        $this->resetConfigurator();

        $ref = $this->configuratorUrl;

        // Basis instellingen
        $this->apiPost('StoreCarpetSetting', "{setting:'collectie',value:'{$collection}'}", $ref);
        $this->apiPost('GetKwaliteiten', '{}', $ref);
        $this->apiPost('StoreCarpetSetting', "{setting:'kwaliteit',value:'{$quality}'}", $ref);
        $this->apiPost('GetDessins', '{}', $ref);
        $this->apiPost('GetKnoopTechnieken', '{}', $ref);

        $articles = [];

        foreach ($this->apiPost('GetCollectieSamenstellingen', '{}', $ref)['d'] ?? [] as $composition) {
            $found = $this->stockForComposition($composition);

            foreach ($found as $article) {
                // Tag provenance: GetCarpetAlternativesForCollection returns bare
                // articles, so record which collection/quality they belong to.
                $article['_collectie'] = $collection;
                $article['_kwaliteit'] = $quality;
                $articles[$this->articleKey($article)] = $article;
            }
        }

        return array_values($articles);
    }

    /**
     * Fetch every in-stock article across all collections and qualities.
     *
     * @param  callable(string $collection, string $quality, int $found):void|null  $progress
     * @return list<WebArticle>
     */
    public function allStock(?callable $progress = null): array
    {
        $this->ensureAuthenticated();

        $all = [];

        foreach ($this->collections() as $collection) {
            foreach ($this->qualities($collection) as $quality) {
                try {
                    $articles = $this->stockForQuality($collection, $quality);
                } catch (\Throwable $e) {
                    $articles = [];
                }

                foreach ($articles as $article) {
                    $all[$this->articleKey($article)] = $article;
                }

                if ($progress !== null) {
                    $progress($collection, $quality, count($articles));
                }
            }
        }

        return array_values($all);
    }

    /**
     * Complete the carpet for one composition and read its stock alternatives.
     *
     * @param  WebArticle  $composition
     * @return list<WebArticle>
     */
    private function stockForComposition(array $composition): array
    {
        $recId = $composition['RecId'] ?? null;

        if ($recId === null) {
            return [];
        }

        $ref = $this->configuratorUrl;

        $this->apiPost('StoreCarpetSetting', "{setting:'collectiesamenstelling',value:'{$recId}'}", $ref);
        $this->apiPost('GetCollectieSamenstelling', '{}', $ref);
        $this->apiPost('GetColorInstructions', '{}', $ref);
        $this->apiPost('GetKwaliteitDetails', '{}', $ref);

        // Colours: pick any valid colour so the carpet is complete. The stock
        // alternatives cover every colour regardless of this choice.
        $palet = $composition['KleurenPallet1'] ?? '';

        if ($palet !== '') {
            $colours = $this->apiPost('GetKleuren', "{palet:'{$palet}'}", $ref)['d'] ?? [];
            $colourRecId = $colours[0]['RecId'] ?? null;

            if ($colourRecId !== null) {
                $this->apiPost('SetKleuren', "{paletId:'1', kleurenString:',{$colourRecId}'}", $ref);
                $this->apiPost('GetSelectedKleuren', '{}', $ref);
            }
        }

        // Advance the server-side step state via the real page transitions.
        $kleurUrl = $this->configuratorUrl.'KleurConfigurator.aspx';
        $maatUrl = $this->configuratorUrl.'MaatConfigurator.aspx';

        $this->getPage('KleurConfigurator.aspx', $this->configuratorUrl);
        $this->getPage('MaatConfigurator.aspx', $kleurUrl);

        // Size: pick any standard size to complete the carpet.
        $sizes = $this->apiPost('GetMaten', '{}', $maatUrl)['d'] ?? [];
        $sizeRecId = $sizes[0]['RecId'] ?? null;

        if ($sizeRecId === null) {
            return [];
        }

        $this->apiPost(
            'SetMaat',
            "{defaultMaat:'{$sizeRecId}', customWidth:'0', customLength:'0', roundCarpet:'false'}",
            $maatUrl,
        );

        $this->getPage('CarpetOverzicht.aspx', $maatUrl);
        $overviewUrl = $this->configuratorUrl.'CarpetOverzicht.aspx';

        return $this->apiPost('GetCarpetAlternativesForCollection', '{}', $overviewUrl)['d'] ?? [];
    }

    /**
     * POST a JSON body to a ConfiguratorService method and return the decoded response.
     *
     * The portal accepts JavaScript-object-literal bodies (single-quoted, unquoted
     * keys); callers build these to exactly match the portal's own requests.
     *
     * @return array<string, mixed>
     */
    private function apiPost(string $method, string $body = '{}', ?string $referer = null): array
    {
        $response = $this->http()
            ->withHeaders([
                'Content-Type'     => 'application/json; charset=utf-8',
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer'          => $referer ?? $this->configuratorUrl,
            ])
            ->withBody($body, 'application/json; charset=utf-8')
            ->post($this->baseUrl.'/Api/ConfiguratorService.aspx/'.$method);

        return $response->json() ?? [];
    }

    private function getPage(string $path, string $referer): void
    {
        $this->http()
            ->withHeaders(['Referer' => $referer])
            ->get($this->configuratorUrl.$path);
    }

    /**
     * Land on a fresh configurator page so the previous carpet state is cleared.
     */
    private function resetConfigurator(): void
    {
        $this->http()->get($this->configuratorUrl);
    }

    private function http(): PendingRequest
    {
        return Http::withOptions([
            'cookies'         => $this->cookies,
            'verify'          => $this->verifySsl,
            'allow_redirects' => true,
        ])->timeout($this->timeout);
    }

    private function ensureAuthenticated(): void
    {
        if (! $this->authenticated) {
            $this->login();
        }
    }

    /**
     * A stable de-duplication key for an in-stock article.
     *
     * @param  WebArticle  $article
     */
    private function articleKey(array $article): string
    {
        return (string) ($article['ArtikelCodeLang'] ?? $article['ArticleCode'] ?? spl_object_hash((object) $article));
    }

    /**
     * Parse the ASP.NET hidden form fields (__VIEWSTATE etc.) from a page.
     *
     * @return array<string, string>
     */
    private function extractHiddenFields(string $html): array
    {
        $fields = [];

        if (preg_match_all('/<input[^>]*type="hidden"[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                if (preg_match('/name="([^"]+)"/', $tag, $name) && preg_match('/value="([^"]*)"/', $tag, $value)) {
                    $fields[$name[1]] = html_entity_decode($value[1], ENT_QUOTES);
                }
            }
        }

        return $fields;
    }
}
