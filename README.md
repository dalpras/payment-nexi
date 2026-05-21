# dalpras/payment-nexi

Nexi XPay connector for `dalpras/payment-core`, focused on Hosted Payment Page checkout and operation-based post-payment actions.

Supported flow:

- create a Hosted Payment Page order with `POST /orders/hpp`
- redirect the buyer to Nexi `hostedPage`
- complete the browser return by querying `GET /orders/{orderId}`
- persist the Nexi `operationId` returned by order lookup
- capture, refund and cancel by calling Nexi operation endpoints
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
        'actionType' => 'PAY',
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

The `resultUrl` should include enough application context to reload the local payment, for example your payment UUID. When the provider is used through `PaymentManager`, the stored payment metadata is also used to enrich completion, refund, cancel, capture and sync requests.

## Provider options

Recommended Nexi options for a sale flow:

```php
providerOptions: [
    'language' => 'ita',
    'capture_type' => 'IMPLICIT',
]
```

### Force capture after authorization

Some Nexi accounts/terminals can return an `AUTHORIZATION` operation even when the checkout was created as a sale with `captureType = IMPLICIT`. Do not map that authorization to `Captured` manually. If you want the library to perform a real capture immediately after completion, enable the core force-capture policy:

```php
providerOptions: [
    'language' => 'ita',
    'capture_type' => 'IMPLICIT',
    'force_capture_after_authorization' => true,
    'capture_description' => 'Forced capture after Nexi authorization',
]
```

Also provide amount and currency in checkout metadata so the forced capture can build the Nexi capture payload:

```php
metadata: [
    'amount_minor' => (string) $total->minorAmount(),
    'currency' => $currency->value,
]
```

When completion returns `Authorized` and `force_capture_after_authorization` is true, `payment-core` calls `capture()` with the extracted Nexi `operationId`. The final completion result becomes the capture result when capture succeeds.

## Idempotency keys and correlation ids

Nexi operation APIs require UUID-formatted headers for provider requests, especially:

- `Idempotency-Key`
- `Correlation-Id`

This provider automatically generates a valid UUID `Correlation-Id` when none is provided or when the candidate value is not UUID-formatted. You may safely pass `correlationId: null` in `CheckoutRequest` and `CompletionRequest`.

Idempotency is different: the idempotency key is a business retry key and must be stable for the same logical operation. For Nexi, it must also be UUID-formatted because it is forwarded as the `Idempotency-Key` header on POST operation endpoints.

Do not use keys such as:

```php
$paymentReference . ':capture';
$paymentReference . ':cancel';
$paymentReference . ':refund';
```

Those are stable, but they are not UUID-formatted and Nexi will reject them.

Use `ramsey/uuid` UUID v5 when you need a deterministic key:

```php
use Ramsey\Uuid\Uuid;

$checkoutIdempotencyKey = Uuid::uuid5(
    Uuid::NAMESPACE_URL,
    $paymentReference . ':checkout'
)->toString();

$completionIdempotencyKey = Uuid::uuid5(
    Uuid::NAMESPACE_URL,
    $paymentReference . ':complete'
)->toString();

$captureIdempotencyKey = Uuid::uuid5(
    Uuid::NAMESPACE_URL,
    $paymentReference . ':capture'
)->toString();

$cancelIdempotencyKey = Uuid::uuid5(
    Uuid::NAMESPACE_URL,
    $paymentReference . ':cancel'
)->toString();

$refundIdempotencyKey = Uuid::uuid5(
    Uuid::NAMESPACE_URL,
    $paymentReference . ':refund:' . $refundId
)->toString();
```

For a one-time checkout key, UUID v4 is also valid:

```php
use Ramsey\Uuid\Uuid;

$idempotencyKey = Uuid::uuid4()->toString();
```

If you enable `force_capture_after_authorization`, make sure your `payment-core` version generates a UUID-formatted idempotency key for the internal forced capture. A deterministic UUID v5 based on `$paymentReference . ':capture'` is recommended.

## Example checkout request

```php
use Ramsey\Uuid\Uuid;

$providerOptions = [];

if ($providerCode === 'nexi') {
    $providerOptions = [
        'language' => 'ita',
        'capture_type' => 'IMPLICIT',
        'force_capture_after_authorization' => true,
        'capture_description' => sprintf('Forced capture for order %s', $order->getId()),
    ];
}

$request = new CheckoutRequest(
    providerCode: $providerCode,
    paymentReference: $paymentReference,
    merchantReference: 'order-' . $order->getId(),
    customer: $customer,
    items: $items,
    amounts: $amounts,
    returnUrl: $returnUrl,
    cancelUrl: $cancelUrl,
    webhookUrl: $webhookUrl,
    intent: PaymentIntent::Sale,
    locale: 'it-IT',
    idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_URL, $paymentReference . ':checkout')->toString(),
    correlationId: null,
    metadata: [
        'orderId' => $order->getId(),
        'amount_minor' => (string) $order->getAmountMoney()->minorAmount(),
        'currency' => $order->getCurrency()->value,
    ],
    providerOptions: $providerOptions,
);
```

## Metadata returned by this provider

### Checkout creation

At checkout creation time, the actionable provider reference is the Nexi HPP order id.

```php
[
    'provider' => 'nexi',
    'provider_payment_id' => $merchantReference,
    'order_id' => $merchantReference,
    'nexi_order_id' => $merchantReference,
    'nexi_security_token' => $securityToken,
    'amount_minor' => '5000',
    'currency' => 'EUR',
]
```

### Completion / sync

After `GET /orders/{orderId}`, the provider extracts operation ids from the order payload. At this point the actionable provider reference becomes the Nexi `operationId`, because capture/refund/cancel endpoints use operation ids, not the HPP order id.

```php
[
    'provider' => 'nexi',
    'provider_payment_id' => $mainOperationId,
    'order_id' => $orderId,
    'nexi_order_id' => $orderId,
    'operation_id' => $mainOperationId,
    'nexi_operation_id' => $mainOperationId,
    'nexi_transaction_ids' => [$operationId1, $operationId2],
]
```

`CompletionResult::$providerPaymentId` and `SyncResult::$providerPaymentId` are also set to `$mainOperationId` when available. The original HPP order id remains available as `metadata['nexi_order_id']`.

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

### Capture request metadata

```php
$result = $paymentManager->capture(new CaptureRequest(
    providerCode: 'nexi',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_URL, $paymentReference . ':capture')->toString(),
    metadata: [
        'amount_minor' => '5000',
        'currency' => 'EUR',
        'description' => 'Capture authorized Nexi payment',
    ],
));
```

### Refund request metadata

```php
$result = $paymentManager->refund(new RefundRequest(
    providerCode: 'nexi',
    paymentReference: $paymentReference,
    providerPaymentId: null,
    idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_URL, $paymentReference . ':refund:' . $refundId)->toString(),
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
    idempotencyKey: Uuid::uuid5(Uuid::NAMESPACE_URL, $paymentReference . ':cancel')->toString(),
    metadata: [
        'description' => 'Cancel accounting operation',
    ],
));
```

The cancel payload intentionally sends only `description`; the operation id is part of the URL.

## Persistent state

For redirect-based payments, use a persistent or cache-backed `PaymentRepositoryInterface` implementation, such as a Redis/Symfony Cache repository. Do not use an in-memory repository for production redirects because the payment metadata would be lost between checkout creation and completion.

Applications should also persist the final provider metadata in their own order/payment table, for example:

```php
$order
    ->setPaymentStatus($completionResult->status)
    ->setProviderPaymentId($completionResult->providerPaymentId)
    ->mergePaymentMetadata($completionResult->metadata);
```

For Nexi, after completion this normally means:

```php
$order->getProviderPaymentId(); // Nexi operationId
$order->getPaymentMetadata()['nexi_order_id']; // Original HPP order id
$order->getPaymentMetadata()['operation_id']; // Nexi operation id
```

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
