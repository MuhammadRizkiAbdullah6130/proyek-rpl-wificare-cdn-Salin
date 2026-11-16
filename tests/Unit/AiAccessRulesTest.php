<?php

declare(strict_types=1);

namespace Tests\Unit;

use AiToolService;
use PHPUnit\Framework\TestCase;

final class AiAccessRulesTest extends TestCase
{
    public function testAdminMendapatToolPalingLuas(): void
    {
        $names = AiToolService::allowedNames('admin');

        self::assertContains('search_customers', $names);
        self::assertContains('search_employees', $names);
        self::assertContains('get_customer_count', $names);
        self::assertContains('get_technician_assignments', $names);
    }

    public function testCsTidakBolehMelihatDataKaryawanAtauKinerjaTeknisi(): void
    {
        $names = AiToolService::allowedNames('cs');

        self::assertContains('search_customers', $names);
        self::assertContains('get_service_rating_summary', $names);
        self::assertNotContains('search_employees', $names);
        self::assertNotContains('get_customer_count', $names);
        self::assertNotContains('get_technician_assignments', $names);
    }

    public function testTeknisiHanyaMelihatTugasnyaSendiri(): void
    {
        $names = AiToolService::allowedNames('teknisi');

        self::assertContains('get_technician_assignments', $names);
        self::assertContains('get_technician_history', $names);
        self::assertNotContains('search_customers', $names);
        self::assertNotContains('search_employees', $names);
        self::assertNotContains('get_service_rating_summary', $names);
    }

    public function testPelangganTidakMelihatDataPelangganLain(): void
    {
        $names = AiToolService::allowedNames('pelanggan');

        self::assertContains('get_my_profile', $names);
        self::assertContains('get_my_tickets', $names);
        self::assertNotContains('search_customers', $names);
        self::assertNotContains('get_technician_assignments', $names);
        self::assertNotContains('get_service_rating_summary', $names);
    }

    public function testTidakAdaToolTulisPadaAllowlist(): void
    {
        $all = AiToolService::allowedNames();

        self::assertNotEmpty($all);

        foreach ($all as $name) {
            self::assertMatchesRegularExpression('/^(get|search)_[a-z0-9_]+$/', $name);
            self::assertDoesNotMatchRegularExpression('/(create|insert|update|delete|drop|alter|truncate|write|exec)/', $name);
        }
    }

    public function testDefinisiToolDisaringSesuaiPeran(): void
    {
        $csDefinitions = AiToolService::definitionsFor(['role' => 'cs']);
        $csNames = array_map(static fn (array $definition): string => (string) $definition['function']['name'], $csDefinitions);

        self::assertSame(AiToolService::allowedNames('cs'), $csNames);
        self::assertNotContains('search_employees', $csNames);

        $pelangganDefinitions = AiToolService::definitionsFor(['role' => 'pelanggan']);
        $pelangganNames = array_map(
            static fn (array $definition): string => (string) $definition['function']['name'],
            $pelangganDefinitions
        );

        self::assertSame(AiToolService::allowedNames('pelanggan'), $pelangganNames);
        self::assertContains('get_my_profile', $pelangganNames);
    }
}
