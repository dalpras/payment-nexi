<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Tests;

use DalPraS\Payment\Dto\CheckoutRequest;
use DalPraS\Payment\Enum\Currency;
use DalPraS\Payment\Enum\PaymentIntent;
use DalPraS\Payment\Enum\PaymentStatus;
use DalPraS\Payment\Nexi\Config\NexiConfig;
use DalPraS\Payment\Nexi\Mapper\NexiOrderMapper;
use DalPraS\Payment\Nexi\Provider\NexiProvider;
use DalPraS\Payment\ValueObject\Address;
use DalPraS\Payment\ValueObject\AmountBreakdown;
use DalPraS\Payment\ValueObject\Customer;
use DalPraS\Payment\ValueObject\LineItem;
use DalPraS\Payment\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class NexiProviderTest extends TestCase
{
    public function testCreateCheckoutReturnsHostedPageAndMapsPayload(): void
    {
        $client = new FakeNexiHttpClient(
            createHostedPaymentPageResponse: [
                'hostedPage' => 'https://xpaysandbox.nexigroup.com/checkout/session-123',
                'securityToken' => 'sec-123',
            ],
        );

        $provider = new NexiProvider(
            new NexiConfig('sandbox-key', true),
            $client,
            new NexiOrderMapper(),
        );

        $response = $provider->createCheckout($this->checkoutRequest());

        self::assertTrue($response->redirectRequired);
        self::assertSame('https://xpaysandbox.nexigroup.com/checkout/session-123', $response->redirectUrl);
        self::assertSame('merchant-1', $response->providerPaymentId);
        self::assertSame('sec-123', $response->providerToken);
        self::assertSame(PaymentStatus::PendingCustomerAction, $response->status);
        self::assertSame('corr-1', $client->lastCreateCorrelationId);
        self::assertSame('merchant-1', $client->lastCreatePayload['order']['orderId']);
        self::assertSame('2500', $client->lastCreatePayload['order']['amount']);
        self::assertSame('EUR', $client->lastCreatePayload['order']['currency']);
        self::assertSame('buyer@example.com', $client->lastCreatePayload['customerInfo']['cardHolderEmail']);
        self::assertSame('https://example.com/pay/return?orderId=merchant-1', $client->lastCreatePayload['resultUrl']);
    }

    public function testMapperUsesExplicitCaptureForDeferredCaptureIntents(): void
    {
        $mapper = new NexiOrderMapper();
        $request = $this->checkoutRequest(PaymentIntent::CaptureLater);
        $payload = $mapper->mapCreateHostedPaymentPayload($request);

        self::assertSame('EXPLICIT', $payload['captureType']);
        self::assertSame('ita', $payload['language']);
    }

    private function checkoutRequest(PaymentIntent $intent = PaymentIntent::Sale): CheckoutRequest
    {
        $currency = Currency::EUR;

        return new CheckoutRequest(
            providerCode: 'nexi',
            paymentReference: 'payment-1',
            merchantReference: 'merchant-1',
            customer: new Customer(
                id: 'customer-1',
                email: 'buyer@example.com',
                fullName: 'Example Buyer',
                billingAddress: new Address(fullName: 'Example Buyer', line1: 'Via Example 1', city: 'Rome', postalCode: '00100', countryCode: 'IT'),
            ),
            items: [
                new LineItem(
                    sku: 'SKU-1',
                    name: 'Widget',
                    quantity: 1,
                    unitPrice: Money::fromDecimal('20.00', $currency),
                    taxAmount: Money::fromDecimal('5.00', $currency),
                    description: 'Test widget',
                ),
            ],
            amounts: new AmountBreakdown(
                subtotal: Money::fromDecimal('20.00', $currency),
                taxTotal: Money::fromDecimal('5.00', $currency),
                discountTotal: Money::fromDecimal('0.00', $currency),
                shippingTotal: Money::fromDecimal('0.00', $currency),
                grandTotal: Money::fromDecimal('25.00', $currency),
            ),
            returnUrl: 'https://example.com/pay/return?orderId=merchant-1',
            cancelUrl: 'https://example.com/pay/cancel',
            webhookUrl: 'https://example.com/pay/webhook',
            intent: $intent,
            locale: 'it-IT',
            idempotencyKey: 'checkout-1',
            correlationId: 'corr-1',
            metadata: ['description' => 'Test order'],
            providerOptions: ['language' => 'ita'],
        );
    }
}
