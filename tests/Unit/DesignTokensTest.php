<?php

namespace Tests\Unit;

use App\Support\Shell;
use PHPUnit\Framework\TestCase;

/**
 * The palette in resources/css/tokens.css, checked as numbers: every text pair the kit uses meets WCAG AA (4.5:1 for text, 3:1 for the edge of a
 * control and for graphics), in the light theme and in the dark one. Adding a token pair to the kit means adding it here.
 */
class DesignTokensTest extends TestCase
{
    /** @var array<string, array{0: string, 1: string}> token => [light hex, dark hex] */
    private array $tokens = [];

    protected function setUp(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');
        preg_match_all('/--([a-z0-9-]+):\s*light-dark\((#[0-9a-fA-F]{6}),\s*(#[0-9a-fA-F]{6})\)/', $css, $m, PREG_SET_ORDER);
        foreach ($m as $row) {
            $this->tokens[$row[1]] ??= [strtolower($row[2]), strtolower($row[3])];
        }
        preg_match_all('/--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6});/', $css, $plain, PREG_SET_ORDER);
        foreach ($plain as $row) {
            $this->tokens[$row[1]] ??= [strtolower($row[2]), strtolower($row[2])];
        }
        // Aliases such as --toast-bg: var(--ink);
        preg_match_all('/--([a-z0-9-]+):\s*var\(--([a-z0-9-]+)\);/', $css, $alias, PREG_SET_ORDER);
        foreach ($alias as $row) {
            if (isset($this->tokens[$row[2]])) {
                $this->tokens[$row[1]] ??= $this->tokens[$row[2]];
            }
        }
    }

    private function luminance(string $hex): float
    {
        $c = array_map(fn ($i) => hexdec(substr($hex, $i, 2)) / 255, [1, 3, 5]);
        $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(string $a, string $b): float
    {
        [$hi, $lo] = [max($this->luminance($a), $this->luminance($b)), min($this->luminance($a), $this->luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** @return array<string, array{0: string, 1: string, 2: float}> */
    public static function pairs(): array
    {
        $text = 4.5;
        $edge = 3.0;
        $pairs = [];
        foreach (['ground', 'surface', 'sunken'] as $ground) {
            foreach (['ink', 'ink-2', 'ink-3', 'brand'] as $ink) {
                $pairs["$ink on $ground"] = [$ink, $ground, $text];
            }
        }
        foreach (['ink', 'ink-2', 'ink-3', 'brand'] as $ink) {
            $pairs["$ink on raised"] = [$ink, 'raised', $text];
        }
        $pairs['on-brand on brand'] = ['on-brand', 'brand', $text];
        $pairs['on-brand on brand-hover'] = ['on-brand', 'brand-hover', $text];
        $pairs['brand on brand-soft'] = ['brand', 'brand-soft', $text];
        $pairs['ink on brand-soft'] = ['ink', 'brand-soft', $text];
        $pairs['ink-3 on photo-ground'] = ['ink-3', 'photo-ground', $text];
        foreach (['pos', 'cau', 'crit'] as $tone) {
            $pairs["$tone on $tone-bg"] = [$tone, "$tone-bg", $text];
            $pairs["$tone on surface"] = [$tone, 'surface', $text];
            $pairs["$tone on ground"] = [$tone, 'ground', $text];
        }
        $pairs['on-awning on awning'] = ['on-awning', 'awning', $text];
        $pairs['toast-fg on toast-bg'] = ['toast-fg', 'toast-bg', $text];
        $pairs['toast-accent on toast-bg'] = ['toast-accent', 'toast-bg', $edge];
        $pairs['toast-crit on toast-bg'] = ['toast-crit', 'toast-bg', $edge];
        foreach (['ground', 'surface', 'raised'] as $ground) {
            $pairs["line-strong on $ground"] = ['line-strong', $ground, $edge];
        }
        foreach (['ground', 'surface'] as $ground) {
            $pairs["focus on $ground"] = ['focus', $ground, $edge];
        }

        return $pairs;
    }

    /** @dataProvider pairs */
    #[\PHPUnit\Framework\Attributes\DataProvider('pairs')]
    public function test_each_pair_meets_aa_in_both_themes(string $fg, string $bg, float $min): void
    {
        foreach ([0 => 'light', 1 => 'dark'] as $i => $theme) {
            $fgHex = $this->resolve($fg)[$i];
            $bgHex = $this->resolve($bg)[$i];
            $this->assertGreaterThanOrEqual($min, $this->ratio($fgHex, $bgHex), sprintf('%s on %s in the %s theme (%s on %s)', $fg, $bg, $theme, $fgHex, $bgHex));
        }
    }

    /** @return array{0: string, 1: string} */
    private function resolve(string $token): array
    {
        $this->assertArrayHasKey($token, $this->tokens, "token --{$token} is declared in tokens.css");

        return $this->tokens[$token];
    }

    public function test_the_browser_chrome_colours_match_the_ground_token(): void
    {
        [$light, $dark] = $this->resolve('ground');
        $this->assertSame($light, strtolower(Shell::THEME_COLOR_LIGHT));
        $this->assertSame($dark, strtolower(Shell::THEME_COLOR_DARK));
    }

    public function test_the_stylesheets_past_the_token_file_name_no_literal_colour_or_font(): void
    {
        foreach (glob(dirname(__DIR__, 2).'/resources/css/*.css') as $file) {
            if (basename($file) === 'tokens.css' || basename($file) === 'fonts.css') {
                continue;
            }
            $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($file));
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $css, basename($file).' has a literal hex colour');
            $this->assertDoesNotMatchRegularExpression('/\brgba?\(/', $css, basename($file).' has a literal rgb colour');
            $this->assertDoesNotMatchRegularExpression('/font-family:\s*+(?!var\(|inherit)/', $css, basename($file).' names a font outside the tokens');
        }
    }
}
