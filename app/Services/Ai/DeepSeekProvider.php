<?php
/**
 * Adapter DeepSeek (API kompatibel OpenAI Chat Completions).
 */

declare(strict_types=1);

final class DeepSeekProvider extends AbstractOpenAiCompatibleProvider
{
    public function id(): string
    {
        return 'deepseek';
    }

    public function label(): string
    {
        return 'DeepSeek';
    }

    public function defaultModel(): string
    {
        return 'deepseek-chat';
    }

    public function defaultBase(): string
    {
        return 'https://api.deepseek.com/v1';
    }

    public function suggestedModels(): array
    {
        return ['deepseek-chat', 'deepseek-reasoner'];
    }
}
