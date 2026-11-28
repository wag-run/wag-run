<?php

namespace Tests;

use App\Models\Organization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * The organization the factories put users and workspaces in: the first
     * one, created if there's none yet. Tests use it where there's no signed-in
     * user for {@see Organization::current()} to work from.
     */
    protected function organization(): Organization
    {
        return Organization::query()->oldest('id')->first() ?? Organization::factory()->create();
    }

    /**
     * A fixed UUID for an authentication setting a test names, so a test can
     * create the setting and refer to it from a node by the same name.
     */
    protected function settingId(string $name): string
    {
        $hash = md5('setting:'.$name);

        return sprintf('%s-%s-7%s-8%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12));
    }
}
