# dalpras/payment-nexi

Nexi XPay connector for `dalpras/payment-core`, focused on Hosted Payment Page checkout and operation-based post-payment actions.

Supported flow:

- create Hosted Payment Page order with `POST /orders/hpp`
- redirect the buyer to Nexi `hostedPage`
- complete browser return by querying `GET /orders/{orderId}`
- persist Nexi `operationId` metadata returned by order lookup
- capture, refund and cancel by calling operation endpoints
- sync order state
- parse basic notifications

## Installation

```bash
composer require dalpras/payment-nexi
```

## Dependencies

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
    defaultCaptureType: 'IMPLICIT',
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
```

Register the provider in `PaymentManager` through the core `ProviderRegistry`.

## Checkout payload mapping

`CheckoutRequest` maps to Nexi HPP as:

```php
[
    'order' => [
        'orderId' => $request->merchantReference,
        'amount' => (string) $request->amounts->grandTotal->minorAmount(),
        'currency' => $request->amounts->grandTotal->currency()->value,
        'customerInfo' => [...],
        'billingAddress' => [...],
    ],
    'paymentSession' => [
        'actionType' => 'PAY',      // sale
        'amount' => '5000',
        'language' => 'ita',
        'captureType' => 'IMPLICIT',
        'resultUrl' => $returnUrl,
        'cancelUrl' => $cancelUrl,
        'notificationUrl' => $webhookUrl,
    ],
]
```

Intent mapping:

| Core intent | Nexi `actionType` | Nexi `captureType` |
| --- | --- | --- |
| `sale` | `PAY` | `IMPLICIT` |
| `authorize` | `PREAUTH` | `EXPLICIT` |
| `capture_later` | `PREAUTH` | `EXPLICIT` |

The `resultUrl` should include enough application context to reload the local payment, for example your payment UUID. The provider also stores `nexi_order_id`, so `PaymentManager::completeCheckout()` can enrich completion even if the browser return does not include `orderId`.

## Metadata returned by this provider

### Checkout creation

```php
[
    'provider' => 'nexi',
    'provider_payment_id' => $merchantReference,
    'order_id' => $merchantReference,
    'nexi_order_id' => $merchantReference,
    'nexi_security_token' => $securityToken,
]
```

### Completion / sync

After `GET /orders/{orderId}`, the provider extracts operation ids from the order payload and returns:

```php
[
    'provider' => 'nexi',
    'provider_payment_id' => $orderId,
    'order_id' => $orderId,
    'nexi_order_id' => $orderId,
    'operation_id' => $mainOperationId,
    'nexi_operation_id' => $mainOperationId,
    'nexi_transaction_ids' => [$operationId1, $operationId2],
]
```

`operation_id` is the generic key used by core for future capture/refund/cancel operations.

### Capture

```php
[
    'provider' => 'nexi',
    'operation_id' => $newOperationIdOrSourceOperationId,
    'nexi_operation_id' => $newOperationIdOrSourceOperationId,
    'nexi_capture_operation_id' => $newOperationId,
    'nexi_source_operation_id' => $sourceOperationId,
]
```

### Refund

```php
[
    'provider' => 'nexi',
    'operation_id' => $sourceOperationId,
    'nexi_operation_id' => $sourceOperationId,
    'nexi_refund_operation_id' => $refundOperationId,
]
```

### Cancel

```php
[
    'provider' => 'nexi',
    'operation_id' => $sourceOperationId,
    'nexi_operation_id' => $sourceOperationId,
    'nexi_cancel_operation_id' => $cancelOperationId,
]
```

## Completion

`completeCheckout()` resolves the Nexi order id from, in order:

1. `queryParams['orderId']`
2. `queryParams['order_id']`
3. `queryParams['codTrans']`
4. `bodyParams['orderId']`
5. `bodyParams['order_id']`
6. `bodyParams['codTrans']`
7. `expectedProviderPaymentId`
8. `metadata['nexi_order_id']`
9. `metadata['order_id']`

When used through `PaymentManager`, `expectedProviderPaymentId` and metadata are normally filled automatically from the stored `Payment`.

## Capture, refund and cancel

Nexi post-payment actions use operation ids, not the HPP order id.

The provider resolves the operation id from:

1. `metadata['operation_id']`
2. `metadata['nexi_operation_id']`
3. `metadata['nexi_capture_operation_id']`
4. `metadata['nexi_authorization_operation_id']`
5. `providerPaymentId`

When used through `PaymentManager`, this metadata is normally persisted after completion/sync and re-injected automatically.

### Refund request metadata

```php
$result = $paymentManager->refund(new RefundRequest(
    providerCode: 'nexi',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: $refundId,
    metadata: [
        'amount_minor' => '5000',
        'currency' => 'EUR',
        'description' => 'Customer refund',
    ],
));
```

### Cancel request metadata

```php
$result = $paymentManager->cancel(new CancelRequest(
    providerCode: 'nexi',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: $cancelId,
    metadata: [
        'description' => 'Cancel accounting operation',
    ],
));
```

The cancel payload intentionally sends only `description`; the operation id is part of the URL.

## Notification verification

The current implementation provides a basic shared-secret/security-token comparison. Production systems should confirm the exact Nexi notification signing/verification strategy used by the merchant account and add reconciliation through `sync()`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

Syntax check:

```bash
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

## License

MIT
