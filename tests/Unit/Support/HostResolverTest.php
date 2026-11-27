<?php

namespace Tests\Unit\Support;

use App\Support\HostResolver;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class HostResolverTest extends TestCase
{
    #[TestWith(['10.20.0.5', '10.20.0.5'])]
    #[TestWith(['[fd00::1]', 'fd00::1'])]
    public function test_gives_an_ip_address_back_without_looking_it_up(string $host, string $address): void
    {
        $this->assertSame([$address], (new HostResolver)->resolve($host));
    }

    /**
     * The one test that asks the machine's own resolver, which every other
     * test replaces with fixed answers. It uses the one name every machine
     * resolves without a network.
     */
    public function test_looks_a_host_name_up_with_the_machines_resolver(): void
    {
        $this->assertContains('127.0.0.1', (new HostResolver)->resolve('localhost'));
    }
}
