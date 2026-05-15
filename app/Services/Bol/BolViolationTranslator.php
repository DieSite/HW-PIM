<?php

namespace App\Services\Bol;

use Illuminate\Http\Client\RequestException;

class BolViolationTranslator
{
    /**
     * Map a Bol.com API error response into a customer-friendly Dutch message.
     */
    public function translate(\Throwable $exception): string
    {
        $request = $this->extractRequestException($exception);

        if (! $request) {
            return 'Er ging iets mis bij het versturen naar Bol.com. Probeer het later opnieuw.';
        }

        $status = $request->response->status();
        $body = $request->response->json();

        if ($status === 401 || $status === 403) {
            return 'De Bol.com koppeling is niet geautoriseerd. Controleer de inloggegevens van het account.';
        }

        if ($status === 429) {
            return 'Bol.com heeft het rate limit toegepast. We proberen het later automatisch opnieuw.';
        }

        if ($status >= 500) {
            return 'Bol.com is tijdelijk niet bereikbaar. We proberen het automatisch opnieuw.';
        }

        if ($status === 404) {
            return 'Het product is niet gevonden bij Bol.com. Mogelijk is het al verwijderd.';
        }

        $violations = $body['violations'] ?? [];

        if (! empty($violations)) {
            $parts = [];
            foreach ($violations as $violation) {
                $parts[] = $this->translateViolation($violation);
            }

            return implode(' ', array_unique(array_filter($parts)));
        }

        $detail = $body['detail'] ?? $body['title'] ?? null;
        if ($detail) {
            return sprintf('Bol.com heeft de aanvraag geweigerd: %s', $detail);
        }

        return 'Bol.com heeft de aanvraag geweigerd. Controleer de productgegevens en probeer opnieuw.';
    }

    private function translateViolation(array $violation): string
    {
        $name = strtolower($violation['name'] ?? '');
        $reason = $violation['reason'] ?? '';

        return match (true) {
            $name === 'ean'                                => 'De EAN-code is ongeldig of niet bekend bij Bol.com.',
            $name === 'unknownproducttitle', $name === 'title' => 'De productnaam wordt door Bol.com afgewezen. Houd de naam binnen de toegestane lengte en zonder verboden tekens.',
            str_contains($name, 'pricing'), str_contains($name, 'price') => 'De prijs wordt door Bol.com afgewezen. Controleer de verkoopprijs.',
            str_contains($name, 'stock'), str_contains($name, 'amount')  => 'De voorraad wordt door Bol.com afgewezen.',
            str_contains($name, 'fulfilment')                            => 'De levermethode wordt door Bol.com afgewezen.',
            str_contains($name, 'asset'), str_contains($name, 'image')   => 'Een of meer afbeeldingen worden door Bol.com afgewezen.',
            str_contains($name, 'attribute')                             => 'Een van de productkenmerken wordt door Bol.com afgewezen: '.$reason,
            default                                                      => sprintf('Bol.com weigert "%s": %s', $violation['name'] ?? 'onbekend veld', $reason),
        };
    }

    private function extractRequestException(\Throwable $e): ?RequestException
    {
        $current = $e;
        while ($current) {
            if ($current instanceof RequestException) {
                return $current;
            }
            $current = $current->getPrevious();
        }

        return null;
    }
}
