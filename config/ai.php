<?php

/*
|--------------------------------------------------------------------------
| AI text generation
|--------------------------------------------------------------------------
|
| Drives the AI product description generator. The driver is swappable; the
| shop-facing defaults (tone of voice, length bounds, banned phrases) are also
| exposed in the admin Configuration screen via config/ai_settings.php, which
| takes precedence when filled.
|
*/

return [

    'enabled' => env('AI_ENABLED', true),

    /**
     * Default driver. Gemini is the house default: it is vision-capable and an
     * order of magnitude cheaper per product than the Opus/GPT tiers, which
     * matters for runs across thousands of products.
     */
    'driver' => env('AI_DRIVER', 'gemini'),

    'drivers' => [

        'gemini' => [
            'api_key'  => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
            'model'    => env('GEMINI_MODEL', 'gemini-3.7-flash'),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model'   => env('OPENAI_MODEL', 'gpt-5'),
        ],
    ],

    'request' => [
        'timeout'     => (int) env('AI_TIMEOUT', 120),
        'max_tokens'  => (int) env('AI_MAX_TOKENS', 4096),
        'temperature' => (float) env('AI_TEMPERATURE', 1.0),
        'retries'     => (int) env('AI_RETRIES', 2),
    ],

    /**
     * Bumped whenever the prompt changes materially, so drafts can be traced
     * back to the wording that produced them.
     */
    'prompt_version' => '1',

    /**
     * Attribute codes the generator may write, and the length window each text
     * has to land in. Derived from the current catalogue: beschrijving_l
     * averages 614 characters, beschrijving_k 461, meta_beschrijving 153.
     *
     * Lengths are measured on the plain text, after stripping the HTML wrapper.
     */
    'fields' => [
        'beschrijving_l' => [
            'label' => 'Beschrijving lang',
            'min'   => 400,
            'max'   => 1100,
            'html'  => true,
        ],
        'beschrijving_k' => [
            'label' => 'Beschrijving kort',
            'min'   => 250,
            'max'   => 800,
            'html'  => true,
        ],
        'meta_beschrijving' => [
            'label' => 'Meta beschrijving',
            'min'   => 110,
            'max'   => 175,
            'html'  => true,
        ],
    ],

    /**
     * Jaccard similarity (5-gram shingles) against already generated siblings in
     * the same collection. Above this a regeneration is attempted once; if the
     * text is still too close it is flagged for review rather than dropped.
     */
    'similarity_threshold' => (float) env('AI_SIMILARITY_THRESHOLD', 0.45),

    /**
     * Deterministically picked per SKU. Without a forced angle the model
     * converges on one opening for every product in a collection, which is the
     * duplicate-content problem this feature exists to solve.
     */
    'angles' => [
        'Open met de kleur en wat die met een ruimte doet.',
        'Open met de ruimte of het interieur waar dit kleed tot zijn recht komt.',
        'Open met het ambacht: hoe het gemaakt is en wat dat oplevert.',
        'Open met de textuur en hoe het kleed aanvoelt onder de voet.',
        'Open met het dessin dat op de foto te zien is.',
    ],

    /**
     * Formulations that read as AI filler or as empty superlatives. Passed to
     * the model as a ban list; the admin Configuration field is appended to it.
     */
    'banned_phrases' => [
        'in het hart van',
        'niet alleen',
        'maar ook',
        'of het nu',
        'in de wereld van',
        'een ware',
        'ware blikvanger',
        'oogverblindend',
        'adembenemend',
        'onmisbaar',
        'de perfecte toevoeging',
        'transformeert',
        'straalt luxe uit',
        'tijdloze elegantie',
        'ultieme',
        'stijlvolle uitstraling die',
        'kortom',
        'duik in',
    ],

    /**
     * Number of sibling openings fed to the model as "do not reuse these".
     */
    'sibling_examples' => 3,
];
