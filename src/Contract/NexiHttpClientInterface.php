<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Contract;

interface NexiHttpClientInterface
{
    public function createHostedPaymentPage(array $payload, string $correlationId): array;
    public function getOrder(string $orderId, string $correlationId): array;
    public function captureOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array;
    public function refundOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array;
    public function cancelOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array;
}
