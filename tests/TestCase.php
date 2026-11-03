<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // No test methods here: every test class inherits from this one.

    protected function setUp(): void
    {
        parent::setUp();
        // Pages render without the compiled front-end (public/build is not in git, and CI
        // doesn't run npm run build).
        $this->withoutVite();
    }
}
