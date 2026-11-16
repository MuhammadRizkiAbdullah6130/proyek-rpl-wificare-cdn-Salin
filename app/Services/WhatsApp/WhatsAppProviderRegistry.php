<?php
/**
 * Allowlist adapter WhatsApp yang benar-benar didukung.
 */

declare(strict_types=1);

final class WhatsAppProviderRegistry
{
    /** @var array<string, WhatsAppProviderInterface>|null */
    private static ?array $providers = null;

    /** @return array<string, WhatsAppProviderInterface> */
    public static function all(): array
    {
        if (self::$providers === null) {
            self::$providers = [];
            foreach ([new WhatsAppCloudProvider(), new WhatsAppFonnteProvider()] as $provider) {
                self::$providers[$provider->id()] = $provider;
            }
        }

        return self::$providers;
    }

    public static function has(string $id): bool
    {
        return isset(self::all()[strtolower(trim($id))]);
    }

    public static function get(string $id): ?WhatsAppProviderInterface
    {
        return self::all()[strtolower(trim($id))] ?? null;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::all() as $id => $provider) {
            $labels[$id] = $provider->label();
        }

        return $labels;
    }
}
