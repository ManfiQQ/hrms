<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Laravel's placeholder test, kept and corrected rather than deleted.
 *
 * It asserted that `/` returns 200, which was true while the welcome page lived there. The
 * root now redirects to the login screen: this system has no public page, and every route
 * behind it is authenticated.
 */
class ExampleTest extends TestCase
{
    public function test_the_root_path_sends_a_visitor_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
