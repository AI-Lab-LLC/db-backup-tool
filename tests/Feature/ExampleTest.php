<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Guests hitting the root URL are redirected to the login screen
     * (the panel is an internal tool behind auth — spec §8b).
     */
    public function test_guest_is_redirected_from_root_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }
}
