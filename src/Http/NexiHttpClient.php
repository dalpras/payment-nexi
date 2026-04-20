<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Http;

use DalPraS\Payment\Nexi\Config\NexiConfig;
use DalPraS\Payment\Nexi\Contract\NexiHttpClientInterface;
use DalPraS\Payment\Nexi\Exception\NexiApiException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class NexiHttpClient implements NexiHttpClientInterface
{
    public function __construct(
        private readonly NexiConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function createHostedPaymentPage(array $payload, string $correlationId): array
    {
        return $this->requestJson('POST', '/orders/hpp', $payload, $correlationId);
    }

    public function getOrder(string $orderId, string $correlationId): array
    {
        return $this->requestJson('GET', sprintf('/orders/%s', rawurlencode($orderId)), [], $correlationId);
    }

    public function captureOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->requestJson('POST', sprintf('/operations/%s/captures', rawurlencode($operationId)), $payload, $correlationId, $idempotencyKey);
    }

    public function refundOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->requestJson('POST', sprintf('/operations/%s/refunds', rawurlencode($operationId)), $payload, $correlationId, $idempotencyKey);
    }

    public function cancelOperation(string $operationId, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        return $this->requestJson('POST', sprintf('/operations/%s/cancels', rawurlencode($operationId)), $payload, $correlationId, $idempotencyKey);
    }

    private function requestJson(string $method, string $path, array $payload, string $correlationId, ?string $idempotencyKey = null): array
    {
        $request = $this->requestFactory->createRequest($method, $this->config->baseUri() . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Api-Key', $this->config->apiKey)
            ->withHeader('Correlation-Id', $correlationId);

        if ($method !== 'GET') {
            $request = $request->withHeader('Content-Type', 'application/json');

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $request = $request->withHeader('Idempotency-Key', $idempotencyKey);
            }

            $request = $request->withBody(
                $this->streamFactory->createStream((string) json_encode($payload, JSON_THROW_ON_ERROR))
            );
        }

        $response = $this->httpClient->sendRequest($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body !== '' ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : [];

        if ($status < 200 || $status >= 300) {
            throw NexiApiException::fromResponse($status, is_array($decoded) ? $decoded : []);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
