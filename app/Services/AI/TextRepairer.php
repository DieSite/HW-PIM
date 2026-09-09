<?php

namespace App\Services\AI;

/**
 * Repairs the diacritics a model mangles on its way out.
 *
 * Gemini writes "gemêleerd" correctly in most calls, but in roughly one in
 * twenty it drops a stray byte in place of the accented vowel ("gem#leerd",
 * "gem6leerd", "gem'eleerd") or falls back to an HTML entity ("gem&ecirc;
 * leerde"). Nothing in our own pipeline touches the bytes — the corruption is
 * in the response — so it is repaired here, at the one point where model text
 * becomes stored text.
 *
 * Entities are decoded generically; the mangled spellings cannot be, because
 * "gem#leerd" carries no information about whether the missing letter was ê, ë
 * or è. Those go through the word list in config('ai.text_repairs'), and
 * anything left over is reported by garbledWords() so the generator can re-ask
 * the model and the reviewer sees a flag instead of a published "gem#leerd".
 */
class TextRepairer
{
    /**
     * Characters a model has been seen to leave behind in place of an accented
     * vowel. None of them occur inside a Dutch word legitimately.
     */
    private const JUNK_IN_WORD = '/\p{L}[#`^~]\p{L}|\p{L}\d\p{L}/u';

    /**
     * An apostrophe is only junk when a vowel follows it: "gem'eleerd" and
     * "reli'ef" are mangled, while "auto's", "z'n" and "'s morgens" are not.
     */
    private const JUNK_APOSTROPHE = "/\p{L}'[aeiouAEIOU]/u";

    public function repair(string $text): string
    {
        return $this->repairKnownWords($this->decodeAccentEntities($text));
    }

    /**
     * Words that still carry a mangled character after repairing, lowercased and
     * deduplicated.
     *
     * @return list<string>
     */
    public function garbledWords(string $text): array
    {
        preg_match_all('/\S*\p{L}[#`^~\d\']\p{L}\S*/u', $text, $matches);

        $garbled = [];

        foreach ($matches[0] ?? [] as $word) {
            if (preg_match(self::JUNK_IN_WORD, $word) || preg_match(self::JUNK_APOSTROPHE, $word)) {
                $garbled[mb_strtolower(trim($word, '.,;:!?()"<>'))] = true;
            }
        }

        return array_keys($garbled);
    }

    /**
     * Turn "&ecirc;" and "&#234;" back into "ê".
     *
     * Only entities that decode to a letter are touched, which leaves the
     * structural ones ("&amp;", "&lt;", "&nbsp;", "&times;") exactly as the
     * model wrote them — they are legitimate in the HTML these texts become.
     */
    private function decodeAccentEntities(string $text): string
    {
        return (string) preg_replace_callback(
            '/&(?:[a-zA-Z][a-zA-Z0-9]{1,9}|#\d{2,5}|#[xX][0-9a-fA-F]{2,5});/',
            function (array $match): string {
                $decoded = html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return preg_match('/^\p{L}$/u', $decoded) === 1 ? $decoded : $match[0];
            },
            $text
        );
    }

    /**
     * Replace the mangled spellings we have actually seen. Matching is on the
     * stem, so "gem#leerd" also repairs "gem#leerde"; the first letter keeps the
     * case it had, which is all these words need mid-sentence or at the start.
     */
    private function repairKnownWords(string $text): string
    {
        /** @var array<string, string> $repairs */
        $repairs = config('ai.text_repairs', []);

        foreach ($repairs as $garbled => $correct) {
            $text = (string) preg_replace_callback(
                '/'.preg_quote($garbled, '/').'/iu',
                fn (array $match): string => $this->matchCase($match[0], $correct),
                $text
            );
        }

        return $text;
    }

    private function matchCase(string $original, string $replacement): string
    {
        $first = mb_substr($original, 0, 1);

        return mb_strtoupper($first) === $first && mb_strtolower($first) !== $first
            ? mb_strtoupper(mb_substr($replacement, 0, 1)).mb_substr($replacement, 1)
            : $replacement;
    }
}
