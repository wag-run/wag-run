<?php

namespace Tests\Feature\Support;

use App\Support\TimeZones;
use Tests\TestCase;

class TimeZonesTest extends TestCase
{
    public function test_groups_time_zones_by_region_labelled_by_place(): void
    {
        $groups = TimeZones::grouped();

        $this->assertSame('London', $groups['Europe']['Europe/London']);
        $this->assertSame('Argentina / Buenos Aires', $groups['America']['America/Argentina/Buenos_Aires']);
        $this->assertArrayNotHasKey('UTC', $groups);
    }

    public function test_knows_only_the_servers_time_zones(): void
    {
        $this->assertTrue(TimeZones::isKnown('UTC'));
        $this->assertTrue(TimeZones::isKnown('America/New_York'));
        $this->assertFalse(TimeZones::isKnown('Mars/Olympus'));
    }
}
