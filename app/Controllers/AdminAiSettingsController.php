<?php
/**
 * Controller Admin — Pengaturan AI Chatbot, Monitoring, dan FAQ.
 *
 * Hanya Admin yang dapat mengakses (middleware route 'role' => 'admin').
 * API key TIDAK PERNAH dikirim ke browser, ditampilkan, atau ditulis ke log;
 * pengelolaan key dilakukan sepenuhnya di sisi server dengan enkripsi.
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminBaseController.php';

final class AdminAiSettingsController extends AdminBaseController
{
    public function index(): void
    {
        $search = $this->queryString('q');
        $category = $this->queryString('kategori');
        $editId = (int) $this->queryString('edit', '0');
        $editFaq = $editId > 0 ? AiFaq::find($editId) : null;

        $ai = AiSettingsService::viewData();
        $activeProvider = (string) ($ai['provider'] ?? 'gemini');

        $this->renderRole('admin/pengaturan_ai', 'admin', 'pengaturan_ai', 'Pengaturan AI Chatbot', [
            ['label' => 'Admin', 'url' => url('/admin/dashboard')],
            ['label' => 'Pengaturan AI Chatbot'],
        ], [
            'ai' => $ai,
            'suggestedModels' => AiSettingsService::suggestedModels($activeProvider),
            'providerMeta' => AiProviderRegistry::metadata(),
            'faqs' => AiFaq::all($search, $category),
            'faqCategories' => AiFaq::categories(),
            'faqEdit' => $editFaq,
            'faqSearch' => $search,
            'faqCategory' => $category,
            'flash_sukses' => flash_get('sukses'),
            'flash_error' => flash_get('error'),
            'csrf' => csrf_token(),
            'saveUrl' => url('/admin/pengaturan/ai'),
            'testUrl' => url('/admin/pengaturan/ai/test'),
            'testDatabaseUrl' => url('/admin/pengaturan/ai/test-database'),
            'faqSaveUrl' => url('/admin/pengaturan/ai/faq'),
            'faqToggleUrl' => url('/admin/pengaturan/ai/faq/status'),
            'faqDeleteUrl' => url('/admin/pengaturan/ai/faq/hapus'),
        ]);
    }

    public function save(): void
    {
        $this->actAdmin(
            'Pengaturan AI Chatbot berhasil disimpan.',
            '/admin/pengaturan/ai',
            static function (array $actor): void {
                $payload = [
                    'provider' => (string) ($_POST['provider'] ?? 'gemini'),
                    'api_key' => (string) ($_POST['api_key'] ?? ''),
                    'model' => (string) ($_POST['model'] ?? ''),
                    'base_url' => (string) ($_POST['base_url'] ?? ''),
                    'is_enabled' => (string) ($_POST['is_enabled'] ?? ''),
                    'max_output_tokens' => (string) ($_POST['max_output_tokens'] ?? ''),
                    'timeout_seconds' => (string) ($_POST['timeout_seconds'] ?? ''),
                    'rate_limit' => (string) ($_POST['rate_limit'] ?? ''),
                    'rate_window' => (string) ($_POST['rate_window'] ?? ''),
                    'daily_limit' => (string) ($_POST['daily_limit'] ?? ''),
                    'max_message_length' => (string) ($_POST['max_message_length'] ?? ''),
                    'system_instruction' => (string) ($_POST['system_instruction'] ?? ''),
                ];

                // Form Tahap 18 mengirim penanda ini. Tanpa penanda (form/API
                // lama), pengaturan hybrid yang sudah ada tidak boleh terhapus.
                if (isset($_POST['hybrid_settings_present'])) {
                    $payload['hybrid_mode'] = (string) ($_POST['hybrid_mode'] ?? '');
                    $payload['local_db_enabled'] = (string) ($_POST['local_db_enabled'] ?? '');
                    $payload['prefer_local_db'] = (string) ($_POST['prefer_local_db'] ?? '');
                    $payload['fallback_enabled'] = (string) ($_POST['fallback_enabled'] ?? '');
                    $payload['ollama_enabled'] = (string) ($_POST['ollama_enabled'] ?? '');
                    $payload['ollama_model'] = (string) ($_POST['ollama_model'] ?? '');
                    $payload['ollama_base_url'] = (string) ($_POST['ollama_base_url'] ?? '');
                }

                AiSettingsService::save($actor, $payload);
            }
        );
    }

    /**
     * Uji koneksi ke provider terpilih memakai API sungguhan. Mengembalikan
     * JSON aman (tanpa API key / respons mentah).
     */
    public function test(): void
    {
        if (!csrf_verify($this->readToken())) {
            $this->json([
                'ok' => false,
                'code' => 'csrf',
                'message' => 'Token keamanan tidak valid. Muat ulang halaman.',
            ], 419);
            return;
        }

        $actor = auth_user();
        if ($actor === null) {
            $this->json([
                'ok' => false,
                'code' => 'auth',
                'message' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
            ], 401);
            return;
        }

        $payload = $this->readPayload();
        $provider = strtolower(trim((string) ($payload['provider'] ?? AiSettingsService::runtime()['provider'])));
        if (!AiProviderRegistry::has($provider)) {
            $this->json(['ok' => false, 'code' => 'invalid_provider', 'message' => 'Provider AI tidak dikenal.']);
            return;
        }

        $overrideKey = isset($payload['api_key']) && trim((string) $payload['api_key']) !== ''
            ? (string) $payload['api_key']
            : null;
        $overrideModel = isset($payload['model']) && trim((string) $payload['model']) !== ''
            ? (string) $payload['model']
            : null;
        $overrideBase = isset($payload['base_url']) && trim((string) $payload['base_url']) !== ''
            ? (string) $payload['base_url']
            : null;

        $startedAt = microtime(true);
        $result = AiSettingsService::testProvider($provider, $overrideKey, $overrideModel, $overrideBase);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $model = $overrideModel ?? (string) AiSettingsService::providerConfig($provider)['model'];
        AiUsage::record(
            $actor,
            $provider,
            $model,
            'test',
            !empty($result['ok']),
            !empty($result['ok']) ? '' : (string) ($result['code'] ?? 'error'),
            $durationMs
        );

        // Audit aman: hanya hasil & kode, tanpa API key.
        AuditLog::record(
            (int) $actor['id'],
            'pengaturan_ai_tes_koneksi',
            'ai_settings',
            null,
            [
                'provider' => $provider,
                'hasil' => !empty($result['ok']) ? 'berhasil' : 'gagal',
                'kode' => (string) ($result['code'] ?? ''),
            ]
        );

        $this->json([
            'ok' => !empty($result['ok']),
            'code' => (string) ($result['code'] ?? ''),
            'message' => (string) ($result['message'] ?? ''),
            'provider' => $provider,
            'provider_label' => AiProviderRegistry::label($provider),
        ]);
    }

    /**
     * Tes nyata koneksi database read-only (SELECT 1). Tidak membaca data
     * pelanggan dan tidak pernah menampilkan kredensial.
     */
    public function testDatabase(): void
    {
        if (!csrf_verify($this->readToken())) {
            $this->json([
                'ok' => false,
                'code' => 'csrf',
                'message' => 'Token keamanan tidak valid. Muat ulang halaman.',
            ], 419);
            return;
        }

        $actor = auth_user();
        if ($actor === null) {
            $this->json([
                'ok' => false,
                'code' => 'auth',
                'message' => 'Sesi Anda telah berakhir. Silakan masuk kembali.',
            ], 401);
            return;
        }

        $startedAt = microtime(true);
        $result = AiSettingsService::testDatabaseConnection();
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        AiUsage::record(
            $actor,
            'mysql-local',
            'read-only',
            'test',
            !empty($result['ok']),
            !empty($result['ok']) ? '' : (string) ($result['code'] ?? 'error'),
            $durationMs
        );
        AuditLog::record(
            (int) $actor['id'],
            'pengaturan_ai_tes_database',
            'ai_settings',
            null,
            [
                'hasil' => !empty($result['ok']) ? 'berhasil' : 'gagal',
                'kode' => (string) ($result['code'] ?? ''),
            ]
        );

        $this->json([
            'ok' => !empty($result['ok']),
            'code' => (string) ($result['code'] ?? ''),
            'message' => (string) ($result['message'] ?? ''),
        ]);
    }

    public function faqSave(): void
    {
        $this->actAdmin(
            'FAQ AI berhasil disimpan.',
            '/admin/pengaturan/ai#faq',
            static function (array $actor): void {
                $input = [
                    'id' => (int) ($_POST['id'] ?? 0),
                    'pertanyaan' => (string) ($_POST['pertanyaan'] ?? ''),
                    'jawaban' => (string) ($_POST['jawaban'] ?? ''),
                    'kategori' => (string) ($_POST['kategori'] ?? 'Umum'),
                    'urutan' => (int) ($_POST['urutan'] ?? 0),
                    'is_active' => (string) ($_POST['is_active'] ?? ''),
                ];

                $errors = AiFaq::validate($input);
                if ($errors !== []) {
                    throw new ValidationException($errors);
                }

                $id = AiFaq::save($input, (int) $actor['id']);
                AuditLog::record(
                    (int) $actor['id'],
                    'ai_faq_disimpan',
                    'ai_faq',
                    $id > 0 ? $id : null,
                    [
                        'kategori' => mb_substr(trim((string) $input['kategori']), 0, 100),
                        'is_active' => !empty($input['is_active']) ? 1 : 0,
                    ]
                );
            }
        );
    }

    public function faqToggle(): void
    {
        $this->actAdmin(
            'Status FAQ AI berhasil diperbarui.',
            '/admin/pengaturan/ai#faq',
            static function (array $actor): void {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0 || AiFaq::find($id) === null) {
                    throw new ValidationException(['faq' => 'FAQ tidak ditemukan.']);
                }

                $active = (string) ($_POST['is_active'] ?? '') === '1';
                AiFaq::setActive($id, $active, (int) $actor['id']);
                AuditLog::record(
                    (int) $actor['id'],
                    'ai_faq_status',
                    'ai_faq',
                    $id,
                    ['is_active' => $active ? 1 : 0]
                );
            }
        );
    }

    public function faqDelete(): void
    {
        $this->actAdmin(
            'FAQ AI berhasil dihapus.',
            '/admin/pengaturan/ai#faq',
            static function (array $actor): void {
                $id = (int) ($_POST['id'] ?? 0);
                $faq = AiFaq::find($id);
                if ($id <= 0 || $faq === null) {
                    throw new ValidationException(['faq' => 'FAQ tidak ditemukan.']);
                }

                AiFaq::delete($id);
                AuditLog::record(
                    (int) $actor['id'],
                    'ai_faq_dihapus',
                    'ai_faq',
                    $id,
                    ['kategori' => mb_substr((string) ($faq['kategori'] ?? ''), 0, 100)]
                );
            }
        );
    }

    private function readToken(): ?string
    {
        $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($header !== '') {
            return $header;
        }

        return isset($_POST['_token']) ? (string) $_POST['_token'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw === false ? '' : $raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
