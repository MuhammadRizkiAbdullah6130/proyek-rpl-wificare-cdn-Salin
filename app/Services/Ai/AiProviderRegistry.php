<?php
/**
 * Registry provider AI yang didukung WiFiCare CDN.
 *
 * Hanya provider pada daftar ini yang boleh dipilih Admin. Nilai provider dari
 * request selalu divalidasi terhadap allowlist ini (tidak pernah dipakai
 * langsung untuk membentuk URL/kelas).
 */

declare(strict_types=1);

final class AiProviderRegistry
{
    /** @var array<string, AiProviderInterface>|null */
    private static ?array $providers = null;

    /** @return array<string, AiProviderInterface> */
    public static function all(): array
    {
        if (self::$providers === null) {
            self::$providers = [];
            foreach ([new GeminiProvider(), new DeepSeekProvider(), new OpenAiProvider(), new OllamaProvider()] as $provider) {
                self::$providers[$provider->id()] = $provider;
            }
        }

        return self::$providers;
    }

    public static function has(string $id): bool
    {
        return isset(self::all()[strtolower(trim($id))]);
    }

    public static function get(string $id): ?AiProviderInterface
    {
        return self::all()[strtolower(trim($id))] ?? null;
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    public static function label(string $id): string
    {
        return self::get($id)?->label() ?? 'Provider tidak dikenal';
    }

    /**
     * Data aman untuk form Admin (tanpa API key).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function metadata(): array
    {
        $meta = [];
        foreach (self::all() as $id => $provider) {
            $meta[$id] = [
                'id' => $id,
                'label' => $provider->label(),
                'default_model' => $provider->defaultModel(),
                'default_base' => $provider->defaultBase(),
                'models' => $provider->suggestedModels(),
            ];
        }

        return $meta;
    }
}
