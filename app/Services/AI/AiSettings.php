<?php

namespace App\Services\AI;

/**
 * Resolves the effective AI settings.
 *
 * The mechanics (enabled, driver, model, API key) come from config/ai.php and
 * are not editable from the admin screen. Only the house style is: Admin
 * Configuration wins over config/ai.php, but only where it is actually filled
 * in — an empty field falls back to the deployed default rather than blanking
 * the setting. Mirrors AfwerkingOptieService's handling of the same screen.
 */
class AiSettings
{
    public function enabled(): bool
    {
        return (bool) config('ai.enabled');
    }

    public function driver(): string
    {
        return (string) config('ai.driver');
    }

    /**
     * @return array<string, mixed>
     */
    public function driverConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = config('ai.drivers.'.$this->driver(), []);

        return $config;
    }

    public function toneOfVoice(): ?string
    {
        return $this->configData('general.ai_texts.style.tone_of_voice');
    }

    public function extraInstructions(): ?string
    {
        return $this->configData('general.ai_texts.style.extra_instructions');
    }

    /**
     * The block's own brief ("what this text is for"), or null for the built-in one.
     */
    public function fieldInstruction(string $field): ?string
    {
        return $this->configData("general.ai_texts.{$field}.instruction");
    }

    /**
     * A tone of voice that replaces the global one for this block only.
     */
    public function fieldToneOfVoice(string $field): ?string
    {
        return $this->configData("general.ai_texts.{$field}.tone_of_voice");
    }

    /**
     * Instructions added on top of the global extra instructions, for this block only.
     */
    public function fieldExtraInstructions(string $field): ?string
    {
        return $this->configData("general.ai_texts.{$field}.extra_instructions");
    }

    /**
     * Other text blocks this block builds on, e.g. a short text that has to be
     * a summary of beschrijving_l. Detected by the field code appearing in the
     * block's brief or extra instructions.
     *
     * @return list<string>
     */
    public function fieldReferences(string $field): array
    {
        $text = implode("\n", array_filter([
            $this->fieldInstruction($field),
            $this->fieldExtraInstructions($field),
        ]));

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(
            array_keys((array) config('ai.fields')),
            fn (string $code): bool => $code !== $field
                && preg_match('/(?<![a-z0-9_])'.preg_quote($code, '/').'(?![a-z0-9_])/i', $text) === 1,
        ));
    }

    /**
     * The built-in ban list plus whatever the admin screen adds, one per line.
     *
     * @return list<string>
     */
    public function bannedPhrases(): array
    {
        /** @var list<string> $phrases */
        $phrases = config('ai.banned_phrases', []);

        return array_values(array_unique([
            ...$phrases,
            ...$this->lines($this->configData('general.ai_texts.style.banned_phrases')),
        ]));
    }

    /**
     * Phrases banned in this block only, on top of bannedPhrases().
     *
     * @return list<string>
     */
    public function fieldBannedPhrases(string $field): array
    {
        return array_values(array_diff(
            $this->lines($this->configData("general.ai_texts.{$field}.banned_phrases")),
            $this->bannedPhrases(),
        ));
    }

    /**
     * @return list<string>
     */
    private function lines(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(trim(...), preg_split('/\R/', $value) ?: []),
            fn (string $line): bool => $line !== '',
        )));
    }

    /**
     * A core_config value, or null when it was never saved or saved empty.
     */
    private function configData(string $key): ?string
    {
        $value = core()->getConfigData($key);

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? trim($value) : (string) $value;
    }
}
