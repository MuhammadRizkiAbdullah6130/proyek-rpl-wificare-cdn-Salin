<?php

declare(strict_types=1);

namespace Tests\Unit;

use Auth;
use PHPUnit\Framework\TestCase;

final class AuthRulesTest extends TestCase
{
    public function testDaftarPeranKanonikTidakBerubah(): void
    {
        self::assertSame(['admin', 'cs', 'teknisi', 'pelanggan'], Auth::ROLES);

        foreach (Auth::ROLES as $role) {
            self::assertTrue(Auth::isValidRole($role), 'Peran sah ditolak: ' . $role);
        }
    }

    public function testPeranTidakDikenalDitolak(): void
    {
        $invalid = ['', 'Admin', 'ADMIN', 'superadmin', 'pelanggan ', 'teknisi2', 'guest'];

        foreach ($invalid as $role) {
            self::assertFalse(Auth::isValidRole($role), 'Peran tidak sah diterima: ' . $role);
        }
    }

    public function testUsernameDanPasswordWajibDiisi(): void
    {
        self::assertSame(
            'Username dan password wajib diisi.',
            Auth::credentialInputError('', 'rahasia')
        );
        self::assertSame(
            'Username dan password wajib diisi.',
            Auth::credentialInputError('admin', '')
        );
        self::assertSame(
            'Username dan password wajib diisi.',
            Auth::credentialInputError('   ', '   ')
        );
    }

    public function testBatasPanjangInputLogin(): void
    {
        self::assertSame(
            'Username atau password tidak valid.',
            Auth::credentialInputError(str_repeat('a', 65), 'rahasia')
        );
        self::assertSame(
            'Username atau password tidak valid.',
            Auth::credentialInputError('admin', str_repeat('p', 201))
        );

        self::assertNull(Auth::credentialInputError(str_repeat('a', 64), str_repeat('p', 200)));
    }

    public function testInputLoginValidDanDiTrim(): void
    {
        self::assertNull(Auth::credentialInputError('  admin.cdn  ', 'Password-Uji-123!'));
    }

    public function testDashboardSesuaiPeran(): void
    {
        self::assertSame('/admin/dashboard', Auth::dashboardUrl('admin'));
        self::assertSame('/cs/dashboard', Auth::dashboardUrl('cs'));
        self::assertSame('/teknisi/dashboard', Auth::dashboardUrl('teknisi'));
        self::assertSame('/pelanggan/dashboard', Auth::dashboardUrl('pelanggan'));
    }

    public function testPeranTidakDikenalTidakMendapatDashboard(): void
    {
        self::assertSame('/login', Auth::dashboardUrl('superadmin'));
        self::assertSame('/login', Auth::dashboardUrl(''));
    }
}
