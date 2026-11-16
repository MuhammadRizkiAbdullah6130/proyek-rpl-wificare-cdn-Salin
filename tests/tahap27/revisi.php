<?php
/**
 * WiFiCare CDN — Tahap 27: Revisi keamanan tampilan & UI.
 *
 * Menguji:
 * - identitas AI tidak otomatis membocorkan username (sudah diuji Tahap 16),
 * - presentasi Audit Log aman dan terbaca,
 * - Audit Konfigurasi WhatsApp tidak menampilkan nama field teknis,
 * - badge tiket dibatalkan hanya menampilkan status efektif,
 * - rating layanan ditutup sementara tanpa menghapus data,
 * - spacing/scoped UI OTP, Notifikasi, dan Monitoring API.
 *
 * Jalankan dengan server uji di http://127.0.0.1:8099 dan DB uji.
 */

declare(strict_types=1);

require_once __DIR__ . '/../tahap10/_config.php';
t10_require_passwords(['T10_PW_ADMIN', 'T10_PW_PEL']);
require_once __DIR__ . '/../../app/bootstrap.php';

$PASS = 0;
$FAIL = 0;
$FAILED = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    global $PASS, $FAIL, $FAILED;
    if ($ok) {
        $PASS++;
        echo "PASS  {$name}\n";

        return;
    }

    $FAIL++;
    $FAILED[] = $name . ($detail !== '' ? " :: {$detail}" : '');
    echo "FAIL  {$name}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

/** @return array{status:int,body:string,headers:array<string,string>} */
function http_request(string $jar, string $method, string $path, array $fields = []): array
{
    $ch = curl_init(T10_BASE . $path);
    $headers = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        return ['status' => 0, 'body' => '', 'headers' => []];
    }

    $headerText = substr((string) $raw, 0, $headerSize);
    $headerMap = [];
    foreach (explode("\r\n", $headerText) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headerMap[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'body' => substr((string) $raw, $headerSize), 'headers' => $headerMap];
}

function token_from(string $html): string
{
    if (preg_match('/name="csrf-token"\s+content="([a-f0-9]{64})"/', $html, $m) === 1) {
        return $m[1];
    }

    return preg_match('/name="_token"\s+value="([a-f0-9]{64})"/', $html, $m) === 1 ? $m[1] : '';
}

/** @return array{0:bool,1:string} */
function login(string $tag, string $username, string $password): array
{
    $jar = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wificare_t27_' . $tag . '_' . getmypid() . '.jar';
    @unlink($jar);
    $page = http_request($jar, 'GET', '/login');
    $token = token_from($page['body']);
    $post = http_request($jar, 'POST', '/login', [
        '_token' => $token,
        'username' => $username,
        'password' => $password,
    ]);
    $ok = $post['status'] === 302 && !str_contains($post['headers']['location'] ?? '', '/login');

    return [$ok, $jar];
}

echo "==== TAHAP 27 — REVISI UI & KEAMANAN TAMPILAN ====\n";
echo "--- Unit: helper audit & badge ---\n";

check('aksi login_berhasil diterjemahkan', audit_action_label('login_berhasil') === 'Login berhasil');
check('aksi fallback tidak menampilkan kode mentah', audit_action_label('uji_revisi_audit') === 'Uji revisi audit');
check('IP ::1 tampil Lokal', audit_ip_label('::1') === 'Lokal');
check('IP 127.0.0.1 tampil Lokal', audit_ip_label('127.0.0.1') === 'Lokal');
check('IP jaringan tetap ditampilkan', audit_ip_label('10.10.0.8') === '10.10.0.8');

$metadata = json_encode([
    'ip' => '::1',
    'nomor_tiket' => 'WFC-UJI-27',
    'status_lama' => 'menunggu_verifikasi',
    'status_baru' => 'diproses_cs',
    'password' => 'secret-value',
    'access_token' => 'token-value',
    'fields' => ['access_token_diganti', 'webhook_secret_diganti'],
    'alasan' => 'SELECT * FROM users',
    'catatan' => 'Catatan aman',
], JSON_UNESCAPED_UNICODE);
$pairs = audit_metadata_pairs((string) $metadata);
$pairLabels = array_column($pairs, 'label');
$pairText = json_encode($pairs, JSON_UNESCAPED_UNICODE);
check('metadata rahasia password/token/fields dibuang', !str_contains($pairText, 'secret-value') && !str_contains($pairText, 'token-value') && !str_contains($pairText, 'access_token_diganti') && !in_array('Password', $pairLabels, true));
check('metadata SQL tidak ditampilkan mentah', !str_contains($pairText, 'SELECT * FROM') && str_contains($pairText, 'Tidak ditampilkan'));
check('metadata status diterjemahkan', in_array('Status sebelumnya', $pairLabels, true) && in_array('Status terbaru', $pairLabels, true));
check('metadata IP loopback menjadi Lokal', str_contains($pairText, 'Lokal'));
check('objek pengaduan memakai nomor tiket', audit_object_label('pengaduan', 28, ['nomor_tiket' => 'WFC-UJI-27']) === 'Pengaduan WFC-UJI-27');
check('objek users tidak menampilkan tabel mentah', audit_object_label('users', 1) === 'Akun pengguna #1');

$cancelledBadge = status_badge_html('selesai', true);
$normalBadge = status_badge_html('selesai', false);
check('badge tiket dibatalkan tunggal', substr_count($cancelledBadge, 'Dibatalkan') === 1 && !str_contains($cancelledBadge, 'Selesai') && !str_contains($cancelledBadge, 'badge-cancel'), $cancelledBadge);
check('badge status normal tetap Selesai', str_contains($normalBadge, 'Selesai'), $normalBadge);
check('rating default nonaktif', rating_layanan_enabled() === false);

echo "--- HTTP: halaman admin & pelanggan ---\n";

$db = Database::pdo();
$db->exec("DELETE FROM audit_log WHERE aksi = 'uji_revisi_audit'");
$db->exec("DELETE FROM api_config_audit WHERE action = 'uji_audit_konfigurasi'");

AuditLog::record(1, 'uji_revisi_audit', 'pengaduan', 999999, [
    'ip' => '::1',
    'nomor_tiket' => 'WFC-UJI-27',
    'password' => 'secret-value',
    'access_token' => 'token-value',
    'alasan' => 'SELECT * FROM users',
    'catatan' => 'Catatan aman',
]);
ApiConfigAudit::record(
    1,
    ApiFeatureRegistry::WHATSAPP_GATEWAY,
    'uji_audit_konfigurasi',
    ['access_token_diganti', 'webhook_secret_diganti']
);

[$adminOk, $adminJar] = login('admin', 'admin.budi', T10_PW_ADMIN);
check('login admin untuk uji UI', $adminOk);

if ($adminOk) {
    $audit = http_request($adminJar, 'GET', '/admin/audit?q=uji_revisi_audit');
    $auditBody = $audit['body'];
    check('halaman Audit Log 200', $audit['status'] === 200, 'status=' . $audit['status']);
    check('Audit Log memakai label aktivitas', str_contains($auditBody, 'Uji revisi audit') && !str_contains($auditBody, '<span class="audit-action">uji_revisi_audit</span>'));
    check('Audit Log menampilkan objek aman', str_contains($auditBody, 'Pengaduan WFC-UJI-27'));
    check('Audit Log menampilkan IP Lokal', str_contains($auditBody, 'Lokal'));
    check('Audit Log memakai keterangan berlabel', str_contains($auditBody, 'audit-meta') && str_contains($auditBody, 'Lihat keterangan'));
    check('Audit Log tidak merender JSON mentah', !str_contains($auditBody, '{"ip"') && !str_contains($auditBody, 'audit-pre'));
    check('Audit Log tidak membocorkan secret/SQL', !str_contains($auditBody, 'secret-value') && !str_contains($auditBody, 'token-value') && !str_contains($auditBody, 'SELECT * FROM'));
    check('filter aktivitas memakai label', str_contains($auditBody, '>Login berhasil</option>') && !str_contains($auditBody, '>login_berhasil</option>'));

    $waAudit = http_request($adminJar, 'GET', '/admin/whatsapp/audit');
    $waAuditBody = $waAudit['body'];
    check('Audit Konfigurasi WhatsApp 200', $waAudit['status'] === 200, 'status=' . $waAudit['status']);
    check('kolom Field teknis dihapus', preg_match('/<th>\s*Field\s*<\/th>/', $waAuditBody) !== 1);
    check('nama field rahasia tidak dirender', !str_contains($waAuditBody, 'access_token_diganti') && !str_contains($waAuditBody, 'webhook_secret_diganti') && !str_contains($waAuditBody, 'changed_fields'));
    check('Audit Konfigurasi memuat informasi wajib', str_contains($waAuditBody, 'Administrator') && str_contains($waAuditBody, 'Aktivitas') && str_contains($waAuditBody, 'Ringkasan'));

    $otp = http_request($adminJar, 'GET', '/admin/whatsapp/otp');
    $otpBody = $otp['body'];
    check('halaman OTP 200', $otp['status'] === 200, 'status=' . $otp['status']);
    check('OTP memiliki grup Status & Koneksi', str_contains($otpBody, 'Status &amp; Koneksi OTP'));
    check('OTP memiliki grup Keamanan', str_contains($otpBody, 'Keamanan OTP'));
    check('OTP memiliki template per tujuan', str_contains($otpBody, 'Template Pesan OTP per Tujuan'));
    check('OTP mempertahankan field keamanan', str_contains($otpBody, 'name="ttl_seconds"') && str_contains($otpBody, 'name="max_attempts"') && str_contains($otpBody, 'name="daily_phone_limit"'));
    check('OTP mempertahankan purposes & template', str_contains($otpBody, 'name="purposes[') && str_contains($otpBody, 'name="template_name"') && str_contains($otpBody, 'name="template_reset_password"'));
    check('OTP memakai spacing scoped', str_contains($otpBody, 'wa-settings-panel') && str_contains($otpBody, 'wa-settings-actions'));

    $notif = http_request($adminJar, 'GET', '/admin/whatsapp/notifikasi');
    $notifBody = $notif['body'];
    check('halaman Notifikasi WhatsApp 200', $notif['status'] === 200, 'status=' . $notif['status']);
    check('Notifikasi memisahkan panel antrean', str_contains($notifBody, 'wa-queue-panel') && str_contains($notifBody, 'Proses Antrean'));
    check('Notifikasi memakai accordion event scoped', str_contains($notifBody, 'wa-event') && str_contains($notifBody, 'Notifikasi per Event'));
    check('Notifikasi mempertahankan field utama', str_contains($notifBody, 'name="admin_number"') && str_contains($notifBody, 'name="daily_limit"') && str_contains($notifBody, 'name="retry_max"') && str_contains($notifBody, 'name="events['));
    check('Notifikasi mempertahankan monitoring per event', str_contains($notifBody, 'Monitoring per Event') && str_contains($notifBody, 'Delivered/read'));

    $monitoring = http_request($adminJar, 'GET', '/admin/monitoring-api');
    $monitoringBody = $monitoring['body'];
    check('halaman Monitoring API 200', $monitoring['status'] === 200, 'status=' . $monitoring['status']);
    check('Monitoring memakai wrapper spacing', str_contains($monitoringBody, 'monitoring-page'));
    check('box Estimasi Biaya terbaca', str_contains($monitoringBody, 'monitoring-cost-card') && str_contains($monitoringBody, 'Estimasi Biaya') && str_contains($monitoringBody, 'Belum tersedia'));

    $pengaduan = http_request($adminJar, 'GET', '/admin/pengaduan');
    check('Daftar Pengaduan admin 200', $pengaduan['status'] === 200, 'status=' . $pengaduan['status']);
    check('kolom rating admin disembunyikan', !str_contains($pengaduan['body'], '<th>Rating</th>'));
}

[$pelangganOk, $pelangganJar] = login('pelanggan', 'pppoe.rina', T10_PW_PEL);
check('login pelanggan untuk uji rating', $pelangganOk);
if ($pelangganOk) {
    $dashboard = http_request($pelangganJar, 'GET', '/pelanggan/dashboard');
    $ratingPage = http_request($pelangganJar, 'GET', '/pelanggan/rating');
    check('menu Rating Layanan hilang', !str_contains($dashboard['body'], 'Rating Layanan'));
    check('halaman Rating Layanan ditutup 404', $ratingPage['status'] === 404, 'status=' . $ratingPage['status']);
    check('halaman rating tidak memuat form', !str_contains($ratingPage['body'], 'name="rating"'));

    $before = (int) $db->query('SELECT COUNT(*) FROM rating_layanan')->fetchColumn();
    $ticketId = (int) $db->query("SELECT id FROM pengaduan WHERE status = 'selesai' AND is_cancelled = 0 LIMIT 1")->fetchColumn();
    if ($ticketId > 0) {
        $post = http_request($pelangganJar, 'POST', '/pelanggan/pengaduan/' . $ticketId . '/rating', [
            '_token' => token_from($dashboard['body']),
            'rating' => '5',
        ]);
        $after = (int) $db->query('SELECT COUNT(*) FROM rating_layanan')->fetchColumn();
        check('endpoint rating ditutup 404', $post['status'] === 404, 'status=' . $post['status']);
        check('data rating historis tidak berubah', $after === $before, "before={$before} after={$after}");
    } else {
        check('endpoint rating ditutup 404', false, 'Tidak ada tiket selesai pada fixture; endpoint tetap diuji oleh Tahap 10.');
        check('data rating historis tidak berubah', true);
    }
}

$db->exec("DELETE FROM audit_log WHERE aksi = 'uji_revisi_audit'");
$db->exec("DELETE FROM api_config_audit WHERE action = 'uji_audit_konfigurasi'");

echo "----------------------------------------------------------------\n";
echo 'TOTAL: ' . ($PASS + $FAIL) . " | PASS: {$PASS} | FAIL: {$FAIL}\n";
if ($FAIL > 0) {
    echo "FAILED:\n";
    foreach ($FAILED as $failure) {
        echo ' - ' . $failure . "\n";
    }
    exit(1);
}

echo "SEMUA TES REVISI 27 LULUS.\n";
