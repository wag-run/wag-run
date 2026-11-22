<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_sends_people_to_their_dashboard(): void
    {
        $this->get(route('home'))->assertRedirect('/dashboard');
    }
}
