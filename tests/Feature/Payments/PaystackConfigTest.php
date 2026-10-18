<?php

namespace Tests\Feature\Payments;

use App\Services\Paystack\PaymentGatewayException;
use App\Services\Paystack\PaystackClient;
use App\Services\Paystack\PaystackConfig;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

class PaystackConfigTest extends TestCase
{
    use BuildsPayments;

    public function test_with_no_key_paystack_is_not_configured(): void
    {
        config(['paystack.secret_key' => '']);

        $config = app(PaystackConfig::class);

        $this->assertFalse($config->isConfigured());
        $this->assertFalse($config->isTestMode());
        $this->assertFalse($config->isMisconfigured());
    }

    public function test_a_test_key_is_usable_and_flags_test_mode(): void
    {
        $this->enablePaystack('sk_test_abc123abc123');

        $config = app(PaystackConfig::class);

        $this->assertTrue($config->isConfigured());
        $this->assertTrue($config->isTestMode());
        $this->assertFalse($config->isMisconfigured());
        $this->assertSame('sk_test_abc123abc123', $config->secretKey());
    }

    public function test_a_live_key_is_refused_unless_live_is_explicitly_allowed(): void
    {
        $this->enablePaystack('sk_live_abc123abc123');
        $config = app(PaystackConfig::class);

        $this->assertTrue($config->isConfigured());
        $this->assertTrue($config->isMisconfigured());
        $this->assertFalse($config->isTestMode());

        $this->expectException(PaymentGatewayException::class);
        $this->expectExceptionMessage('live Paystack key');
        $config->secretKey();
    }

    public function test_a_live_key_is_never_sent_anywhere_when_refused(): void
    {
        $this->enablePaystack('sk_live_abc123abc123');
        $http = $this->fakeHttp();

        try {
            app(PaystackClient::class)->verifyTransaction('EMALL-1-abc');
            $this->fail('expected a config error');
        } catch (PaymentGatewayException) {
            // expected
        }

        $http->assertNothingSent();
    }

    public function test_a_live_key_works_only_with_the_second_switch_on(): void
    {
        $this->enablePaystack('sk_live_abc123abc123', ['paystack.allow_live' => true]);
        $config = app(PaystackConfig::class);

        $this->assertFalse($config->isMisconfigured());
        $this->assertFalse($config->isTestMode());
        $this->assertSame('sk_live_abc123abc123', $config->secretKey());
    }

    public function test_a_key_with_an_unknown_prefix_is_refused(): void
    {
        $this->enablePaystack('totally-not-a-paystack-key');

        $this->assertTrue(app(PaystackConfig::class)->isMisconfigured());
        $this->expectException(PaymentGatewayException::class);
        app(PaystackConfig::class)->secretKey();
    }

    public function test_a_non_https_base_url_is_refused(): void
    {
        $this->enablePaystack(null, ['paystack.base_url' => 'http://api.paystack.co']);

        $this->assertTrue(app(PaystackConfig::class)->isMisconfigured());
    }

    public function test_the_error_messages_never_contain_the_key(): void
    {
        $this->enablePaystack('sk_live_super_secret_value_999');

        try {
            app(PaystackConfig::class)->secretKey();
            $this->fail('expected an exception');
        } catch (PaymentGatewayException $e) {
            $this->assertStringNotContainsString('super_secret_value_999', $e->getMessage());
        }
    }

    public function test_a_blank_unpaid_window_falls_back_to_sixty_minutes(): void
    {
        config(['paystack.unpaid_window_minutes' => 0]);

        $this->assertSame(60, app(PaystackConfig::class)->unpaidWindowMinutes());
    }
}
