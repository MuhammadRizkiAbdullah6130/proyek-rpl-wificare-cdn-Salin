<?php
/**
 * Kontrak adapter provider WhatsApp Business API.
 *
 * Semua fitur (OTP, notifikasi, laporan) memakai adapter yang sama. Tidak ada
 * kode HTTP terpisah per fitur.
 */

declare(strict_types=1);

interface WhatsAppProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function defaultBase(): string;

    /**
     * Apakah provider mendukung template pesan (mis. Authentication Meta).
     * Provider teks-saja wajib memakai sendText untuk OTP.
     */
    public function supportsTemplates(): bool;

    /**
     * Uji koneksi nyata. Tidak pernah mengembalikan token.
     *
     * @param array<string, mixed> $credential
     * @return array{ok:bool,code:string,message:string,duration_ms:int}
     */
    public function testConnection(array $credential, int $timeout = 20): array;

    /**
     * @param array<string, mixed> $credential
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int}
     */
    public function sendText(array $credential, string $to, string $body, int $timeout = 20): array;

    /**
     * @param array<string, mixed> $credential
     * @param array<int, string> $parameters
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int}
     */
    public function sendTemplate(
        array $credential,
        string $to,
        string $templateName,
        string $languageCode,
        array $parameters,
        int $timeout = 20,
        bool $authenticationTemplate = false
    ): array;

    /**
     * Unggah media lalu kirim sebagai dokumen. Untuk laporan yang disetujui.
     *
     * @param array<string, mixed> $credential
     * @return array{ok:bool,status:string,code:string,message:string,provider_message_id:string,duration_ms:int}
     */
    public function sendDocument(
        array $credential,
        string $to,
        string $filePath,
        string $filename,
        string $caption = '',
        int $timeout = 30
    ): array;
}
