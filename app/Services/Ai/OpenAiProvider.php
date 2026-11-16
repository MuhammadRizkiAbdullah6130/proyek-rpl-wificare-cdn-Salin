<?php
/**
 * Adapter OpenAI (Chat Completions).
 */

declare(strict_types=1);

final class OpenAiProvider extends AbstractOpenAiCompatibleProvider
{
    public function id(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    public function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function defaultBase(): string
    {
        return 'https://api.openai.com/v1';
    }

    public function suggestedModels(): array
    {
        return ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1'];
    }
}
