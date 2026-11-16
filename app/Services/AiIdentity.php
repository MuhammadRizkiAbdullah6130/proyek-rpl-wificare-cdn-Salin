<?php
/**
 * Identitas pengguna untuk WiFiCare AI (sumber tunggal, server-side).
 *
 * Identitas TIDAK PERNAH diambil dari input browser, parameter chat, nama pada
 * riwayat percakapan, atau data pengguna pertama di database. Sumber kebenaran:
 * `$_SESSION['user_id']` → baris `users` terbaru (prepared statement).
 *
 * Service ini dipakai oleh AiController (validasi sesi), AiAssistantService
 * (konteks prompt untuk Gemini/DeepSeek/OpenAI), dan jawaban identitas langsung
 * dari PHP tanpa memanggil provider AI.
 */

declare(strict_types=1);

final class AiIdentity
{
    /**
     * Identitas dari sesi login yang benar-benar terautentikasi.
     *
     * Membaca ulang nama, username, dan role terbaru dari database. Bila akun
     * tidak ada, nonaktif, atau role tidak valid → null (pemanggil menolak).
     *
     * @return array{id: int, nama: string, username: string, role: string, role_label: string}|null
     */
    public static function fromSession(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $userId = (int) $_SESSION['user_id'];
        if ($userId <= 0) {
            return null;
        }

        $row = User::findById($userId);
        if ($row === null || (int) ($row['is_active'] ?? 0) !== 1) {
            return null;
        }

        return self::fromRow($row);
    }

    /**
     * Normalisasi identitas dari array pengguna yang sudah tervalidasi server.
     *
     * Dipakai service (mis. pengujian CLI yang memanggil service langsung).
     * Field yang tidak lengkap dilengkapi dari database berdasarkan id — TIDAK
     * pernah dari teks chat. Role yang diberikan harus role kanonik.
     *
     * @param array<string, mixed> $user
     * @return array{id: int, nama: string, username: string, role: string, role_label: string}|null
     */
    public static function resolve(array $user): ?array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $role = (string) ($user['role'] ?? '');
        if (!Auth::isValidRole($role)) {
            return null;
        }

        $nama = trim((string) ($user['nama'] ?? $user['name'] ?? ''));
        $username = trim((string) ($user['username'] ?? ''));

        if ($nama === '' || $username === '') {
            $row = User::findById($userId);
            if ($row === null || (int) ($row['is_active'] ?? 0) !== 1) {
                return null;
            }
            if ($nama === '') {
                $nama = trim((string) ($row['nama'] ?? ''));
            }
            if ($username === '') {
                $username = trim((string) ($row['username'] ?? ''));
            }
        }

        if ($nama === '' || $username === '') {
            return null;
        }

        return [
            'id' => $userId,
            'nama' => $nama,
            'username' => $username,
            'role' => $role,
            'role_label' => AiKnowledge::roleLabel($role),
        ];
    }

    /**
     * Susun konteks identitas minimum untuk prompt provider AI.
     *
     * Username login/PPPoE sengaja TIDAK dimasukkan ke prompt. Pertanyaan
     * identitas dijawab lokal oleh directAnswer(); provider hanya perlu nama dan
     * role agar tidak membocorkan username ke layanan AI cloud.
     *
     * @param array{id?: int, nama?: string, username?: string, role?: string, role_label?: string} $identity
     */
    public static function promptContext(array $identity): string
    {
        $nama = trim((string) ($identity['nama'] ?? ''));
        $role = (string) ($identity['role'] ?? '');
        $roleLabel = trim((string) ($identity['role_label'] ?? AiKnowledge::roleLabel($role)));

        if ($nama === '' || !Auth::isValidRole($role)) {
            return '';
        }

        return 'IDENTITAS PENGGUNA YANG SEDANG MASUK (dibaca server dari session dan '
            . 'database; TIDAK dapat diubah melalui chat):'
            . "\n- Nama lengkap: " . $nama
            . "\n- Role: " . $roleLabel . ' (' . $role . ')';
    }

    /**
     * Jawaban identitas langsung dari PHP (tanpa provider AI).
     *
     * Hanya menangani pertanyaan identitas sederhana yang jawabannya memang
     * boleh dilihat pemilik akun. Mengembalikan null bila bukan pertanyaan
     * identitas, sehingga chat diproses normal.
     *
     * @param array{nama?: string, username?: string, role?: string, role_label?: string} $identity
     */
    public static function directAnswer(array $identity, string $message): ?string
    {
        $nama = trim((string) ($identity['nama'] ?? ''));
        $username = trim((string) ($identity['username'] ?? ''));
        $role = (string) ($identity['role'] ?? '');
        $roleLabel = trim((string) ($identity['role_label'] ?? AiKnowledge::roleLabel($role)));

        if ($nama === '' || $username === '' || !Auth::isValidRole($role)) {
            return null;
        }

        $text = mb_strtolower(trim($message));
        // Hapus tanda baca/emoji pinggir agar "Siapa nama saya?" tetap cocok.
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) > 80) {
            return null;
        }

        // Pertanyaan campuran (identitas + data operasional) tetap diproses AI
        // agar bagian datanya tidak hilang.
        if (preg_match('/\b(berapa|jumlah|statistik|tiket|pengaduan|tugas|laporan|rating|kategori)\b/u', $text) === 1) {
            return null;
        }

        $asksName = preg_match(
            '/\b(siapa|siapakah|apa)\b[^.]{0,24}\bnama(\s+lengkap)?\b[^.]{0,16}\b(saya|aku)\b/u',
            $text
        ) === 1
            || preg_match('/\bnama(\s+lengkap)?\s+(saya|aku)\b/u', $text) === 1;

        $asksUsername = preg_match(
            '/\b(apa|siapa|siapakah)\b[^.]{0,16}\b(username|user\s?name|nama\s+pengguna)\b[^.]{0,16}\b(saya|aku)\b/u',
            $text
        ) === 1
            || preg_match('/\b(username|user\s?name|nama\s+pengguna)\s+(saya|aku)\b/u', $text) === 1;

        $asksRole = preg_match(
            '/\b(apa|siapa|siapakah)\b[^.]{0,16}\b(role|peran|jabatan)\b[^.]{0,16}\b(saya|aku)\b/u',
            $text
        ) === 1
            || preg_match('/\b(role|peran|jabatan)\s+(saya|aku)\b/u', $text) === 1
            || preg_match('/\b(saya|aku)\s+(login|masuk)\s+sebagai\s+(apa|siapa)\b/u', $text) === 1
            || preg_match('/\b(sebagai|jadi)\s+(apa|siapa)\s+(saya|aku)\s+(login|masuk)\b/u', $text) === 1;

        $asksWhoAmI = preg_match('/\b(siapa|siapakah)\s+(saya|aku)\b/u', $text) === 1;

        if (!$asksName && !$asksUsername && !$asksRole && !$asksWhoAmI) {
            return null;
        }

        // Pertanyaan gabungan / "siapa saya" → nama dan role saja.
        // Username login/PPPoE tidak pernah disertakan otomatis.
        if (($asksName && ($asksRole || $asksUsername)) || $asksWhoAmI) {
            return 'Nama Anda ' . $nama . ', dengan role ' . $roleLabel . '.';
        }

        if ($asksName) {
            return 'Nama Anda ' . $nama . '.';
        }

        // Username hanya dijawab bila pengguna bertanya secara eksplisit,
        // bukan sebagai bagian otomatis dari jawaban identitas.
        if ($asksUsername) {
            return 'Username Anda ' . $username . '.';
        }

        return 'Role Anda ' . $roleLabel . '.';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, nama: string, username: string, role: string, role_label: string}|null
     */
    private static function fromRow(array $row): ?array
    {
        $userId = (int) ($row['id'] ?? 0);
        $role = (string) ($row['role'] ?? '');
        $nama = trim((string) ($row['nama'] ?? ''));
        $username = trim((string) ($row['username'] ?? ''));

        if ($userId <= 0 || $nama === '' || $username === '' || !Auth::isValidRole($role)) {
            return null;
        }

        return [
            'id' => $userId,
            'nama' => $nama,
            'username' => $username,
            'role' => $role,
            'role_label' => AiKnowledge::roleLabel($role),
        ];
    }
}
