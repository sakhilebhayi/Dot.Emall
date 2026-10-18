<?php

namespace Tests\Feature\Currency;

use App\Providers\AppServiceProvider;
use App\Support\Shop;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The one storefront currency: where it comes from, how an amount is
 * printed, and what happens when the setting is wrong. The print format
 * deliberately matches Dot.Auction's Auction::money(): the symbol straight
 * against the number, a comma between thousands, a dot before two decimals.
 */
class ShopMoneyTest extends TestCase
{
    public function test_the_default_currency_is_south_african_rand(): void
    {
        $this->assertSame('ZAR', config('shop.currency'));
        $this->assertSame('ZAR', Shop::currency());
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}>
     */
    public static function formats(): array
    {
        return [
            'rand' => [123456, 'ZAR', 'R1,234.56'],
            'dollar' => [123456, 'USD', '$1,234.56'],
            'euro' => [123456, 'EUR', '€1,234.56'],
            'pound' => [123456, 'GBP', '£1,234.56'],
            'unmapped code is a prefix' => [123456, 'CHF', 'CHF 1,234.56'],
            'zero' => [0, 'ZAR', 'R0.00'],
            'one cent' => [1, 'ZAR', 'R0.01'],
            'under a thousand has no separator' => [99999, 'ZAR', 'R999.99'],
            'millions' => [123456789, 'ZAR', 'R1,234,567.89'],
            'negative keeps the sign outside the symbol' => [-500, 'ZAR', '-R5.00'],
            'negative code prefix' => [-123456, 'CHF', '-CHF 1,234.56'],
        ];
    }

    #[DataProvider('formats')]
    public function test_cents_are_printed_with_the_currency_symbol_or_code(int $cents, string $currency, string $expected): void
    {
        $this->assertSame($expected, Shop::money($cents, $currency));
    }

    public function test_money_defaults_to_the_configured_storefront_currency(): void
    {
        $this->assertSame('R236.90', Shop::money(23690));

        config(['shop.currency' => 'EUR']);

        $this->assertSame('€236.90', Shop::money(23690));
    }

    public function test_an_explicit_currency_wins_over_the_storefront_setting(): void
    {
        config(['shop.currency' => 'EUR']);

        $this->assertSame('R236.90', Shop::money(23690, 'ZAR'));
    }

    public function test_decimal_amounts_are_converted_through_integer_cents(): void
    {
        $this->assertSame('R236.90', Shop::amount('236.90'));
        $this->assertSame('R236.90', Shop::amount(236.9));
        $this->assertSame('R30.00', Shop::amount(30));
        $this->assertSame('R0.00', Shop::amount(null));
        // 19.99 * 100 is 1998.9999999999998 as a float; it must still print as 19.99.
        $this->assertSame('R19.99', Shop::amount(19.99));
        $this->assertSame('-R4.50', Shop::amount('-4.50'));
    }

    public function test_the_symbol_helper_returns_null_for_codes_without_one(): void
    {
        $this->assertSame('R', Shop::symbol('ZAR'));
        $this->assertSame('$', Shop::symbol('USD'));
        $this->assertNull(Shop::symbol('CHF'));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidCodes(): array
    {
        return [
            'lowercase' => ['zar'],
            'mixed case' => ['Zar'],
            'too long' => ['RAND'],
            'too short' => ['ZA'],
            'a symbol' => ['$'],
            'a digit' => ['ZA1'],
            'padded' => [' ZAR'],
            'blank' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_an_invalid_currency_setting_is_refused_with_a_message_that_names_the_setting(mixed $code): void
    {
        config(['shop.currency' => $code]);

        try {
            Shop::currency();
            $this->fail('an invalid currency must not be returned');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('SHOP_CURRENCY', $e->getMessage());
            $this->assertStringContainsString('three uppercase letters', $e->getMessage());
        }
    }

    public function test_formatting_with_an_invalid_explicit_code_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Shop::money(100, 'dollars');
    }

    public function test_booting_the_app_with_an_invalid_currency_fails_loudly(): void
    {
        config(['shop.currency' => 'dollars']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SHOP_CURRENCY');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_booting_the_app_with_a_valid_currency_is_silent(): void
    {
        config(['shop.currency' => 'USD']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('USD', Shop::currency());
    }

    public function test_a_real_process_with_a_bad_env_value_refuses_to_start(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'list'], base_path(), array_merge(getenv(), ['SHOP_CURRENCY' => 'dollars', 'APP_ENV' => 'testing']));
        $process->run();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('SHOP_CURRENCY', $process->getOutput().$process->getErrorOutput());
    }

    public function test_a_real_process_with_a_good_env_value_starts(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'list'], base_path(), array_merge(getenv(), ['SHOP_CURRENCY' => 'EUR', 'APP_ENV' => 'testing']));
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
