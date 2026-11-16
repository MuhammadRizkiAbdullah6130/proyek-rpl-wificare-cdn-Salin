<?php

declare(strict_types=1);

namespace Tests\Integration;

use Auth;
use Tests\Support\DatabaseTestCase;

final class AuthAttemptTest extends DatabaseTestCase
{
    public function testLoginBerhasilDenganKredensialBenar(): void
    {
        $user = $this->createUser(['role' => 'cs']);

        $result = Auth::attempt($user['username'], $user['password']);

        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('user', $result);
        self::assertSame($user['id'], (int) $result['user']['id']);
        self::assertSame('cs', $result['user']['role']);
    }

    public function testUsernameDiTrimSaatLogin(): void
    {
        $user = $this->createUser(['role' => 'teknisi']);

        $result = Auth::attempt('  ' . $user['username'] . '  ', $user['password']);

        self::assertSame('ok', $result['status']);
        self::assertSame($user['id'], (int) $result['user']['id']);
    }

    public function testPasswordSalahDitolak(): void
    {
        $user = $this->createUser(['role' => 'admin']);

        $result = Auth::attempt($user['username'], 'Password-Salah-999!');

        self::assertSame('invalid', $result['status']);
        self::assertArrayNotHasKey('user', $result);
    }

    public function testUsernameTidakAdaDitolak(): void
    {
        $result = Auth::attempt('tidak.ada.' . bin2hex(random_bytes(4)), 'apa-saja');

        self::assertSame('invalid', $result['status']);
        self::assertArrayNotHasKey('user', $result);
    }

    public function testAkunNonaktifDitolakWalauPasswordBenar(): void
    {
        $user = $this->createUser(['role' => 'teknisi', 'active' => false]);

        $result = Auth::attempt($user['username'], $user['password']);

        self::assertSame('inactive', $result['status']);
        self::assertArrayNotHasKey('user', $result);
    }

    public function testBatasPercobaanGagalMengunciLogin(): void
    {
        $user = $this->createUser(['role' => 'pelanggan']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $result = Auth::attempt($user['username'], 'Password-Salah-' . $attempt);
            self::assertSame('invalid', $result['status'], 'Percobaan ke-' . $attempt);
        }

        $locked = Auth::attempt($user['username'], $user['password']);
        self::assertSame('locked', $locked['status']);
        self::assertArrayNotHasKey('user', $locked);
    }
}
