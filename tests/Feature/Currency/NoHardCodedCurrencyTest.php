<?php

namespace Tests\Feature\Currency;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A currency symbol must never be typed into a view or a notification again:
 * it comes from App\Support\Shop, which reads the storefront currency. This
 * scans the places that print money for a literal dollar sign used as one.
 *
 * What counts as a hard-coded price:
 *   $12  $ 12            a dollar sign against a number
 *   ${{ ... }}           a dollar sign against a Blade echo ({{ is two braces)
 *   '$'  "$"             a string that is only a dollar sign (to be glued to a number)
 *   $'.  $".             a string that ENDS in a dollar sign and is concatenated
 *   &#36;  &dollar;      an entity or escape for it
 * What does not: PHP variables ($order, $this), a JS template placeholder
 * (${name}, one brace), a regex anchor ($/), and the symbol map inside
 * App\Support\Shop, which lives outside the scanned directories.
 */
class NoHardCodedCurrencyTest extends TestCase
{
    /** Explicit, reviewed exceptions as "relative/path:substring of the line". Keep this empty. */
    private const ALLOWED = [];

    private const FORBIDDEN = [
        '/(?<![\w$\\\\])\$ ?\d/',
        '/(?<![\w$\\\\])\$\s*\{\{/',
        '/(?<![\w$\\\\])\$\s*\{!!/',
        '/([\'"])\$\1/',
        '/\$[\'"]\s*\./',
        '/&#0*36;|&#x0*24;|&dollar;|\\\\u0024|\\\\x24/i',
        '/[€£]/u',
    ];

    /** @var list<string> */
    private const SCANNED = ['resources/views', 'app/Notifications', 'app/Mail', 'app/View', 'app/Livewire'];

    public function test_no_view_or_notification_hard_codes_a_currency_symbol(): void
    {
        $offences = [];
        $scanned = 0;

        foreach (self::SCANNED as $directory) {
            if (! is_dir(base_path($directory))) {
                continue;
            }

            foreach (Finder::create()->files()->in(base_path($directory))->name(['*.php', '*.html']) as $file) {
                $scanned++;
                $relative = $directory.'/'.str_replace('\\', '/', $file->getRelativePathname());

                foreach (preg_split('/\R/', $file->getContents()) as $number => $line) {
                    foreach (self::FORBIDDEN as $pattern) {
                        if (preg_match($pattern, $line) === 1 && ! $this->isAllowed($relative, $line)) {
                            $offences[] = $relative.':'.($number + 1).'  '.trim($line);
                            break;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(20, $scanned, 'the scan found suspiciously few files');
        $this->assertSame([], $offences, "Hard-coded currency symbols found. Print money with App\\Support\\Shop::money()/amount():\n".implode("\n", $offences));
    }

    public function test_the_allow_list_is_empty(): void
    {
        $this->assertSame([], self::ALLOWED, 'every exception needs a reviewed reason; prefer fixing the view');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function offendingLines(): array
    {
        return [
            'number' => ['<span>$12.00</span>'],
            'space' => ['Total: $ 12'],
            'blade echo' => ['<span>${{ number_format($x, 2) }}</span>'],
            'minus blade echo' => ['<span>-${{ $x }}</span>'],
            'blade raw echo' => ['<span>${!! $x !!}</span>'],
            'quoted symbol' => ["\$money = fn (\$c) => '\$'.number_format(\$c / 100, 2);"],
            'double quoted symbol' => ['$money = fn ($c) => "$".number_format($c, 2);'],
            'ends in symbol' => ["'worth \$'.number_format(\$c, 2)"],
            'entity' => ['<span>&#36;5</span>'],
            'named entity' => ['<span>&dollar;5</span>'],
            'euro' => ['<span>€5</span>'],
            'pound' => ['<span>£5</span>'],
        ];
    }

    #[DataProvider('offendingLines')]
    public function test_the_patterns_catch_each_way_of_hard_coding_a_price(string $line): void
    {
        $this->assertTrue($this->looksHardCoded($line), $line);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function innocentLines(): array
    {
        return [
            'php variable' => ['{{ $order->total }}'],
            'php variable with digit suffix' => ['{{ $line2->total }}'],
            'js template' => ['const label = `Item ${name} costs ${price}`;'],
            'js template after text' => ['el.textContent = `x${count}`;'],
            'regex anchor' => ["preg_match('/^[0-9]+\$/', \$v)"],
            'escaped dollar' => ['echo "\\$5";'],
            'array access' => ['{{ $items[0]->name }}'],
            'static call' => ['{{ \\App\\Support\\Shop::amount($product->price) }}'],
            'blade comment' => ['{{-- shows the price --}}'],
            'dollar in css url' => ['background: url(data:image/svg+xml;base64,AAAA)'],
        ];
    }

    #[DataProvider('innocentLines')]
    public function test_the_patterns_leave_variables_and_js_templates_alone(string $line): void
    {
        $this->assertFalse($this->looksHardCoded($line), $line);
    }

    private function looksHardCoded(string $line): bool
    {
        foreach (self::FORBIDDEN as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isAllowed(string $relative, string $line): bool
    {
        foreach (self::ALLOWED as $entry) {
            [$path, $needle] = explode(':', $entry, 2);

            if ($path === $relative && str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }
}
