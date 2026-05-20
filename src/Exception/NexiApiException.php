<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Exception;

use RuntimeException;

final class NexiApiException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        private readonly int $statusCode = 0,
        private readonly array $responsePayload = []
    ) {
        parent::__construct($message, $code);
    }

    public static function fromResponse(int $statusCode, array $payload = []): self
    {
        $message = 'Nexi API request failed.';

        if (isset($payload['errors'][0]['description']) && is_string($payload['errors'][0]['description'])) {
            $message = $payload['errors'][0]['description'];
        } elseif (isset($payload['errors'][0]['message']) && is_string($payload['errors'][0]['message'])) {
            $message = $payload['errors'][0]['message'];
        } elseif (isset($payload['detail']) && is_string($payload['detail'])) {
            $message = $payload['detail'];
        } elseif (isset($payload['title']) && is_string($payload['title'])) {
            $message = $payload['title'];
        } elseif (isset($payload['message']) && is_string($payload['message'])) {
            $message = $payload['message'];
        }

        return new self(
            sprintf('%s (HTTP %d)', $message, $statusCode),
            $statusCode,
            $statusCode,
            $payload
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responsePayload(): array
    {
        return $this->responsePayload;
    }
}