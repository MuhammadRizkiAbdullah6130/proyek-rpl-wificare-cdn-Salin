<?php

declare(strict_types=1);

namespace Tests\Unit;

use PengaduanFlowService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ValidationException;

final class StatusTransitionTest extends TestCase
{
    public function testTransisiCsHanyaDariDuaStatus(): void
    {
        self::assertSame(
            ['diproses_cs', 'ditolak'],
            PengaduanFlowService::csAllowedTargets('menunggu_verifikasi')
        );
        self::assertSame(
            ['menunggu_teknisi', 'selesai'],
            PengaduanFlowService::csAllowedTargets('diproses_cs')
        );

        foreach (['menunggu_teknisi', 'dalam_penanganan', 'selesai', 'ditolak', 'tidak_ada', '', null] as $status) {
            self::assertSame([], PengaduanFlowService::csAllowedTargets($status), 'CS tidak boleh memiliki transisi dari: ' . (string) $status);
        }
    }

    public function testTransisiTeknisiHanyaDariDuaStatus(): void
    {
        self::assertSame(
            ['dalam_penanganan'],
            PengaduanFlowService::teknisiAllowedTargets('menunggu_teknisi')
        );
        self::assertSame(
            ['selesai'],
            PengaduanFlowService::teknisiAllowedTargets('dalam_penanganan')
        );

        foreach (['menunggu_verifikasi', 'diproses_cs', 'selesai', 'ditolak', 'tidak_ada', '', null] as $status) {
            self::assertSame([], PengaduanFlowService::teknisiAllowedTargets($status), 'Teknisi tidak boleh memiliki transisi dari: ' . (string) $status);
        }
    }

    public function testCsDanTeknisiTidakBolehMemakaiJalurSatuSamaLain(): void
    {
        self::assertNotContains('dalam_penanganan', PengaduanFlowService::csAllowedTargets('menunggu_teknisi'));
        self::assertNotContains('diproses_cs', PengaduanFlowService::teknisiAllowedTargets('menunggu_verifikasi'));
        self::assertNotContains('ditolak', PengaduanFlowService::teknisiAllowedTargets('dalam_penanganan'));
    }

    public function testAssertInMenolakTransisiIlegal(): void
    {
        $method = new ReflectionMethod(PengaduanFlowService::class, 'assertIn');
        $method->setAccessible(true);

        try {
            $method->invoke(null, ['diproses_cs'], 'menunggu_verifikasi', 'selesai');
            self::fail('Transisi ilegal tidak ditolak.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            self::assertArrayHasKey('status', $errors);
            self::assertStringContainsString('Transisi status tidak diizinkan', $errors['status']);
        }
    }

    public function testAssertInMenerimaTransisiResmi(): void
    {
        $method = new ReflectionMethod(PengaduanFlowService::class, 'assertIn');
        $method->setAccessible(true);

        $method->invoke(null, ['diproses_cs', 'ditolak'], 'menunggu_verifikasi', 'diproses_cs');

        $this->addToAssertionCount(1);
    }

    public function testValidTextMenolakKosongDanTerlaluPanjang(): void
    {
        $method = new ReflectionMethod(PengaduanFlowService::class, 'validText');
        $method->setAccessible(true);

        self::assertSame('alasan sah', $method->invoke(null, '  alasan sah  ', 'alasan', 'Wajib diisi.'));

        try {
            $method->invoke(null, '   ', 'alasan', 'Alasan penolakan wajib diisi.');
            self::fail('Teks kosong tidak ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame('Alasan penolakan wajib diisi.', $exception->errors()['alasan'] ?? null);
        }

        try {
            $method->invoke(null, str_repeat('a', 2001), 'catatan', 'Wajib diisi.');
            self::fail('Teks melebihi 2000 karakter tidak ditolak.');
        } catch (ValidationException $exception) {
            self::assertSame('Maksimal 2000 karakter.', $exception->errors()['catatan'] ?? null);
        }

        self::assertSame(
            str_repeat('a', 2000),
            $method->invoke(null, str_repeat('a', 2000), 'catatan', 'Wajib diisi.')
        );
    }

    public function testStatusAktifDanStatusAkhir(): void
    {
        self::assertSame(
            ['menunggu_verifikasi', 'diproses_cs', 'menunggu_teknisi', 'dalam_penanganan'],
            active_statuses()
        );

        foreach (active_statuses() as $status) {
            self::assertTrue(status_is_active($status), 'Status aktif salah: ' . $status);
        }

        self::assertFalse(status_is_active('selesai'));
        self::assertFalse(status_is_active('ditolak'));
        self::assertFalse(status_is_active('dibatalkan'));

        self::assertSame(
            ['menunggu_verifikasi', 'diproses_cs', 'menunggu_teknisi', 'dalam_penanganan', 'selesai', 'ditolak'],
            status_keys()
        );
    }
}
