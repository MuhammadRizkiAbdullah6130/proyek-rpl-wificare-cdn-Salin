<?php

declare(strict_types=1);

namespace Tests\Integration;

use PengaduanService;
use RuntimeException;
use Tests\Support\DatabaseTestCase;

final class ComplaintValidationTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private function pelanggan(array $override = []): array
    {
        return array_merge([
            'id' => 1001,
            'pelanggan_id' => 501,
            'nama' => 'Pelanggan Uji',
            'username_pppoe' => 'pppoe.uji',
            'nomor_whatsapp' => '081234567890',
        ], $override);
    }

    private function activeCategoryId(): int
    {
        $id = $this->pdo->query('SELECT id FROM kategori_gangguan WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();

        self::assertNotFalse($id, 'Seeder kategori tidak tersedia di database uji.');

        return (int) $id;
    }

    public function testStatusAwalPengaduanBaru(): void
    {
        self::assertSame('menunggu_verifikasi', PengaduanService::STATUS_AWAL);
        self::assertTrue(status_is_active(PengaduanService::STATUS_AWAL));
    }

    public function testAkunTanpaDataPelangganDitolak(): void
    {
        try {
            PengaduanService::createForPelanggan(
                $this->pelanggan(['pelanggan_id' => 0]),
                ['kategori_id' => $this->activeCategoryId(), 'deskripsi' => 'Deskripsi yang cukup panjang.'],
                [[]]
            );
            self::fail('Akun tanpa pelanggan_id tidak ditolak.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('tidak terhubung dengan data pelanggan', $exception->getMessage());
        }
    }

    public function testValidasiInputPengaduanMenghasilkanSemuaErrorRelevan(): void
    {
        $errors = $this->validationErrors(function (): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(['nomor_whatsapp' => '']),
                [
                    'nomor_whatsapp' => 'abc',
                    'kategori_id' => 999999,
                    'deskripsi' => 'pendek',
                ],
                []
            );
        });

        self::assertSame('Format nomor WhatsApp tidak valid (9–20 digit).', $errors['nomor_whatsapp'] ?? null);
        self::assertSame('Kategori gangguan yang dipilih tidak valid.', $errors['kategori_id'] ?? null);
        self::assertSame('Deskripsi gangguan minimal 10 karakter.', $errors['deskripsi'] ?? null);
        self::assertSame('Foto bukti gangguan wajib diunggah.', $errors['foto'] ?? null);
    }

    public function testNomorWhatsAppWajibAdaAtauMemakaiNomorUtama(): void
    {
        $errors = $this->validationErrors(function (): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(['nomor_whatsapp' => '']),
                [
                    'nomor_whatsapp' => '',
                    'kategori_id' => $this->activeCategoryId(),
                    'deskripsi' => 'Deskripsi yang cukup panjang.',
                ],
                []
            );
        });

        self::assertSame(
            'Nomor WhatsApp kontak tiket wajib diisi atau nomor utama harus tersedia.',
            $errors['nomor_whatsapp'] ?? null
        );
    }

    public function testNomorUtamaDipakaiSebagaiFallback(): void
    {
        $errors = $this->validationErrors(function (): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(['nomor_whatsapp' => '081234567890']),
                [
                    'nomor_whatsapp' => '',
                    'kategori_id' => $this->activeCategoryId(),
                    'deskripsi' => 'Deskripsi yang cukup panjang.',
                ],
                []
            );
        });

        self::assertArrayNotHasKey('nomor_whatsapp', $errors);
        self::assertSame('Foto bukti gangguan wajib diunggah.', $errors['foto'] ?? null);
    }

    public function testBatasPanjangDeskripsi(): void
    {
        $category = $this->activeCategoryId();

        $empty = $this->validationErrors(function () use ($category): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(),
                ['kategori_id' => $category, 'deskripsi' => '   '],
                []
            );
        });
        self::assertSame('Deskripsi gangguan wajib diisi.', $empty['deskripsi'] ?? null);

        $tooShort = $this->validationErrors(function () use ($category): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(),
                ['kategori_id' => $category, 'deskripsi' => '123456789'],
                []
            );
        });
        self::assertSame('Deskripsi gangguan minimal 10 karakter.', $tooShort['deskripsi'] ?? null);

        $tooLong = $this->validationErrors(function () use ($category): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(),
                ['kategori_id' => $category, 'deskripsi' => str_repeat('a', 2001)],
                []
            );
        });
        self::assertSame('Deskripsi gangguan maksimal 2000 karakter.', $tooLong['deskripsi'] ?? null);

        $maximum = $this->validationErrors(function () use ($category): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(),
                ['kategori_id' => $category, 'deskripsi' => str_repeat('a', 2000)],
                []
            );
        });
        self::assertArrayNotHasKey('deskripsi', $maximum);
        self::assertSame('Foto bukti gangguan wajib diunggah.', $maximum['foto'] ?? null);
    }

    public function testKategoriNonaktifAtauTidakAdaDitolak(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO kategori_gangguan (nama, is_active) VALUES (:nama, 0)');
        $insert->execute(['nama' => 'Kategori Nonaktif Uji ' . bin2hex(random_bytes(4))]);
        $inactiveId = (int) $this->pdo->lastInsertId();

        foreach ([0, 999999, $inactiveId] as $categoryId) {
            $errors = $this->validationErrors(function () use ($categoryId): void {
                PengaduanService::createForPelanggan(
                    $this->pelanggan(),
                    [
                        'kategori_id' => $categoryId,
                        'deskripsi' => 'Deskripsi yang cukup panjang.',
                    ],
                    [[]]
                );
            });

            self::assertSame(
                'Kategori gangguan yang dipilih tidak valid.',
                $errors['kategori_id'] ?? null,
                'Kategori ID ' . $categoryId
            );
        }
    }

    public function testFotoWajibAda(): void
    {
        $errors = $this->validationErrors(function (): void {
            PengaduanService::createForPelanggan(
                $this->pelanggan(),
                [
                    'kategori_id' => $this->activeCategoryId(),
                    'deskripsi' => 'Deskripsi yang cukup panjang.',
                ],
                []
            );
        });

        self::assertSame(['foto'], array_keys($errors));
    }
}
