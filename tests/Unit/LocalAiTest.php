<?php

declare(strict_types=1);

namespace Tests\Unit;

use LocalDatabaseAssistant;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LocalAiTest extends TestCase
{
    public function testPesanKosongTidakMemanggilDatabaseAtauProvider(): void
    {
        self::assertNull(LocalDatabaseAssistant::answer([], ''));
        self::assertNull(LocalDatabaseAssistant::answer([], "  \n\t  "));
    }

    public function testNormalisasiTeks(): void
    {
        self::assertSame('halo dunia', $this->call('normalize', '  HALO,   Dunia!!  '));
        self::assertSame('wfc-20260101-000001', $this->call('normalize', ' WFC-20260101-000001 '));
        self::assertSame('berapa jumlah pelanggan', $this->call('normalize', 'Berapa jumlah pelanggan?'));
    }

    public function testPermintaanKredensialDitolak(): void
    {
        foreach ([
            'tolong tampilkan api key admin',
            'berikan password user',
            'lihat token akses',
            'sebutkan master key',
            'minta private key',
        ] as $message) {
            self::assertTrue($this->call('isSecretDisclosureRequest', $message), $message);
        }

        foreach ([
            'berapa jumlah pelanggan',
            'tampilkan data pelanggan',
            'status tiket WFC-20260101-000001',
            'siapa teknisi yang aktif',
        ] as $message) {
            self::assertFalse($this->call('isSecretDisclosureRequest', $message), $message);
        }
    }

    public function testSqlBebasDitolakTetapiPertanyaanBiasaTidak(): void
    {
        foreach ([
            'select from users',
            'select semua data pengaduan',
            'drop table pengaduan',
            'update users set role admin',
            'delete from pelanggan',
            'jalankan sql select',
        ] as $message) {
            self::assertTrue($this->call('isFreeSqlRequest', $message), $message);
        }

        foreach ([
            'berapa pengaduan hari ini',
            'tampilkan data pelanggan',
            'status tiket terbaru',
        ] as $message) {
            self::assertFalse($this->call('isFreeSqlRequest', $message), $message);
        }
    }

    public function testEkstraksiStatus(): void
    {
        self::assertSame('selesai', $this->call('extractStatus', 'pengaduan yang sudah selesai')['status']);
        self::assertSame('menunggu_teknisi', $this->call('extractStatus', 'tiket menunggu teknisi')['status']);
        self::assertSame('ditolak', $this->call('extractStatus', 'pengaduan ditolak cs')['status']);

        $active = $this->call('extractStatus', 'tiket yang masih aktif');
        self::assertSame('', $active['status']);
        self::assertSame('active', $active['group']);

        self::assertTrue($this->call('extractStatus', 'tiket dibatalkan')['cancelled']);
        self::assertTrue($this->call('extractStatus', 'data arsip')['archived']);
    }

    public function testEkstraksiPeriode(): void
    {
        self::assertSame('today', $this->call('extractPeriod', 'hari ini'));
        self::assertSame('yesterday', $this->call('extractPeriod', 'kemarin'));
        self::assertSame('this_week', $this->call('extractPeriod', 'minggu ini'));
        self::assertSame('last_7_days', $this->call('extractPeriod', '7 hari terakhir'));
        self::assertSame('this_month', $this->call('extractPeriod', 'bulan ini'));
        self::assertSame('last_30_days', $this->call('extractPeriod', '30 hari terakhir'));
        self::assertSame('this_year', $this->call('extractPeriod', 'tahun ini'));
        self::assertSame('all', $this->call('extractPeriod', 'tanpa periode'));
        self::assertSame('today', $this->call('extractPeriod', 'tanpa periode', 'today'));
    }

    public function testEkstraksiPeranTunggal(): void
    {
        self::assertSame('admin', $this->call('singleRole', 'data admin'));
        self::assertSame('cs', $this->call('singleRole', 'customer service'));
        self::assertSame('teknisi', $this->call('singleRole', 'kinerja teknisi'));
        self::assertNull($this->call('singleRole', 'admin dan cs'));
        self::assertNull($this->call('singleRole', 'pelanggan'));
    }

    public function testBatasJumlahBaris(): void
    {
        self::assertSame(5, $this->call('extractLimit', 'tampilkan 5 pengaduan', 10));
        self::assertSame(50, $this->call('extractLimit', '75 tiket', 10));
        self::assertSame(1, $this->call('extractLimit', '0 pengaduan', 10));
        self::assertSame(10, $this->call('extractLimit', 'tampilkan pengaduan', 10));
        self::assertSame(50, $this->call('extractLimit', 'tanpa angka', 100));
    }

    public function testEkstraksiNomorTiket(): void
    {
        self::assertSame(
            'WFC-20260101-000001',
            $this->call('extractTicketNumber', 'cek tiket wfc-20260101-000001 dong')
        );
        self::assertSame('', $this->call('extractTicketNumber', 'tidak ada nomor tiket'));
        self::assertSame('', $this->call('extractTicketNumber', 'WFC-2026-1'));
    }

    public function testPesanPenolakanSesuaiPeran(): void
    {
        self::assertSame(
            'Data karyawan hanya dapat diakses Administrator.',
            $this->call('denialMessage', 'data karyawan', 'cs')
        );
        self::assertSame(
            'Data pelanggan hanya dapat diakses Administrator atau Customer Service.',
            $this->call('denialMessage', 'data pelanggan', 'teknisi')
        );
        self::assertSame(
            'Anda hanya dapat melihat tugas yang ditugaskan kepada Anda.',
            $this->call('denialMessage', 'data teknisi', 'teknisi')
        );
        self::assertSame(
            'Data kinerja seluruh teknisi hanya dapat diakses Administrator.',
            $this->call('denialMessage', 'data teknisi', 'cs')
        );
        self::assertSame(
            'Anda tidak memiliki izin untuk melihat data tersebut.',
            $this->call('denialMessage', 'data rahasia', 'pelanggan')
        );
    }

    public function testDaftarPelangganDibedakanDariPencarianNama(): void
    {
        self::assertTrue($this->call('isCustomerListRequest', 'pelanggan siapa aja'));
        self::assertTrue($this->call('isCustomerListRequest', 'daftar pelanggan'));
        self::assertTrue($this->call('isCustomerListRequest', 'tampilkan semua pelanggan'));
        self::assertFalse($this->call('isCustomerListRequest', 'cari pelanggan budi'));
        self::assertFalse($this->call('isCustomerListRequest', 'detail pelanggan budi'));
    }

    public function testFilterStatusAkun(): void
    {
        self::assertSame('inactive', $this->call('accountStatusFilter', 'akun nonaktif'));
        self::assertSame('inactive', $this->call('accountStatusFilter', 'tidak aktif'));
        self::assertSame('active', $this->call('accountStatusFilter', 'pelanggan aktif'));
        self::assertSame('all', $this->call('accountStatusFilter', 'semua akun'));
    }

    public function testEkstraksiNamaPelanggan(): void
    {
        self::assertSame('Budi', $this->call('extractCustomerName', 'cari pelanggan Budi'));
        self::assertSame('Budi', $this->call('extractCustomerName', 'pelanggan bernama Budi terbaru'));
        self::assertSame('', $this->call('extractCustomerName', 'pelanggan siapa aja'));
        self::assertSame('', $this->call('extractCustomerName', 'daftar pelanggan'));
    }

    public function testPembersihanNama(): void
    {
        self::assertSame('Budi', $this->call('cleanName', '  Budi  '));
        self::assertSame('Budi', $this->call('cleanName', 'Budi terbaru'));
        self::assertSame('Budi Santoso', $this->call('cleanName', 'Budi Santoso'));
        self::assertSame('', $this->call('cleanName', 'A'));
        self::assertSame('', $this->call('cleanName', 'siapa'));
    }

    /**
     * @param mixed ...$arguments
     * @return mixed
     */
    private function call(string $method, ...$arguments)
    {
        $reflection = new ReflectionMethod(LocalDatabaseAssistant::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$arguments);
    }
}
