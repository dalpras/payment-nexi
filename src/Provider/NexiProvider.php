<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Provider;

use DalPraS\Payment\Contract\PaymentProviderInterface;
use DalPraS\Payment\Dto\AuthorizationResult;
use DalPraS\Payment\Dto\AuthorizeRequest;
use DalPraS\Payment\Dto\CancelRequest;
use DalPraS\Payment\Dto\CancelResult;
use DalPraS\Payment\Dto\CaptureRequest;
use DalPraS\Payment\Dto\CaptureResult;
use DalPraS\Payment\Dto\CheckoutRequest;
use DalPraS\Payment\Dto\CheckoutResponse;
use DalPraS\Payment\Dto\CompletionRequest;
use DalPraS\Payment\Dto\CompletionResult;
use DalPraS\Payment\Dto\RefundRequest;
use DalPraS\Payment\Dto\RefundResult;
use DalPraS\Payment\Dto\SyncRequest;
use DalPraS\Payment\Dto\SyncResult;
use DalPraS\Payment\Dto\VerificationResult;
use DalPraS\Payment\Dto\WebhookEvent;
use DalPraS\Payment\Enum\PaymentStatus;
use DalPraS\Payment\Nexi\Config\NexiConfig;
use DalPraS\Payment\Nexi\Contract\NexiHttpClientInterface;
use DalPraS\Payment\Nexi\Exception\NexiApiException;
use DalPraS\Payment\Nexi\Exception\NexiConfigurationException;
use DalPraS\Payment\Nexi\Mapper\NexiOrderMapper;
use DalPraS\Payment\Nexi\Support\NexiStatusMapper;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Nexi XPay provider implementation.
 *
 * Checkout uses the Hosted Payment Page order id, while capture/refund/cancel use
 * operation ids. The provider therefore returns both generic metadata keys
 * (order_id, operation_id) and Nexi-specific aliases (nexi_order_id,
 * nexi_operation_id) so payment-core can store and reuse them safely.
 */
final class NexiProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly NexiConfig $config,
        private readonly NexiHttpClientInterface $httpClient,
        private readonly NexiOrderMapper $mapper,
    ) {
    }

    public function code(): string
    {
        return 'nexi';
    }

    public function createCheckout(CheckoutRequest $request): CheckoutResponse
    {
        $payload = $this->mapper->mapCreateHostedPaymentPayload(
            new CheckoutRequest(
                providerCode: $request->providerCode,
                paymentReference: $request->paymentReference,
                merchantReference: $request->merchantReference,
                customer: $request->customer,
                items: $request->items,
                amounts: $request->amounts,
                returnUrl: $request->returnUrl,
                cancelUrl: $request->cancelUrl,
                webhookUrl: $request->webhookUrl,
                intent: $request->intent,
                locale: $request->locale,
                idempotencyKey: $request->idempotencyKey,
                correlationId: $request->correlationId,
                metadata: $request->metadata,
                providerOptions: array_replace([
                    'language' => $this->config->defaultLanguage,
                    'payment_service' => $this->config->paymentService,
                    'capture_type' => $this->config->defaultCaptureType,
                ], $request->providerOptions),
            )
        );

        $correlationId = $this->resolveCorrelationId($request->correlationId, $request->idempotencyKey, $request->paymentReference);
        $response = $this->httpClient->createHostedPaymentPage($payload, $correlationId);
        $securityToken = isset($response['securityToken']) && is_string($response['securityToken'])
            ? $response['securityToken']
            : null;

        return new CheckoutResponse(
            status: PaymentStatus::PendingCustomerAction,
            redirectRequired: true,
            redirectUrl: isset($response['hostedPage']) && is_string($response['hostedPage']) ? $response['hostedPage'] : null,
            providerPaymentId: $request->merchantReference,
            providerToken: $securityToken,
            raw: $response,
            message: $response['result'] ?? null,
            metadata: $this->filterMetadata([
                'provider' => $this->code(),
                'provider_payment_id' => $request->merchantReference,
                'order_id' => $request->merchantReference,
                'nexi_order_id' => $request->merchantReference,
                'nexi_security_token' => $securityToken,

                // Persist original HPP context for later status mapping.
                'nexi_action_type' => 'PAY',
                'nexi_capture_type' => $request->providerOptions['capture_type']
                    ?? $this->config->defaultCaptureType,

                'force_capture_after_authorization' => $request->providerOptions['force_capture_after_authorization'] ?? null,
                'capture_description' => $request->providerOptions['capture_description'] ?? null,
                'amount_minor' => (string) $request->amounts->grandTotal->minorAmount(),
                'currency' => $request->amounts->grandTotal->currency()->value,
            ]),
        );
    }

    public function completeCheckout(CompletionRequest $request): CompletionResult
    {
        $orderId = $request->queryParams['orderId']
            ?? $request->queryParams['order_id']
            ?? $request->queryParams['codTrans']
            ?? $request->bodyParams['orderId']
            ?? $request->bodyParams['order_id']
            ?? $request->bodyParams['codTrans']
            ?? $request->expectedProviderPaymentId
            ?? $request->metadata['nexi_order_id']
            ?? $request->metadata['order_id']
            ?? null;

        if (!is_string($orderId) || $orderId === '') {
            throw new NexiConfigurationException('Missing Nexi order id for checkout completion.');
        }

        $correlationId = $this->resolveCorrelationId(
            $request->correlationId,
            $request->metadata['correlation_id'] ?? null,
            $request->metadata['nexi_correlation_id'] ?? null,
            $request->idempotencyKey,
            $request->paymentReference,
        );
        $response = $this->httpClient->getOrder($orderId, $correlationId);
        $transactionIds = $this->extractTransactionIds($response);
        $metadata = array_replace_recursive(
            $request->metadata,
            $this->extractOrderMetadata($response, $orderId),
        );

        return new CompletionResult(
            status: NexiStatusMapper::fromOrderPayload($response, $request->metadata),
            providerPaymentId: $metadata['operation_id'] ?? $orderId,
            transactionIds: $transactionIds,
            message: $response['status'] ?? $response['result'] ?? null,
            raw: $response,
            metadata: $metadata,
        );
    }

    public function authorize(AuthorizeRequest $request): AuthorizationResult
    {
        return new AuthorizationResult(
            status: PaymentStatus::Unknown,
            providerPaymentId: $request->providerPaymentId,
            transactionIds: [],
            message: 'Nexi authorization is driven by checkout captureType in this provider.',
            raw: [],
            metadata: ['provider' => $this->code()],
        );
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        $operationId = $this->resolveOperationId($request->providerPaymentId, $request->metadata);
        $correlationId = $this->resolveCorrelationId(
            $request->metadata['correlation_id'] ?? null,
            $request->metadata['nexi_correlation_id'] ?? null,
            $request->idempotencyKey,
            $request->paymentReference,
        );
        $payload = $this->mapper->mapCapturePayload($request);
        $response = $this->httpClient->captureOperation($operationId, $payload, $correlationId, $request->idempotencyKey);
        $newOperationId = isset($response['operationId']) && is_string($response['operationId']) ? $response['operationId'] : null;

        return new CaptureResult(
            status: PaymentStatus::Captured,
            providerPaymentId: $operationId,
            transactionIds: array_values(array_filter([$newOperationId], 'is_string')),
            message: $newOperationId,
            raw: $response,
            metadata: $this->filterMetadata([
                'provider' => $this->code(),
                'operation_id' => $newOperationId ?? $operationId,
                'nexi_operation_id' => $newOperationId ?? $operationId,
                'nexi_capture_operation_id' => $newOperationId,
                'nexi_source_operation_id' => $operationId,
            ]),
        );
    }

    public function cancel(CancelRequest $request): CancelResult
    {
        $operationId = $this->resolveCancelOperationId($request->providerPaymentId, $request->metadata);

        if ($operationId === null) {
            $orderId = $this->resolveOrderId($request->providerPaymentId, $request->metadata);

            return new CancelResult(
                status: PaymentStatus::Cancelled,
                providerPaymentId: $request->providerPaymentId,
                message: 'Pagamento annullato dall’utente.',
                metadata: $this->filterMetadata([
                    'provider' => $this->code(),
                    'order_id' => $orderId,
                    'nexi_order_id' => $orderId,
                    'nexi_cancel_local_only' => true,
                ]),
            );
        }

        $correlationId = $this->resolveCorrelationId(
            $request->metadata['correlation_id'] ?? null,
            $request->metadata['nexi_correlation_id'] ?? null,
            $request->idempotencyKey,
            $request->paymentReference,
        );
        $payload = $this->mapper->mapCancelPayload($request);

        try {
            $response = $this->httpClient->cancelOperation($operationId, $payload, $correlationId, $request->idempotencyKey);
        } catch (NexiApiException $exception) {
            if ($exception->statusCode() !== 404) {
                throw $exception;
            }

            return new CancelResult(
                status: PaymentStatus::Cancelled,
                providerPaymentId: $operationId,
                message: 'Pagamento annullato dall’utente.',
                raw: $exception->responsePayload(),
                metadata: $this->filterMetadata([
                    'provider' => $this->code(),
                    'operation_id' => $operationId,
                    'nexi_operation_id' => $operationId,
                    'nexi_cancel_missing_remote_operation' => true,
                    'nexi_cancel_error_status' => $exception->statusCode(),
                ]),
            );
        }

        $newOperationId = isset($response['operationId']) && is_string($response['operationId']) ? $response['operationId'] : null;

        return new CancelResult(
            status: PaymentStatus::Cancelled,
            providerPaymentId: $operationId,
            transactionIds: array_values(array_filter([$newOperationId], 'is_string')),
            message: $newOperationId,
            raw: $response,
            metadata: $this->filterMetadata([
                'provider' => $this->code(),
                'operation_id' => $operationId,
                'nexi_operation_id' => $operationId,
                'nexi_cancel_operation_id' => $newOperationId,
            ]),
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $operationId = $this->resolveOperationId($request->providerPaymentId, $request->metadata);
        $correlationId = $this->resolveCorrelationId(
            $request->metadata['correlation_id'] ?? null,
            $request->metadata['nexi_correlation_id'] ?? null,
            $request->idempotencyKey,
            $request->paymentReference,
        );
        $payload = $this->mapper->mapRefundPayload($request);
        $response = $this->httpClient->refundOperation($operationId, $payload, $correlationId, $request->idempotencyKey);
        $newOperationId = isset($response['operationId']) && is_string($response['operationId']) ? $response['operationId'] : null;

        return new RefundResult(
            status: PaymentStatus::Refunded,
            providerPaymentId: $operationId,
            transactionIds: array_values(array_filter([$newOperationId], 'is_string')),
            message: $newOperationId,
            raw: $response,
            metadata: $this->filterMetadata([
                'provider' => $this->code(),
                'operation_id' => $operationId,
                'nexi_operation_id' => $operationId,
                'nexi_refund_operation_id' => $newOperationId,
            ]),
        );
    }

    public function sync(SyncRequest $request): SyncResult
    {
        $orderId = $request->metadata['nexi_order_id']
            ?? $request->metadata['order_id']
            ?? $request->providerPaymentId
            ?? null;

        if (!is_string($orderId) || $orderId === '') {
            throw new NexiConfigurationException('Missing Nexi order id for sync operation.');
        }

        $correlationId = $this->resolveCorrelationId(
            $request->metadata['correlation_id'] ?? null,
            $request->metadata['nexi_correlation_id'] ?? null,
            $request->idempotencyKey,
            $request->paymentReference,
        );
        $response = $this->httpClient->getOrder($orderId, $correlationId);
        $transactionIds = $this->extractTransactionIds($response);
        $metadata = array_replace_recursive(
            $request->metadata,
            $this->extractOrderMetadata($response, $orderId),
        );

        return new SyncResult(
            status: NexiStatusMapper::fromOrderPayload($response, $request->metadata),
            providerPaymentId: $metadata['operation_id'] ?? $orderId,
            transactionIds: $transactionIds,
            message: $response['status'] ?? $response['result'] ?? null,
            raw: $response,
            metadata: $metadata,
        );
    }

    public function parseWebhook(ServerRequestInterface $request): WebhookEvent
    {
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            $payload = $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];
        } else {
            $payload = $request->getParsedBody();
            if (!is_array($payload)) {
                $payload = [];
            }
        }

        return new WebhookEvent(
            providerCode: $this->code(),
            eventType: (string) ($payload['operationType'] ?? $payload['eventType'] ?? 'unknown'),
            providerPaymentId: isset($payload['order']['orderId']) && is_string($payload['order']['orderId'])
                ? $payload['order']['orderId']
                : (isset($payload['orderId']) && is_string($payload['orderId']) ? $payload['orderId'] : null),
            payload: $payload,
            headers: $request->getHeaders(),
        );
    }

    public function verifyWebhook(WebhookEvent $event): VerificationResult
    {
        if ($this->config->notificationSharedSecret === null || $this->config->notificationSharedSecret === '') {
            return new VerificationResult(false, 'Missing configured Nexi notification shared secret.');
        }

        $provided = $event->payload['securityToken'] ?? $event->payload['security_token'] ?? null;
        if (!is_string($provided) || $provided === '') {
            return new VerificationResult(false, 'Missing Nexi notification security token.', $event->payload);
        }

        return new VerificationResult(
            verified: hash_equals($this->config->notificationSharedSecret, $provided),
            message: 'Compared notification token with configured shared secret.',
            raw: $event->payload,
        );
    }

    /** Resolve a Nexi operation id for cancellation, if one already exists.
     *
     * A customer can leave the Hosted Payment Page before Nexi creates any
     * payment operation. In that case the stored providerPaymentId is the order
     * id, not an operation id, so /operations/{id}/cancels would return 404.
     */
    private function resolveCancelOperationId(?string $providerPaymentId, array $metadata): ?string
    {
        $operationId = $metadata['operation_id']
            ?? $metadata['nexi_operation_id']
            ?? $metadata['nexi_capture_operation_id']
            ?? $metadata['nexi_authorization_operation_id']
            ?? null;

        if (is_string($operationId) && $operationId !== '') {
            return $operationId;
        }

        $orderId = $this->resolveOrderId($providerPaymentId, $metadata);
        if ($orderId !== null && $providerPaymentId === $orderId) {
            return null;
        }

        return is_string($providerPaymentId) && $providerPaymentId !== '' ? $providerPaymentId : null;
    }

    private function resolveOrderId(?string $providerPaymentId, array $metadata): ?string
    {
        $orderId = $metadata['order_id']
            ?? $metadata['nexi_order_id']
            ?? null;

        if (is_string($orderId) && $orderId !== '') {
            return $orderId;
        }

        return is_string($providerPaymentId) && $providerPaymentId !== '' ? $providerPaymentId : null;
    }

    /** Resolve the Nexi operationId required by capture/refund/cancel endpoints. */
    private function resolveOperationId(?string $providerPaymentId, array $metadata): string
    {
        $operationId = $metadata['operation_id']
            ?? $metadata['nexi_operation_id']
            ?? $metadata['nexi_capture_operation_id']
            ?? $metadata['nexi_authorization_operation_id']
            ?? $providerPaymentId;

        if (!is_string($operationId) || $operationId === '') {
            throw new NexiConfigurationException('Missing Nexi operation id for post-payment operation.');
        }

        return $operationId;
    }

    /** Extract normalized IDs from GET /orders/{orderId} for future operations. */
    private function extractOrderMetadata(array $payload, string $orderId): array
    {
        $operationIds = $this->extractTransactionIds($payload);
        $mainOperationId = $this->extractMainOperationId($payload);

        return $this->filterMetadata([
            'provider' => $this->code(),
            'provider_payment_id' => $mainOperationId ?? $orderId,
            'order_id' => $orderId,
            'nexi_order_id' => $orderId,
            'operation_id' => $mainOperationId,
            'nexi_operation_id' => $mainOperationId,
            'nexi_transaction_ids' => $operationIds,
        ]);
    }

    /** Prefer successful payment/capture/authorization operations as the reusable operation id. */
    private function extractMainOperationId(array $payload): ?string
    {
        $operations = isset($payload['operations']) && is_array($payload['operations']) ? $payload['operations'] : [];

        foreach (['CAPTURE', 'PAYMENT', 'AUTHORIZATION'] as $preferredType) {
            foreach ($operations as $operation) {
                if (!is_array($operation)) {
                    continue;
                }

                $operationType = strtoupper((string) ($operation['operationType'] ?? ''));
                $operationResult = strtoupper((string) ($operation['operationResult'] ?? ''));

                if ($operationType === $preferredType
                    && in_array($operationResult, ['EXECUTED', 'SUCCESS', 'OK'], true)
                    && isset($operation['operationId'])
                    && is_string($operation['operationId'])
                ) {
                    return $operation['operationId'];
                }
            }
        }

        return $this->extractTransactionIds($payload)[0] ?? null;
    }

    private function extractTransactionIds(array $payload): array
    {
        $ids = [];

        if (isset($payload['operations']) && is_array($payload['operations'])) {
            foreach ($payload['operations'] as $operation) {
                if (is_array($operation) && isset($operation['operationId']) && is_string($operation['operationId'])) {
                    $ids[] = $operation['operationId'];
                }
            }
        }

        if (isset($payload['operationId']) && is_string($payload['operationId'])) {
            array_unshift($ids, $payload['operationId']);
        }

        return array_values(array_unique($ids));
    }

    /** Nexi requires Correlation-Id to be UUID-formatted on every API request. */
    private function resolveCorrelationId(?string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $this->isUuid($candidate)) {
                return $candidate;
            }
        }

        return $this->generateCorrelationId();
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function generateCorrelationId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function filterMetadata(array $metadata): array
    {
        return array_filter($metadata, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }
}
