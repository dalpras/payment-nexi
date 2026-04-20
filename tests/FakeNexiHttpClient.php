<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Tests;

use DalPraS\Payment\Nexi\Contract\NexiHttpClientInterface;

final class FakeNexiHttpClient implements NexiHttpClientInterface
{
    public array $lastCreatePayload = [];
    public ?string $lastCreateCorrelationId = null;

    public function __construct(
        public array $createHostedPaymentPageResponse = [],
        public array $getOrderResponse = [],
        public array $captureOperationResponse = [],
        public array $refundOperationResponse = [],
        public array $cancelOperationResponse = [],
    ) {
    }

    public function createHostedPaymentPage(array $payload, string $correlationId): array
    {
        $this->lastCreatePayload = $payload;
        $this->lastCreateCorrelationId = $correlationId;
        return $this->createHostedPaymentPageResponse;
    }

    public function getOrder(string $orderId, string $correlationId): array
    {
        return $this->getOrderResponse;
    }

    public function captureOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->captureOperationResponse;
    }

    public function refundOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->refundOperationResponse;
    }

    public function cancelOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->cancelOperationResponse;
    }
}
