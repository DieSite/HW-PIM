<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/**
 * Checks a generated text before it is allowed to become a draft.
 *
 * The size check is the important one. "Beschrijving kort" is in practice the
 * sizes-and-lead-times block, so a hallucinated measurement lands straight on a
 * product page as a promise we cannot keep.
 *
 * @phpstan-type Problem array{field:string, rule:string, message:string}
 */
class DescriptionValidator
{
    /**
     * Matches "170 cm x 240 cm", "200 cm", "400 cm" and bare "170 x 240".
     */
    private const MEASUREMENT = '/\b\d{2,4}\s*(?:cm)?\s*(?:x|×)\s*\d{2,4}\s*(?:cm)?\b|\b\d{2,4}\s*cm\b/i';

    /**
     * @param  array<string, string>  $texts  field code => generated text
     * @param  list<string>  $allowedSizes
     * @param  list<string>  $bannedPhrases
     * @return list<Problem>
     */
    public function validate(array $texts, array $allowedSizes, array $bannedPhrases = []): array
    {
        $problems = [];

        /** @var array<string, array{label:string, min:int, max:int, html:bool}> $fields */
        $fields = config('ai.fields');

        foreach ($fields as $code => $rules) {
            if (! array_key_exists($code, $texts)) {
                continue;
            }

            $plain = $this->plain($texts[$code]);

            if ($plain === '') {
                $problems[] = $this->problem($code, 'empty', "{$rules['label']} is leeg.");

                continue;
            }

            $length = mb_strlen($plain);

            if ($length < $rules['min']) {
                $problems[] = $this->problem($code, 'too_short', "{$rules['label']} is {$length} tekens, minimaal {$rules['min']}.");
            }

            if ($length > $rules['max']) {
                $problems[] = $this->problem($code, 'too_long', "{$rules['label']} is {$length} tekens, maximaal {$rules['max']}.");
            }

            foreach ($this->inventedSizes($plain, $allowedSizes) as $size) {
                $problems[] = $this->problem($code, 'invented_size', "{$rules['label']} noemt maat \"{$size}\" die niet in het assortiment staat.");
            }

            foreach ($this->usedBannedPhrases($plain, $bannedPhrases) as $phrase) {
                $problems[] = $this->problem($code, 'banned_phrase', "{$rules['label']} gebruikt de verboden formulering \"{$phrase}\".");
            }
        }

        return $problems;
    }

    /**
     * Measurements in the text that do not appear in the product's real size
     * list. Comparison is on digits only, so "170 cm x 240 cm", "170x240" and
     * "170 x 240 cm" all match the same variant.
     *
     * @param  list<string>  $allowedSizes
     * @return list<string>
     */
    public function inventedSizes(string $text, array $allowedSizes): array
    {
        $allowed = [];

        foreach ($allowedSizes as $size) {
            foreach ($this->digitGroups($size) as $group) {
                $allowed[$group] = true;
            }
        }

        preg_match_all(self::MEASUREMENT, $text, $matches);

        $invented = [];

        foreach ($matches[0] ?? [] as $match) {
            $groups = $this->digitGroups($match);

            if ($groups === []) {
                continue;
            }

            foreach ($groups as $group) {
                if (! isset($allowed[$group])) {
                    $invented[trim($match)] = true;

                    break;
                }
            }
        }

        return array_keys($invented);
    }

    /**
     * @param  list<string>  $bannedPhrases
     * @return list<string>
     */
    public function usedBannedPhrases(string $text, array $bannedPhrases): array
    {
        $lower = Str::lower($text);

        return array_values(array_filter(
            $bannedPhrases,
            fn (string $phrase): bool => $phrase !== '' && str_contains($lower, Str::lower($phrase))
        ));
    }

    /**
     * Jaccard similarity over 5-word shingles. Used to catch texts that are
     * technically new but say the same thing as a sibling in the collection.
     */
    public function similarity(string $left, string $right): float
    {
        $a = $this->shingles($this->plain($left));
        $b = $this->shingles($this->plain($right));

        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    /**
     * The highest similarity against any of the given texts.
     *
     * @param  list<string>  $others
     */
    public function maxSimilarity(string $text, array $others): float
    {
        $max = 0.0;

        foreach ($others as $other) {
            $max = max($max, $this->similarity($text, $other));
        }

        return $max;
    }

    /**
     * Wrap a text in a single paragraph if it is not already HTML.
     *
     * The catalogue is inconsistent here — 1.044 long descriptions are wrapped
     * in <p>, 2.414 are bare — so everything this feature writes is normalised.
     */
    public function normaliseHtml(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (Str::startsWith($text, '<')) {
            return $text;
        }

        $paragraphs = preg_split('/\R{2,}/', $text) ?: [$text];

        return collect($paragraphs)
            ->map(trim(...))
            ->filter()
            ->map(fn (string $paragraph): string => '<p>'.nl2br(e($paragraph), false).'</p>')
            ->implode('');
    }

    public function plain(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/p>|<\/li>/i', ' ', $html) ?? $html;

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text))));
    }

    /**
     * @return list<string>
     */
    private function digitGroups(string $value): array
    {
        preg_match_all('/\d{2,4}/', $value, $matches);

        return array_values($matches[0] ?? []);
    }

    /**
     * @return array<string, true>
     */
    private function shingles(string $text, int $size = 5): array
    {
        $words = preg_split('/\W+/u', Str::lower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) < $size) {
            return $words === [] ? [] : [implode(' ', $words) => true];
        }

        $shingles = [];

        for ($i = 0; $i + $size <= count($words); $i++) {
            $shingles[implode(' ', array_slice($words, $i, $size))] = true;
        }

        return $shingles;
    }

    /**
     * @return Problem
     */
    private function problem(string $field, string $rule, string $message): array
    {
        return ['field' => $field, 'rule' => $rule, 'message' => $message];
    }
}
