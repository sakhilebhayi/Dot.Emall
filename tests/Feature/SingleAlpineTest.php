<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Livewire 3 bundles and starts Alpine. A second copy loaded from a CDN replaces window.Alpine and
 * breaks every Livewire re-render, so the layout must not load one.
 */
class SingleAlpineTest extends TestCase
{
    public function test_no_layout_loads_a_second_alpine(): void
    {
        $root = dirname(__DIR__, 2).'/resources/views/components/';

        foreach (['store-layout', 'account-layout', 'desk-shell', 'auth-layout'] as $layout) {
            $source = file_get_contents($root.$layout.'.blade.php');

            $this->assertIsString($source);
            $this->assertDoesNotMatchRegularExpression('/<script[^>]+alpinejs/i', $source, $layout);
            $this->assertStringContainsString('@livewireScripts', $source, $layout);
        }
    }
}
