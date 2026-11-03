<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_loads_for_a_guest(): void
    {
        $this->get('/login')->assertOk()->assertSee('authentication-form', false);
        $this->get('/')->assertRedirect(route('login'));          // "/" is the dashboard root
    }

    public function test_the_dashboard_sends_a_guest_to_the_login_page(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
