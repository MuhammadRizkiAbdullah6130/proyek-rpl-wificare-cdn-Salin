<?php
/**
 * Kontrak adapter provider AI WiFiCare CDN.
 *
 * Setiap provider memiliki format request/response sendiri. Kelas adapter
 * bertanggung jawab atas detail transport (endpoint, header autentikasi,
 * payload) dan pengujian koneksi nyata. Kunci API tidak pernah ditampilkan.
 */

declare(strict_types=1);

interface AiProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function defaultModel(): string;

    public function defaultBase(): string;

    /** @return array<int, string> */
    public function suggestedModels(): array;

    /**
     * Uji koneksi nyata ke provider.
     *
     * @return array{ok: bool, code: string, message: string}
     */
    public function testConnection(string $apiKey, string $model, string $base, int $timeout = 15): array;
}
