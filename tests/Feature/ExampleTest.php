<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
