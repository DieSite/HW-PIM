<?php

namespace App\Services\AI;

use App\Models\Product;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turns one rug into three shop texts.
 *
 * Two mechanisms fight the duplicate-content problem this feature exists for:
 * the product photo (the only genuinely unique input within a collection) and a
 * per-SKU angle plus the openings of already-written siblings, which the model
 * is told not to reuse. Without the second the model converges on one opening
 * for a whole collection even though every text is nominally new.
 *
 * @phpstan-type Result array{texts:array<string,string>, problems:list<array{field:string,rule:string,message:string}>, similarity:float, model:string, input_tokens:int, output_tokens:int, attempts:int}
 */
class ProductDescriptionGenerator
{
    /**
     * The built-in brief per text block; the admin screen can replace each one.
     */
    public const FIELD_BRIEFS = [
        'beschrijving_l' => 'Het verhaal van het kleed: hoe het eruitziet, waar het van gemaakt is, '
            .'in welk interieur het past en waar de koper op moet letten. Dit is de hoofdtekst op de productpagina.',
        'beschrijving_k' => 'Het praktische blok: welke standaardmaten er zijn, of maatwerk mogelijk is '
            .'en tot welke afmetingen, en de levertijden. Neem alleen de maten en levertijden over die in de '
            .'productgegevens staan, letterlijk zoals ze daar genoteerd zijn.',
        'meta_beschrijving' => 'De meta description voor Google. Eén zin die dit specifieke kleed onderscheidt, '
            .'plus de merknaam Huis & Wonen.',
    ];

    public function __construct(
        private readonly AiClientManager $clients,
        private readonly AiSettings $settings,
        private readonly ProductDescriptionBrief $briefBuilder,
        private readonly ProductImageResolver $images,
        private readonly DescriptionValidator $validator,
        private readonly SiblingTextRepository $siblings,
        private readonly TextRepairer $repairer,
    ) {}

    /**
     * A block that refers to another block whose text is still empty gets that
     * text written in the same call, so the result also carries the source.
     *
     * @param  list<string>|null  $fields  Attribute codes to write; defaults to all configured fields.
     * @param  array<string, mixed>  $overrides  Values from the open form that have not been saved yet.
     * @return Result
     */
    public function generate(Product $product, ?array $fields = null, array $overrides = []): array
    {
        $existing = $this->briefBuilder->common($product, $overrides);
        $fields = $this->withSources($this->fields($fields), $existing);
        $brief = $this->briefBuilder->build($product, $overrides);
        $allowedSizes = $this->briefBuilder->allowedSizes($product, $overrides);
        $siblingTexts = $this->siblings->openings($product);
        $image = $this->images->resolve($product);

        $request = new AiRequest(
            systemInstruction: $this->systemInstruction(),
            prompt: $this->prompt($brief, $fields, $product, $siblingTexts, $existing),
            images: $image === null ? [] : [$image],
            jsonSchema: $this->schema($fields),
        );

        return $this->run($request, $fields, $allowedSizes, $product);
    }

    /**
     * Generate for a product that does not exist yet, from the create form's
     * current values. No variants exist, so the model is told to leave sizes
     * out entirely rather than guess at them.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>|null  $fields
     * @return Result
     */
    public function generateFromValues(array $values, ?array $fields = null): array
    {
        $fields = $this->withSources($this->fields($fields), $values);
        $brief = $this->briefBuilder->buildFromValues($values);

        $request = new AiRequest(
            systemInstruction: $this->systemInstruction(),
            prompt: $this->prompt($brief, $fields, null, collect(), $values)
                ."\n\nDit product bestaat nog niet in het systeem, dus er is geen matenlijst. "
                .'Noem daarom geen enkele concrete maat of levertijd.',
            jsonSchema: $this->schema($fields),
        );

        return $this->run($request, $fields, [], null);
    }

    /**
     * The house style. Identical for every call, so it sits in the system
     * instruction where a provider can cache it across a bulk run.
     */
    public function systemInstruction(): string
    {
        $tone = $this->settings->toneOfVoice() ?: $this->defaultTone();
        $banned = implode("\n", array_map(fn (string $phrase): string => "- {$phrase}", $this->settings->bannedPhrases()));
        $extra = $this->settings->extraInstructions();

        $instruction = <<<TXT
        Je schrijft productteksten voor Huis & Wonen, een Nederlandse vloerkledenspecialist met een
        Experience Center in Gorinchem. Je schrijft in het Nederlands, voor particuliere klanten die
        een vloerkleed uitzoeken.

        TOON
        {$tone}

        HARDE REGELS
        - Gebruik uitsluitend feiten uit de meegeleverde productgegevens en uit wat je op de foto ziet.
          Verzin niets: geen materialen, geen percentages, geen maten, geen levertijden, geen prijzen,
          geen certificeringen, geen herkomstverhalen.
        - Noem alleen maten die letterlijk in de productgegevens staan.
        - Schrijf geen tekst over verzending, retourneren, garantietermijnen of acties, tenzij die
          gegevens zijn meegeleverd.
        - Geen aanhef, geen kopjes, geen opsommingstekens, geen call-to-action.
        - Schrijf actief en concreet. Eén idee per zin. Varieer de zinslengte.
        - Beschrijf wat er op de foto te zien is (dessin, kleurverloop, structuur, sfeer) in plaats van
          dat af te leiden uit de specificaties.

        VERMIJD DEZE FORMULERINGEN
        {$banned}

        OPMAAK
        - Lever elke tekst als HTML met alleen <p>-tags. Geen andere tags, geen attributen.
        - Schrijf accenten als gewone letters: gemêleerd, reliëf, crème. Geen HTML-entiteiten
          (&ecirc;) en geen losse leestekens in plaats van een accent (gem#leerd, gem'eleerd).
        - Antwoord uitsluitend met het gevraagde JSON-object, zonder toelichting eromheen.
        TXT;

        if ($extra) {
            $instruction .= "\n\nEXTRA INSTRUCTIES\n".$extra;
        }

        return $instruction;
    }

    /**
     * One call, then at most one retry when the text lands too close to a
     * sibling or trips a validation rule. A second failure is not dropped: it
     * becomes a draft with its problems attached, so a human decides.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $allowedSizes
     * @return Result
     */
    /**
     * One call, then at most one retry that re-asks **only for the texts that
     * failed**. Regenerating all three because the meta description came out
     * five characters short would throw away good work and re-upload the photo
     * for nothing. A second failure is not dropped: it becomes a draft with its
     * problems attached, so a human decides.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $allowedSizes
     * @return Result
     */
    private function run(AiRequest $request, array $fields, array $allowedSizes, ?Product $product): array
    {
        if (! $this->settings->enabled()) {
            throw new RuntimeException('AI-teksten staan uit in de configuratie.');
        }

        $client = $this->clients->client();
        $threshold = (float) config('ai.similarity_threshold');
        $bannedPhrases = $this->settings->bannedPhrases();
        $fieldBannedPhrases = collect($fields)
            ->mapWithKeys(fn (string $field): array => [$field => $this->settings->fieldBannedPhrases($field)])
            ->all();
        $siblingTexts = $product !== null ? $this->siblings->fullTexts($product) : [];

        $texts = [];
        $pending = $fields;
        $inputTokens = 0;
        $outputTokens = 0;
        $model = '';
        $attempt = 0;

        while ($attempt < 2) {
            $attempt++;

            $response = $client->complete($request);
            $texts = [...$texts, ...$this->extractTexts($response, $pending)];

            $model = $response->model;
            $inputTokens += $response->inputTokens;
            $outputTokens += $response->outputTokens;

            $problems = $this->validator->validate($texts, $allowedSizes, $bannedPhrases, $fieldBannedPhrases);
            $similarity = isset($texts['beschrijving_l'])
                ? $this->validator->maxSimilarity($texts['beschrijving_l'], $siblingTexts)
                : 0.0;

            $result = [
                'texts'         => $texts,
                'problems'      => $problems,
                'similarity'    => round($similarity, 3),
                'model'         => $model,
                'input_tokens'  => $inputTokens,
                'output_tokens' => $outputTokens,
                'attempts'      => $attempt,
            ];

            $tooSimilar = $similarity > $threshold;

            if ($problems === [] && ! $tooSimilar) {
                return $result;
            }

            if ($attempt === 2) {
                return $result;
            }

            $pending = $this->failedFields($fields, $problems, $tooSimilar);

            /** Nothing actionable to re-ask for; hand it to the reviewer as is. */
            if ($pending === []) {
                return $result;
            }

            /** Keep whatever passed; only the failures go back to the model. */
            $texts = array_diff_key($texts, array_flip($pending));

            $request = $request->retryWith(
                $request->prompt."\n\n".$this->correction($pending, $problems, $tooSimilar, $texts),
                $this->schema($pending),
                in_array('beschrijving_l', $pending, true),
            );
        }

        /** @var Result $result */
        return $result;
    }

    /**
     * Which texts have to be written again: the ones that tripped a rule, plus
     * the long description when it reads too much like a sibling.
     *
     * @param  list<string>  $fields
     * @param  list<array{field:string, rule:string, message:string}>  $problems
     * @return list<string>
     */
    private function failedFields(array $fields, array $problems, bool $tooSimilar): array
    {
        $failed = array_column($problems, 'field');

        if ($tooSimilar) {
            $failed[] = 'beschrijving_l';
        }

        /** A text built on a rewritten text has to follow it, or it describes the old one. */
        do {
            $added = false;

            foreach ($fields as $field) {
                if (! in_array($field, $failed, true) && array_intersect($this->settings->fieldReferences($field), $failed) !== []) {
                    $failed[] = $field;
                    $added = true;
                }
            }
        } while ($added);

        return array_values(array_intersect($fields, array_unique($failed)));
    }

    /**
     * The requested fields plus every block they refer to that has no text yet,
     * ordered so a source is always written before the text built on it.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $existing
     * @return list<string>
     */
    private function withSources(array $fields, array $existing): array
    {
        $queue = $fields;

        while ($queue !== []) {
            foreach ($this->settings->fieldReferences(array_shift($queue)) as $source) {
                if (! in_array($source, $fields, true) && $this->existingText($existing, $source) === null) {
                    $fields[] = $source;
                    $queue[] = $source;
                }
            }
        }

        $ordered = [];
        $visiting = [];

        $visit = function (string $field) use (&$visit, &$ordered, &$visiting, $fields): void {
            if (isset($visiting[$field])) {
                return;
            }

            $visiting[$field] = true;

            foreach ($this->settings->fieldReferences($field) as $source) {
                if (in_array($source, $fields, true)) {
                    $visit($source);
                }
            }

            $ordered[] = $field;
        };

        foreach (array_keys((array) config('ai.fields')) as $code) {
            if (in_array($code, $fields, true)) {
                $visit($code);
            }
        }

        return $ordered;
    }

    /**
     * How the requested texts relate to each other, plus the current text of
     * every referenced block that is not being rewritten in this call.
     *
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $existing
     */
    private function referenceBriefs(array $fields, array $existing): string
    {
        /** @var array<string, array{label:string}> $config */
        $config = config('ai.fields');

        $lines = [];
        $quoted = [];

        foreach ($fields as $field) {
            foreach ($this->settings->fieldReferences($field) as $source) {
                if (in_array($source, $fields, true)) {
                    $lines[] = "- {$field} bouwt voort op {$source}: schrijf eerst {$source} en baseer {$field} op precies die tekst.";
                } elseif (($text = $this->existingText($existing, $source)) !== null) {
                    $lines[] = "- {$field} bouwt voort op de huidige tekst van {$source}, hieronder.";
                    $quoted[$source] = $text;
                }
            }
        }

        if ($lines === []) {
            return '';
        }

        $brief = "VERWIJZINGEN TUSSEN TEKSTEN\n".implode("\n", $lines);

        foreach ($quoted as $source => $text) {
            $label = $config[$source]['label'] ?? $source;
            $brief .= "\n\nHUIDIGE TEKST VAN {$source} ({$label})\n{$text}";
        }

        return $brief;
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function existingText(array $existing, string $field): ?string
    {
        $value = $existing[$field] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $plain = $this->validator->plain($value);

        return $plain === '' || strtolower($plain) === 'null' ? null : $plain;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  list<string>  $fields
     * @param  Collection<int, string>  $siblingOpenings
     * @param  array<string, mixed>  $existing  Current attribute values, form values included.
     */
    private function prompt(array $brief, array $fields, ?Product $product, Collection $siblingOpenings, array $existing = []): string
    {
        $json = json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = "PRODUCTGEGEVENS\n{$json}\n\nGEVRAAGDE TEKSTEN\n".$this->fieldBriefs($fields);

        if ($references = $this->referenceBriefs($fields, $existing)) {
            $prompt .= "\n\n".$references;
        }

        if ($product !== null) {
            $prompt .= "\n\nINVALSHOEK\n".$this->angle((string) $product->sku);
        }

        if ($siblingOpenings->isNotEmpty()) {
            $openings = $siblingOpenings
                ->map(fn (string $opening): string => "- {$opening}")
                ->implode("\n");

            $prompt .= <<<TXT


            AL GEBRUIKTE OPENINGEN BIJ ZUSTERPRODUCTEN
            Deze zinnen staan al bij andere kleden uit dezelfde collectie. Hergebruik ze niet en kies
            een andere invalshoek dan deze:
            {$openings}
            TXT;
        }

        return $prompt;
    }

    /**
     * What each field is for. Written out because the field names alone do not
     * say that "beschrijving_k" is the sizes-and-delivery block.
     *
     * The block settings from the admin screen go here rather than into the
     * system instruction, so that stays identical for every call and cacheable.
     *
     * @param  list<string>  $fields
     */
    private function fieldBriefs(array $fields): string
    {
        /** @var array<string, array{label:string, min:int, max:int}> $config */
        $config = config('ai.fields');

        return collect($fields)
            ->map(function (string $field) use ($config): string {
                $rules = $config[$field];
                $instruction = $this->settings->fieldInstruction($field) ?? self::FIELD_BRIEFS[$field] ?? '';

                $lines = ["- {$field} ({$rules['label']}, {$rules['min']}-{$rules['max']} tekens platte tekst): {$instruction}"];

                if ($tone = $this->settings->fieldToneOfVoice($field)) {
                    $lines[] = "  Toon voor deze tekst (gaat voor de algemene toon): {$tone}";
                }

                if ($extra = $this->settings->fieldExtraInstructions($field)) {
                    $lines[] = "  Extra instructies voor deze tekst: {$extra}";
                }

                if ($banned = $this->settings->fieldBannedPhrases($field)) {
                    $lines[] = '  Vermijd in deze tekst ook: '.implode('; ', $banned);
                }

                return implode("\n", $lines);
            })
            ->implode("\n");
    }

    /**
     * Picked deterministically from the SKU so the same product always gets the
     * same angle, and neighbouring products in a collection get different ones.
     */
    private function angle(string $sku): string
    {
        /** @var list<string> $angles */
        $angles = config('ai.angles');

        return $angles[crc32($sku) % count($angles)];
    }

    /**
     * The follow-up instruction: what went wrong, which texts to write again,
     * and which ones are already approved so the model stays consistent with
     * them instead of contradicting what it wrote a moment ago.
     *
     * @param  list<string>  $pending  Fields being re-asked for.
     * @param  list<array{field:string, rule:string, message:string}>  $problems
     * @param  array<string, string>  $accepted  Texts that passed and are kept.
     */
    private function correction(array $pending, array $problems, bool $tooSimilar, array $accepted): string
    {
        /** @var array<string, array{label:string}> $config */
        $config = config('ai.fields');

        $labels = implode(', ', array_map(fn (string $field): string => $config[$field]['label'] ?? $field, $pending));

        $lines = [
            "CORRECTIE — schrijf alleen deze tekst(en) opnieuw: {$labels}.",
            'Wat er mis was:',
        ];

        foreach ($problems as $problem) {
            if (in_array($problem['field'], $pending, true)) {
                $lines[] = "- {$problem['message']}";
            }
        }

        if ($tooSimilar && in_array('beschrijving_l', $pending, true)) {
            $lines[] = '- De lange beschrijving lijkt te veel op die van een zusterproduct. '
                .'Kies een andere invalshoek, een andere openingszin en een andere zinsopbouw.';
        }

        if ($accepted !== []) {
            $lines[] = '';
            $lines[] = 'Deze teksten zijn al goedgekeurd en blijven staan. Spreek ze niet tegen en herhaal ze niet:';

            foreach ($accepted as $code => $text) {
                $label = $config[$code]['label'] ?? $code;
                $lines[] = "- {$label}: \"".$this->validator->plain($text).'"';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function schema(array $fields): array
    {
        $properties = [];

        foreach ($fields as $field) {
            $properties[$field] = ['type' => 'string'];
        }

        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $fields,
            'propertyOrdering'     => $fields,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function extractTexts(AiResponse $response, array $fields): array
    {
        $decoded = $response->json();
        $texts = [];

        foreach ($fields as $field) {
            $value = $decoded[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException("Model leverde geen tekst voor \"{$field}\".");
            }

            $texts[$field] = $this->validator->normaliseHtml($this->repairer->repair($value));
        }

        return $texts;
    }

    /**
     * @param  list<string>|null  $fields
     * @return list<string>
     */
    private function fields(?array $fields): array
    {
        /** @var array<string, mixed> $configured */
        $configured = config('ai.fields');
        $allowed = array_keys($configured);

        if ($fields === null || $fields === []) {
            return $allowed;
        }

        $selected = array_values(array_intersect($allowed, $fields));

        if ($selected === []) {
            throw new RuntimeException('Geen geldige velden opgegeven.');
        }

        return $selected;
    }

    private function defaultTone(): string
    {
        return <<<'TXT'
        Zakelijk-informatief en nuchter, zoals een ervaren verkoper in de showroom. Behulpzaam,
        niet wervend. Je mag een kleed mooi noemen, maar onderbouwt dat met wat je ziet. Geen
        superlatieven, geen marketingtaal, geen uitroeptekens.
        TXT;
    }
}
