<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Provider;

use DalPraS\Payment\Contract\PaymentProviderInterface;
use DalPraS\Payment\Dto\AuthorizeRequest;
use DalPraS\Payment\Dto\AuthorizationResult;
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
use DalPraS\Payment\Nexi\Exception\NexiConfigurationException;
use DalPraS\Payment\Nexi\Mapper\NexiOrderMapper;
use DalPraS\Payment\Nexi\Support\NexiStatusMapper;
use Psr\Http\Message\ServerRequestInterface;

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

        $correlationId = $request->correlationId ?? $request->idempotencyKey ?? $request->paymentReference;
        $response = $this->httpClient->createHostedPaymentPage($payload, $correlationId);

        return new CheckoutResponse(
            status: PaymentStatus::PendingCustomerAction,
            redirectRequired: true,
            redirectUrl: isset($response['hostedPage']) && is_string($response['hostedPage']) ? $response['hostedPage'] : null,
            providerPaymentId: $request->merchantReference,
            providerToken: isset($response['securityToken']) && is_string($response['securityToken']) ? $response['securityToken'] : null,
            raw: $response,
            message: $response['result'] ?? null,
        );
    }

    public function completeCheckout(CompletionRequest $request): CompletionResult
    {
        $orderId = $request->queryParams['orderId']
            ?? $request->bodyParams['orderId']
            ?? $request->expectedProviderPaymentId;

        if (!is_string($orderId) || $orderId === '') {
            throw new NexiConfigurationException('Missing Nexi order id for checkout completion.');
        }

        $correlationId = $request->idempotencyKey ?? $request->paymentReference;
        $response = $this->httpClient->getOrder($orderId, $correlationId);

        return new CompletionResult(
            status: NexiStatusMapper::fromOrderPayload($response),
            providerPaymentId: $orderId,
            transactionIds: $this->extractTransactionIds($response),
            message: $response['status'] ?? $response['result'] ?? null,
            raw: $response,
        );
    }

    public function authorize(AuthorizeRequest $request): AuthorizationResult
    {
        return new AuthorizationResult(
            status: PaymentStatus::Unknown,
            providerPaymentId: $request->providerPaymentId,
            transactionIds: [],
            message: 'Nexi authorization is driven by checkout captureType in this skeleton provider.',
            raw: [],
        );
    }

    public function capture(CaptureRequest $request): CaptureResult
    {
        $operationId = $this->resolveOperationId($request->providerPaymentId, $request->metadata);
        $correlationId = $request->idempotencyKey ?? $request->paymentReference;
        $payload = $this->mapper->mapCapturePayload($request);
        $response = $this->httpClient->captureOperation($operationId, $payload, $correlationId, $request->idempotencyKey);

        return new CaptureResult(
            status: PaymentStatus::Captured,
            providerPaymentId: $operationId,
            transactionIds: array_values(array_filter([$response['operationId'] ?? null], 'is_string')),
            message: $response['operationId'] ?? null,
            raw: $response,
        );
    }

    public function cancel(CancelRequest $request): CancelResult
    {
        $operationId = $this->resolveOperationId($request->providerPaymentId, $request->metadata);
        $correlationId = $request->idempotencyKey ?? $request->paymentReference;
        $payload = $this->mapper->mapCancelPayload($request);
        $response = $this->httpClient->cancelOperation($operationId, $payload, $correlationId, $request->idempotencyKey);

        return new CancelResult(
            status: PaymentStatus::Cancelled,
            providerPaymentId: $operationId,
            transactionIds: array_values(array_filter([$response['operationId'] ?? null], 'is_string')),
            message: $response['operationId'] ?? null,
            raw: $response,
        );
    }

    public function refund(RefundRequest $request): RefundResult
    {
        $operationId = $this->resolveOperationId($request->providerPaymentId, $request->metadata);
        $correlationId = $request->idempotencyKey ?? $request->paymentReference;
        $payload = $this->mapper->mapRefundPayload($request);
        $response = $this->httpClient->refundOperation($operationId, $payload, $correlationId, $request->idempotencyKey);

        return new RefundResult(
            status: PaymentStatus::Refunded,
            providerPaymentId: $operationId,
            transactionIds: array_values(array_filter([$response['operationId'] ?? null], 'is_string')),
            message: $response['operationId'] ?? null,
            raw: $response,
        );
    }

    public function sync(SyncRequest $request): SyncResult
    {
        $orderId = $request->providerPaymentId ?? ($request->metadata['order_id'] ?? null);
        if (!is_string($orderId) || $orderId === '') {
            throw new NexiConfigurationException('Missing Nexi order id for sync operation.');
        }

        $correlationId = $request->idempotencyKey ?? $request->paymentReference;
        $response = $this->httpClient->getOrder($orderId, $correlationId);

        return new SyncResult(
            status: NexiStatusMapper::fromOrderPayload($response),
            providerPaymentId: $orderId,
            transactionIds: $this->extractTransactionIds($response),
            message: $response['status'] ?? $response['result'] ?? null,
            raw: $response,
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

    private function resolveOperationId(?string $providerPaymentId, array $metadata): string
    {
        $operationId = $metadata['operation_id'] ?? $providerPaymentId;
        if (!is_string($operationId) || $operationId === '') {
            throw new NexiConfigurationException('Missing Nexi operation id for post-payment operation.');
        }

        return $operationId;
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
}
