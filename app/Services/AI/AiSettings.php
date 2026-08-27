<?php

namespace App\Services\AI;

/**
 * Resolves the effective AI settings.
 *
 * Admin Configuration wins over config/ai.php, but only where it is actually
 * filled in — an empty field falls back to the deployed default rather than
 * blanking the setting. Mirrors AfwerkingOptieService's handling of the same
 * screen.
 */
class AiSettings
{
    public function enabled(): bool
    {
        $configured = $this->configData('general.ai_texts.settings.enabled');

        if ($configured === null) {
            return (bool) config('ai.enabled');
        }

        return (bool) $configured;
    }

    public function driver(): string
    {
        return $this->configData('general.ai_texts.settings.driver')
            ?? (string) config('ai.driver');
    }

    /**
     * @return array<string, mixed>
     */
    public function driverConfig(): array
    {
        $driver = $this->driver();

        /** @var array<string, mixed> $config */
        $config = config("ai.drivers.{$driver}", []);

        if ($config === []) {
            return [];
        }

        if ($model = $this->configData('general.ai_texts.settings.model')) {
            $config['model'] = $model;
        }

        if ($apiKey = $this->configData('general.ai_texts.settings.api_key')) {
            $config['api_key'] = $apiKey;
        }

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
     * The built-in ban list plus whatever the admin screen adds, one per line.
     *
     * @return list<string>
     */
    public function bannedPhrases(): array
    {
        /** @var list<string> $phrases */
        $phrases = config('ai.banned_phrases', []);

        $extra = $this->configData('general.ai_texts.style.banned_phrases');

        if ($extra !== null) {
            foreach (preg_split('/\R/', $extra) ?: [] as $line) {
                $line = trim($line);

                if ($line !== '') {
                    $phrases[] = $line;
                }
            }
        }

        return array_values(array_unique($phrases));
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
