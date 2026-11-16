<?php

declare(strict_types=1);

namespace Tests\Integration;

use Auth;
use Tests\Support\DatabaseTestCase;

final class RoleAccessTest extends DatabaseTestCase
{
    public function testTanpaSessionTidakTerautentikasi(): void
    {
        self::assertFalse(Auth::check());
        self::assertNull(Auth::user());
        self::assertNull(Auth::role());
        self::assertNull(Auth::id());
    }

    public function testPeranSelaluDibacaDariDatabaseBukanSession(): void
    {
        $user = $this->createUser(['role' => 'cs']);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['session_version'] = 0;
        $_SESSION['role'] = 'admin';

        self::assertTrue(Auth::check());
        self::assertSame($user['id'], Auth::id());
        self::assertSame('cs', Auth::role());
        self::assertSame('cs', Auth::user()['role']);
    }

    public function testMiddlewareMengizinkanPeranYangSesuai(): void
    {
        $user = $this->createUser(['role' => 'cs']);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['session_version'] = 0;

        \AuthMiddleware::auth();
        \AuthMiddleware::role(['cs', 'admin']);

        $this->addToAssertionCount(2);
    }
}
