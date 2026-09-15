<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inertia responses render the root Blade view, which resolves asset
        // URLs through the Vite manifest. The backend suite asserts on the
        // page component and its props, not on the bundle, so it must not
        // require `npm run build` to have happened first.
        $this->withoutVite();
    }
}
