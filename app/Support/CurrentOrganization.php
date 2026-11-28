<?php

namespace App\Support;

use App\Models\Organization;

/**
 * The organization the current request or job is about. Middleware sets it for
 * web and API requests from the signed-in user, and code that runs without a
 * user (a job, a webhook) sets it from the record it works on. It's scoped, so
 * it starts empty for every request and every queued job.
 *
 * Read it through {@see Organization::current()}.
 */
class CurrentOrganization
{
    protected ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }
}
