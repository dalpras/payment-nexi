# dalpras/payment-nexi

Nexi XPay connector skeleton for `dalpras/payment-core`.

This package is a starting point for a production Nexi connector built on top of the current XPay APIs, with the **Hosted Payment Page** flow as the primary checkout model:

- create hosted checkout session
- redirect buyer to Nexi hosted page
- complete browser return by querying the order result
- capture previously authorized operations
- refund / cancel operations
- sync order state
- parse notifications

## Status

Skeleton package only.

Included:
- provider implementation against `DalPraS\Payment\Contract\PaymentProviderInterface`
- config object
- PSR-18 HTTP client
- mapper from core DTOs to Nexi HPP payloads
- status mapping helpers
- notification parser stub
- tests for payload mapping and redirect extraction

Not yet included:
- production-grade notification verification
- full mapping of every Nexi notification field
- advanced XPay Build or 3-step flows
- operation action discovery via `GET /operations/{operationId}/actions`
- production retry policy and observability

## Installation

```bash
composer require dalpras/payment-nexi
```

## Dependencies

This package depends on:
- `dalpras/payment-core`
- `psr/http-client`
- `psr/http-factory`
- `psr/http-message`

Bring your own PSR-18 client and PSR-17 factories.

## Basic usage

```php
use DalPraS\Payment\Nexi\Config\NexiConfig;
use DalPraS\Payment\Nexi\Http\NexiHttpClient;
use DalPraS\Payment\Nexi\Mapper\NexiOrderMapper;
use DalPraS\Payment\Nexi\Provider\NexiProvider;

$config = new NexiConfig(
    apiKey: 'sandbox-api-key',
    sandbox: true,
    defaultLanguage: 'ita',
    defaultCaptureType: 'IMPLICIT'
);

$httpClient = new NexiHttpClient(
    config: $config,
    httpClient: $psr18Client,
    requestFactory: $requestFactory,
    streamFactory: $streamFactory,
);

$provider = new NexiProvider(
    config: $config,
    httpClient: $httpClient,
    mapper: new NexiOrderMapper(),
);

$response = $provider->createCheckout($checkoutRequest);

if ($response->redirectRequired) {
    header('Location: ' . $response->redirectUrl);
    exit;
}
```

## How it maps to the core package

### `CheckoutRequest`
Mapped to Nexi `POST /orders/hpp`.

- `merchantReference` -> `order.orderId`
- `grandTotal` -> `order.amount` in the smallest currency unit
- `currency` -> `order.currency`
- customer snapshot -> `customerInfo` / `billingAddress`
- `returnUrl` -> `resultUrl`
- `cancelUrl` -> `cancelUrl`
- `webhookUrl` -> `notificationUrl`
- `intent = sale` -> capture type `IMPLICIT`
- `intent = authorize` or `capture_later` -> capture type `EXPLICIT`
- provider options can constrain `paymentService`, `language`, and other HPP options

### `CompletionRequest`
This skeleton treats browser return as a UX event and resolves final state by querying `GET /orders/{orderId}`.
Pass the Nexi order id through one of:
- `queryParams['orderId']`
- `bodyParams['orderId']`
- `expectedProviderPaymentId`

### `CaptureRequest`
Uses `POST /operations/{operationId}/captures`.
Pass the Nexi operation id through:
- `metadata['operation_id']`, or
- `providerPaymentId`

### `RefundRequest`
Uses `POST /operations/{operationId}/refunds`.
Pass the Nexi operation id through:
- `metadata['operation_id']`, or
- `providerPaymentId`

### `CancelRequest`
Uses `POST /operations/{operationId}/cancels`.
Pass the Nexi operation id through:
- `metadata['operation_id']`, or
- `providerPaymentId`

## Recommended production additions

- persist the Nexi `securityToken` returned by checkout creation if you want to use it for notification validation
- persist the main Nexi `operationId` once known, so capture/refund/cancel are straightforward
- use browser return for UX and notification + sync for authoritative reconciliation
- keep correlation and idempotency keys stable across retries
- persist raw Nexi payloads for support and reconciliation

## Package layout

- `src/Config/NexiConfig.php`
- `src/Http/NexiHttpClient.php`
- `src/Mapper/NexiOrderMapper.php`
- `src/Provider/NexiProvider.php`
- `src/Support/NexiStatusMapper.php`
- `src/Exception/*`

## Next steps

A practical next step is to connect this package to your `dalpras/payment-core` `PaymentManager`, then add:
- persistence of Nexi order and operation ids
- notification verification based on your chosen reconciliation strategy
- PHPUnit fixtures for XPay sandbox responses
