<?php

namespace Tests\Concerns;

use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;

/**
 * Markup contract of the four layout contexts (store, account, seller desk, operator): a skip link, one main landmark, one h1, a theme control, and (R-52) the
 * shared dot-ui header with ONE labelled menu button that opens its drawer (#dh-drawer, a modal dialog, printed right after the header). Reads rendered HTML, never
 * pixels; behaviour lives in public/dot-ui/js/dot-nav.js and resources/js/app.js.
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

    /** The one header, one drawer contract of every layout context (R-52). */
    protected function assertResponsiveShell(TestResponse $response): DOMXPath
    {
        $response->assertOk();
        $xpath = $this->shellXPath($response);

        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content, "width=device-width")]')->length, 'viewport meta');
        $this->assertSame(1, $xpath->query('//a[contains(@class, "ds-skip")][@href="#main"]')->length, 'skip link');
        $this->assertSame(1, $xpath->query('//main[@id="main"]')->length, 'one main landmark');
        $this->assertSame(1, $xpath->query('//h1')->length, 'one h1');
        $this->assertGreaterThan(0, $xpath->query('//button[@data-theme-toggle] | //button[@data-dh-theme-set]')->length, 'a theme control: the icon button (signed out) or the account menu buttons (signed in)');
        $this->assertSame(1, $xpath->query('//title')->length);
        $this->assertSame(1, $xpath->query('//header[contains(concat(" ", normalize-space(@class), " "), " dh ")]')->length, 'one shared dot-ui header');

        $button = $xpath->query('//header//button[@data-dh-menu-button][@aria-label="Open menu"]');
        $this->assertSame(1, $button->length, 'one menu button');
        $this->assertSame('button', $button->item(0)->getAttribute('type'));
        $this->assertSame('dh-drawer', $button->item(0)->getAttribute('aria-controls'));
        $this->assertSame(1, $xpath->query('//div[@id="dh-drawer"]//div[@role="dialog"][@aria-modal="true"][@aria-label]')->length, 'the menu drawer');
        $this->assertGreaterThan(0, $xpath->query('//div[@id="dh-drawer"]//nav//a[@href]')->length, 'navigation links live inside the menu');
        $this->assertSame(0, $xpath->query('//*[contains(@class, "shell-legacy-nav")]')->length, 'no duplicate legacy navigation');
        $this->assertSame(0, $xpath->query('//dialog[@id="menu-drawer" or @id="desk-menu" or @id="account-menu-sheet"]')->length, 'the old phone menu dialogs are gone');

        return $xpath;
    }

    protected function assertScrollsHorizontally(DOMXPath $xpath, string $label): void
    {
        $wrapper = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " table-scroll ") or contains(concat(" ", normalize-space(@class), " "), " ds-table-wrap ")][@role="region"][@aria-label="'.$label.'"][@tabindex="0"]');
        $this->assertSame(1, $wrapper->length, "table region \"{$label}\" is an overflow-x container");
        $this->assertGreaterThan(0, $xpath->query('.//table', $wrapper->item(0))->length, "\"{$label}\" wraps a table");
    }
}
