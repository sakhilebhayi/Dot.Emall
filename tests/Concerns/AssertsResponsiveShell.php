<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

/**
 * Markup contract of the four layout contexts (store, account, seller desk, operator): a skip link, one main landmark, one h1, a theme toggle, and a
 * labelled menu button that opens a <dialog> (the phone menu). Reads rendered HTML, never pixels; behaviour lives in resources/js/app.js.
 */
trait AssertsResponsiveShell
{
    protected function shellXPath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** @param  string  $menuId  the dialog the menu button opens: menu-drawer (store, account) or desk-menu (seller, operator) */
    protected function assertResponsiveShell(TestResponse $response, string $menuId = 'menu-drawer'): DOMXPath
    {
        $response->assertOk();
        $xpath = $this->shellXPath($response);

        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content, "width=device-width")]')->length, 'viewport meta');
        $this->assertSame(1, $xpath->query('//a[contains(@class, "ds-skip")][@href="#main"]')->length, 'skip link');
        $this->assertSame(1, $xpath->query('//main[@id="main"]')->length, 'one main landmark');
        $this->assertSame(1, $xpath->query('//h1')->length, 'one h1');
        $this->assertGreaterThan(0, $xpath->query('//button[@data-theme-toggle]')->length, 'theme toggle');
        $this->assertSame(1, $xpath->query('//title')->length);

        $button = $xpath->query('//button[@data-dialog-open="'.$menuId.'"][@aria-label="Open menu"]');
        $this->assertSame(1, $button->length, 'one menu button');
        $this->assertSame('button', $button->item(0)->getAttribute('type'));
        $this->assertSame(1, $xpath->query('//dialog[@id="'.$menuId.'"][@aria-labelledby]')->length, 'the menu dialog');
        $this->assertGreaterThan(0, $xpath->query('//dialog[@id="'.$menuId.'"]//nav//a[@href]')->length, 'navigation links live inside the menu');
        $this->assertSame(0, $xpath->query('//*[contains(@class, "shell-legacy-nav")]')->length, 'no duplicate legacy navigation');

        return $xpath;
    }

    protected function assertScrollsHorizontally(DOMXPath $xpath, string $label): void
    {
        $wrapper = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " table-scroll ") or contains(concat(" ", normalize-space(@class), " "), " ds-table-wrap ")][@role="region"][@aria-label="'.$label.'"][@tabindex="0"]');
        $this->assertSame(1, $wrapper->length, "table region \"{$label}\" is an overflow-x container");
        $this->assertGreaterThan(0, $xpath->query('.//table', $wrapper->item(0))->length, "\"{$label}\" wraps a table");
    }
}
