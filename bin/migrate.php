<?php
/**
 * WiFiCare CDN — Skrip migrasi & seeder.
 *
 * Penggunaan (dari direktori proyek):
 *   php bin/migrate.php
 *
 * Skrip akan:
 *   1. Membuat database bila belum ada.
 *   2. Menjalankan seluruh berkas di database/migrations secara berurutan
 *      (dicatat pada tabel `migrations` agar tidak diulang).
 *   3. Menjalankan seeder di database/seeds (idempotent).
 *
 * Hanya dapat dijalankan dari CLI, bukan melalui peramban.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan melalui CLI.');
}

require __DIR__ . '/../app/bootstrap.php';

/**
 * Pisahkan dump SQL menjadi pernyataan tunggal (aman terhadap tanda kutip & komentar).
 *
 * @return array<int, string>
 */
function wificare_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $index = 0;

    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    while ($index < $length) {
        $char = $sql[$index];
        $next = ($index + 1 < $length) ? $sql[$index + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            $index++;
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $index += 2;
                continue;
            }
            $index++;
            continue;
        }

        if ($inSingle) {
            $buffer .= $char;
            if ($char === "'" && $next === "'") {
                $buffer .= $next;
                $index += 2;
                continue;
            }
            if ($char === "'") {
                $inSingle = false;
            }
            $index++;
            continue;
        }

        if ($inDouble) {
            $buffer .= $char;
            if ($char === '"' && $next === '"') {
                $buffer .= $next;
                $index += 2;
                continue;
            }
            if ($char === '"') {
                $inDouble = false;
            }
            $index++;
            continue;
        }

        if ($inBacktick) {
            $buffer .= $char;
            if ($char === '`') {
                $inBacktick = false;
            }
            $index++;
            continue;
        }

        if ($char === '-' && $next === '-') {
            $inLineComment = true;
            $index += 2;
            continue;
        }

        if ($char === '#') {
            $inLineComment = true;
            $index++;
            continue;
        }

        if ($char === '/' && $next === '*') {
            $inBlockComment = true;
            $index += 2;
            continue;
        }

        if ($char === "'") {
            $inSingle = true;
            $buffer .= $char;
            $index++;
            continue;
        }

        if ($char === '"') {
            $inDouble = true;
            $buffer .= $char;
            $index++;
            continue;
        }

        if ($char === '`') {
            $inBacktick = true;
            $buffer .= $char;
            $index++;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            $index++;
            continue;
        }

        $buffer .= $char;
        $index++;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * Jalankan seluruh pernyataan dalam sebuah berkas SQL.
 */
function wificare_run_sql_file(PDO $pdo, string $path): int
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Tidak dapat membaca berkas: ' . $path);
    }

    $statements = wificare_sql_statements($contents);
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    return count($statements);
}

$databaseName = (string) db_config('database');
$collation = (string) db_config('collation', 'utf8mb4_unicode_ci');

if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
    fwrite(STDERR, "Nama database tidak valid: {$databaseName}\n");
    exit(1);
}

echo "WiFiCare CDN — Migrasi Database\n";
echo "==========================================\n";

try {
    // 1. Buat database bila belum ada.
    $server = Database::withoutDatabase();
    $server->exec(
        sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE %s',
            $databaseName,
            $collation
        )
    );
    echo "[ok]   Database siap: {$databaseName}\n";

    $pdo = Database::pdo();

    // 2. Tabel pencatat migrasi.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            migration  VARCHAR(255) NOT NULL,
            applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);

    $migrationFiles = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
    sort($migrationFiles);

    $ran = 0;
    foreach ($migrationFiles as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            echo "[skip] Migrasi sudah dijalankan: {$name}\n";
            continue;
        }

        $count = wificare_run_sql_file($pdo, $file);
        $statement = $pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
        $statement->execute(['migration' => $name]);

        echo "[run]  {$name} ({$count} pernyataan)\n";
        $ran++;
    }

    if ($ran === 0) {
        echo "[info] Tidak ada migrasi baru.\n";
    }

    // 3. Seeder (idempotent, dijalankan setiap kali).
    $seedFiles = glob(__DIR__ . '/../database/seeds/*.sql') ?: [];
    sort($seedFiles);

    foreach ($seedFiles as $file) {
        $count = wificare_run_sql_file($pdo, $file);
        echo "[seed] " . basename($file) . " ({$count} pernyataan)\n";
    }

    echo "==========================================\n";
    echo "Migrasi selesai tanpa error.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, "[error] " . $exception->getMessage() . "\n");
    exit(1);
}
