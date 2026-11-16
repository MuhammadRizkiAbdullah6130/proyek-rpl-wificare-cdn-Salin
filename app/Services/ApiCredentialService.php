<?php
/**
 * API Credentials Service reusable (Tahap 17).
 *
 * Tanggung jawab:
 * - Menyimpan/mengganti/mencabut kredensial WhatsApp dengan enkripsi libsodium.
 * - Menandai koneksi aktif dan memilih koneksi per fitur.
 * - Mengetes koneksi nyata + mencatat waktu/status terakhir.
 * - Menyediakan data aman untuk tampilan (tanpa secret/ciphertext).
 *
 * Integrasi AI tetap memakai `ai_provider_keys` (sudah terenkripsi) dan
 * pengaturan multi-provider yang ada; service ini tidak menggandakan key AI.
 */

declare(strict_types=1);

final class ApiCredentialService
{
    public const INTEGRATION_WHATSAPP = 'whatsapp';

    /** @return array<int, array<string, mixed>> */
    public static function whatsappConnections(): array
    {
        $result = [];
        foreach (ApiCredential::all(self::INTEGRATION_WHATSAPP) as $row) {
            $result[] = ApiCredential::safe($row);
        }

        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public static function activeConnections(): array
    {
        return array_values(array_filter(
            self::whatsappConnections(),
            static fn (array $row): bool => !empty($row['is_active']) && empty($row['is_revoked'])
        ));
    }

    /** @return array<string, mixed>|null */
    public static function safe(int $id): ?array
    {
        $row = ApiCredential::find($id);

        return $row === null ? null : ApiCredential::safe($row);
    }

    /**
     * Kredensial internal (mengandung token hasil dekripsi). HANYA untuk adapter.
     *
     * @return array<string, mixed>|null
     */
    public static function connection(int $id): ?array
    {
        $row = ApiCredential::find($id);
        if ($row === null || ($row['revoked_at'] ?? null) !== null) {
            return null;
        }

        $token = Crypto::decrypt((string) ($row['secret_encrypted'] ?? ''));
        if ($token === null || $token === '') {
            return null;
        }

        $config = ApiCredential::config($row);

        return [
            'id' => (int) $row['id'],
            'provider' => (string) $row['provider'],
            'name' => (string) $row['name'],
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
            'is_revoked' => false,
            'token' => $token,
            'business_account_id' => (string) ($config['business_account_id'] ?? ''),
            'phone_number_id' => (string) ($config['phone_number_id'] ?? ''),
            'sender_number' => (string) ($config['sender_number'] ?? ''),
            'base_url' => (string) ($config['base_url'] ?? ''),
            'webhook_verify_token' => (string) (Crypto::decrypt((string) ($row['webhook_verify_token_encrypted'] ?? '')) ?? ''),
            'webhook_secret' => (string) (Crypto::decrypt((string) ($row['webhook_secret_encrypted'] ?? '')) ?? ''),
        ];
    }

    /**
     * Koneksi efektif untuk fitur: mapping khusus bila ada, jika tidak koneksi
     * WhatsApp aktif (berbagi kredensial).
     *
     * @return array<string, mixed>|null
     */
    public static function connectionForFeature(string $feature): ?array
    {
        $connectionId = ApiIntegrationSetting::connectionId($feature);
        if ($connectionId !== null) {
            return self::connection($connectionId);
        }

        $active = ApiCredential::active(self::INTEGRATION_WHATSAPP);

        return $active === null ? null : self::connection((int) $active['id']);
    }

    /**
     * Provider efektif untuk sebuah fitur, tanpa mendekripsi secret.
     */
    public static function providerForFeature(string $feature): string
    {
        $connectionId = ApiIntegrationSetting::connectionId($feature);
        $row = $connectionId !== null
            ? ApiCredential::find($connectionId)
            : ApiCredential::active(self::INTEGRATION_WHATSAPP);

        return strtolower(trim((string) ($row['provider'] ?? '')));
    }

    /**
     * Provider efektif untuk koneksi tertentu, tanpa mendekripsi secret.
     */
    public static function providerForConnection(int $id): string
    {
        $row = ApiCredential::find($id);

        return strtolower(trim((string) ($row['provider'] ?? '')));
    }

    public static function providerSupportsTemplates(string $provider): bool
    {
        $adapter = WhatsAppProviderRegistry::get($provider);

        return $adapter !== null && $adapter->supportsTemplates();
    }

    /**
     * @return array<string, string>
     */
    public static function connectionOptions(): array
    {
        $options = ['' => 'Otomatis — koneksi WhatsApp aktif'];
        foreach (self::whatsappConnections() as $row) {
            if (!empty($row['is_revoked'])) {
                continue;
            }
            $label = (string) $row['name'];
            if (!empty($row['sender_number'])) {
                $label .= ' (' . (string) $row['sender_number'] . ')';
            }
            if (empty($row['is_active'])) {
                $label .= ' — tidak aktif';
            }
            $options[(string) $row['id']] = $label;
        }

        return $options;
    }

    /**
     * Simpan kredensial WhatsApp baru atau ganti kredensial lama.
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function saveWhatsApp(array $actor, array $input): int
    {
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? ApiCredential::find($id) : null;
        if ($id > 0 && ($existing === null || ($existing['revoked_at'] ?? null) !== null)) {
            throw new ValidationException(['kredensial' => 'Kredensial tidak ditemukan atau sudah dicabut.']);
        }

        $provider = strtolower(trim((string) ($input['provider'] ?? 'meta_cloud')));
        $name = trim((string) ($input['name'] ?? ''));
        $phoneId = trim((string) ($input['phone_number_id'] ?? ''));
        $businessId = trim((string) ($input['business_account_id'] ?? ''));
        $senderRaw = trim((string) ($input['sender_number'] ?? ''));
        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        $token = trim((string) ($input['access_token'] ?? ''));
        $webhookVerify = trim((string) ($input['webhook_verify_token'] ?? ''));
        $webhookSecret = trim((string) ($input['webhook_secret'] ?? ''));
        $makeActive = (string) ($input['is_active'] ?? '') === '1';
        $errors = [];

        $isMeta = $provider === 'meta_cloud';
        $isFonnte = $provider === 'fonnte';
        $tokenLabel = $isFonnte ? 'API token Fonnte' : 'Access token';
        if ($isFonnte) {
            // Jangan menyimpan sisa metadata Meta saat koneksi beralih ke
            // Fonnte; field tersebut tidak dipakai dan bisa menyesatkan UI.
            $phoneId = '';
            $businessId = '';
        }

        if (!WhatsAppProviderRegistry::has($provider)) {
            $errors['provider'] = 'Provider WhatsApp tidak didukung.';
        }
        if ($name === '') {
            $errors['name'] = 'Nama koneksi wajib diisi.';
        } elseif (mb_strlen($name) > 100) {
            $errors['name'] = 'Nama koneksi maksimal 100 karakter.';
        }

        // Phone Number ID/WABA hanya relevan untuk Meta. Fonnte memakai token
        // perangkat dan tidak boleh dipaksa mengisi field khusus Meta.
        if ($isMeta && $phoneId === '') {
            $errors['phone_number_id'] = 'Phone Number ID wajib diisi untuk provider Meta.';
        } elseif ($phoneId !== '' && !preg_match('/^[A-Za-z0-9._-]{4,64}$/', $phoneId)) {
            $errors['phone_number_id'] = 'Format Phone Number ID tidak valid.';
        }
        if ($businessId !== '' && !preg_match('/^[A-Za-z0-9._-]{2,64}$/', $businessId)) {
            $errors['business_account_id'] = 'Format Business Account ID tidak valid.';
        }
        $sender = $senderRaw === '' ? '' : whatsapp_number($senderRaw);
        if ($senderRaw !== '' && $sender === null) {
            $errors['sender_number'] = 'Nomor pengirim tidak valid. Gunakan format E.164, misalnya 6281234567890 atau +15551687957.';
        }
        if ($baseUrl !== '' && !self::validBaseUrl($baseUrl)) {
            $errors['base_url'] = 'Base URL harus HTTPS (HTTP hanya diizinkan untuk localhost pengujian).';
        }
        if ($token !== '' && mb_strlen($token) > 4096) {
            $errors['access_token'] = $tokenLabel . ' terlalu panjang.';
        }
        if ($token !== '' && preg_match('/[\r\n\0]/', $token) === 1) {
            $errors['access_token'] = $tokenLabel . ' mengandung karakter yang tidak valid.';
        }
        if ($webhookVerify !== '' && mb_strlen($webhookVerify) < 8) {
            $errors['webhook_verify_token'] = 'Webhook verification token minimal 8 karakter.';
        }
        if ($webhookSecret !== '' && mb_strlen($webhookSecret) < 8) {
            $errors['webhook_secret'] = 'Webhook secret minimal 8 karakter.';
        }

        $isNew = $id <= 0;
        $needsSecret = $isNew || ($existing !== null && trim((string) ($existing['secret_encrypted'] ?? '')) === '');
        if ($needsSecret && $token === '') {
            $errors['access_token'] = $tokenLabel . ' wajib diisi untuk koneksi baru.';
        }
        if (($token !== '' || $needsSecret) && !Crypto::isReady()) {
            $errors['access_token'] = 'Master encryption key belum dikonfigurasi. Kredensial tidak boleh disimpan plaintext.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $data = [
            'id' => $id,
            'integration' => self::INTEGRATION_WHATSAPP,
            'provider' => $provider,
            'name' => $name,
            'business_account_id' => $businessId,
            'phone_number_id' => $phoneId,
            'sender_number' => $sender ?? '',
            'base_url' => $baseUrl,
            'actor_id' => (int) ($actor['id'] ?? 0),
            'secret_encrypted' => $token !== '' ? Crypto::encrypt($token) : null,
            'webhook_verify_token_encrypted' => $webhookVerify !== '' ? Crypto::encrypt($webhookVerify) : null,
            'webhook_secret_encrypted' => $webhookSecret !== '' ? Crypto::encrypt($webhookSecret) : null,
        ];

        $savedId = ApiCredential::save($data);

        if ($makeActive) {
            ApiCredential::setActive($savedId);
        } elseif ($isNew && ApiCredential::active(self::INTEGRATION_WHATSAPP) === null) {
            // Koneksi pertama otomatis menjadi default agar admin tidak lupa.
            ApiCredential::setActive($savedId);
        }

        $fields = ['name', 'provider', 'sender_number', 'base_url'];
        if ($isMeta) {
            $fields[] = 'phone_number_id';
        }
        if ($businessId !== '') {
            $fields[] = 'business_account_id';
        }
        if ($token !== '') {
            $fields[] = 'access_token_diganti';
        }
        if ($webhookVerify !== '') {
            $fields[] = 'webhook_verify_token_diganti';
        }
        if ($webhookSecret !== '') {
            $fields[] = 'webhook_secret_diganti';
        }
        self::audit($actor, ApiFeatureRegistry::WHATSAPP_GATEWAY, $isNew ? 'kredensial_dibuat' : 'kredensial_diperbarui', $fields, $savedId);

        return $savedId;
    }

    /**
     * @param array<string, mixed> $actor
     */
    public static function setActive(array $actor, int $id): void
    {
        if (!ApiCredential::setActive($id)) {
            throw new ValidationException(['kredensial' => 'Kredensial tidak ditemukan atau sudah dicabut.']);
        }

        self::audit($actor, ApiFeatureRegistry::WHATSAPP_GATEWAY, 'koneksi_diaktifkan', ['is_active'], $id);
    }

    /**
     * Cabut kredensial (secret dihapus, jejak tetap ada).
     *
     * @param array<string, mixed> $actor
     */
    public static function revoke(array $actor, int $id): void
    {
        if (!ApiCredential::revoke($id)) {
            throw new ValidationException(['kredensial' => 'Kredensial tidak ditemukan atau sudah dicabut.']);
        }

        self::audit($actor, ApiFeatureRegistry::WHATSAPP_GATEWAY, 'kredensial_dicabut', ['secret_dihapus', 'is_active'], $id);
    }

    /**
     * Tes koneksi nyata + catat hasil. Tidak pernah mencatat token.
     *
     * @param array<string, mixed> $actor
     * @return array{ok:bool,code:string,message:string,duration_ms:int}
     */
    public static function testWhatsApp(array $actor, int $id): array
    {
        $connection = self::connection($id);
        if ($connection === null) {
            throw new ValidationException(['kredensial' => 'Kredensial tidak ditemukan, dicabut, atau tidak dapat didekripsi.']);
        }

        $adapter = WhatsAppProviderRegistry::get((string) $connection['provider']);
        if ($adapter === null) {
            throw new ValidationException(['provider' => 'Provider WhatsApp tidak didukung.']);
        }

        $timeout = (int) (ApiIntegrationSetting::get(ApiFeatureRegistry::WHATSAPP_GATEWAY)['settings']['timeout'] ?? 20);
        $result = $adapter->testConnection($connection, max(5, min(60, $timeout)));
        $ok = !empty($result['ok']);

        ApiCredential::markTest(
            $id,
            $ok,
            $ok ? 'connected' : 'failed',
            $ok ? (string) $result['message'] : (string) ($result['code'] . ': ' . $result['message'])
        );

        ApiUsageLog::record(
            ApiFeatureRegistry::WHATSAPP_GATEWAY,
            (string) $connection['provider'],
            $id,
            'connection_test',
            $ok ? 'success' : 'failed',
            $ok,
            '',
            $ok ? '' : (string) ($result['code'] ?? 'error'),
            (int) ($result['duration_ms'] ?? 0)
        );

        AuditLog::record(
            (int) ($actor['id'] ?? 0),
            'api_whatsapp_tes_koneksi',
            'api_credentials',
            $id,
            [
                'provider' => (string) $connection['provider'],
                'hasil' => $ok ? 'berhasil' : 'gagal',
                'kode' => (string) ($result['code'] ?? ''),
            ]
        );

        return [
            'ok' => $ok,
            'code' => (string) ($result['code'] ?? ''),
            'message' => (string) ($result['message'] ?? ''),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
        ];
    }

    /**
     * URL webhook yang perlu didaftarkan di Meta. Bukan rahasia.
     */
    public static function webhookUrl(int $connectionId): string
    {
        return url('/webhook/whatsapp/' . $connectionId);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<int, string> $fields
     */
    private static function audit(array $actor, string $feature, string $action, array $fields, int $objectId): void
    {
        ApiConfigAudit::record((int) ($actor['id'] ?? 0), $feature, $action, $fields);
        AuditLog::record(
            (int) ($actor['id'] ?? 0),
            'api_' . $action,
            'api_credentials',
            $objectId,
            ['feature' => $feature, 'fields' => array_values(array_unique($fields))]
        );
    }

    private static function validBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }

        if (strtolower((string) $parts['scheme']) === 'https') {
            return true;
        }

        $host = strtolower((string) $parts['host']);

        return strtolower((string) $parts['scheme']) === 'http'
            && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
