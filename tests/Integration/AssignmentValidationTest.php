<?php

declare(strict_types=1);

namespace Tests\Integration;

use PengaduanFlowService;
use Tests\Support\DatabaseTestCase;

final class AssignmentValidationTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private function actor(): array
    {
        return ['id' => 9001, 'role' => 'cs', 'nama' => 'CS Uji'];
    }

    public function testTeknisiTidakAdaDitolak(): void
    {
        $errors = $this->validationErrors(function (): void {
            PengaduanFlowService::tugaskan($this->actor(), 1, 999999999, null);
        });

        self::assertSame('Teknisi yang dipilih tidak valid.', $errors['teknisi_id'] ?? null);
    }

    public function testAkunBukanTeknisiDitolak(): void
    {
        $cs = $this->createUser(['role' => 'cs']);

        $errors = $this->validationErrors(function () use ($cs): void {
            PengaduanFlowService::tugaskan($this->actor(), 1, $cs['id'], null);
        });

        self::assertSame('Teknisi yang dipilih tidak valid.', $errors['teknisi_id'] ?? null);
    }

    public function testTeknisiNonaktifDitolak(): void
    {
        $teknisi = $this->createUser(['role' => 'teknisi', 'active' => false]);

        $errors = $this->validationErrors(function () use ($teknisi): void {
            PengaduanFlowService::tugaskan($this->actor(), 1, $teknisi['id'], null);
        });

        self::assertSame(
            'Akun teknisi tidak aktif dan tidak dapat ditugaskan.',
            $errors['teknisi_id'] ?? null
        );
    }

    public function testInstruksiPenugasanMaksimal2000Karakter(): void
    {
        $teknisi = $this->createUser(['role' => 'teknisi']);

        $errors = $this->validationErrors(function () use ($teknisi): void {
            PengaduanFlowService::tugaskan($this->actor(), 1, $teknisi['id'], str_repeat('a', 2001));
        });

        self::assertSame('Instruksi penugasan maksimal 2000 karakter.', $errors['catatan'] ?? null);
    }

    public function testTeknisiAktifLolosValidasiAwalLaluPengaduanTidakDitemukan(): void
    {
        $teknisi = $this->createUser(['role' => 'teknisi']);

        $errors = $this->validationErrors(function () use ($teknisi): void {
            PengaduanFlowService::tugaskan($this->actor(), 0, $teknisi['id'], '  Instruksi singkat  ');
        });

        self::assertSame('Pengaduan tidak ditemukan.', $errors['pengaduan'] ?? null);
    }

    public function testCatatanTepat2000KarakterLolosBatasPanjang(): void
    {
        $teknisi = $this->createUser(['role' => 'teknisi']);

        $errors = $this->validationErrors(function () use ($teknisi): void {
            PengaduanFlowService::tugaskan($this->actor(), 0, $teknisi['id'], str_repeat('a', 2000));
        });

        self::assertSame('Pengaduan tidak ditemukan.', $errors['pengaduan'] ?? null);
    }
}
