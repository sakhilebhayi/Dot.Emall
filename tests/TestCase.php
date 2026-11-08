<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Symfony\Component\HttpFoundation\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // Host pinning (TrustHosts) is static state on Symfony's Request. A test that runs in "production" mode switches it
        // on, and it would otherwise pin every later test in the process to the APP_URL host.
        Request::setTrustedHosts([]);

        parent::tearDown();
    }
}
