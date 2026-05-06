<?php

namespace Tests\Unit;

use GitManagerEnterprise\Http\Controllers\CheckoutController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

class EnterpriseCheckoutLocaleTest extends TestCase
{
    public function test_checkout_locale_maps_supported_app_locales_to_stripe_locales(): void
    {
        $this->assertSame('ja', $this->stripeLocaleFor('ja'));
        $this->assertSame('pt-BR', $this->stripeLocaleFor('pt_BR'));
        $this->assertSame('zh', $this->stripeLocaleFor('zh_CN'));
    }

    public function test_checkout_locale_falls_back_to_auto_for_unsupported_stripe_locales(): void
    {
        $this->assertSame('auto', $this->stripeLocaleFor('hi'));
        $this->assertSame('auto', $this->stripeLocaleFor('ar'));
    }

    private function stripeLocaleFor(string $locale): string
    {
        $controller = new CheckoutController();
        $request = Request::create('/checkout/enterprise', 'POST', [
            'locale' => $locale,
        ]);

        $method = new ReflectionMethod($controller, 'stripeCheckoutLocale');
        $method->setAccessible(true);

        return $method->invoke($controller, $request);
    }
}
