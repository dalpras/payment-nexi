<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Exception;

use RuntimeException;

final class NexiApiException extends RuntimeException
{
    public static function fromResponse(int $statusCode, array $payload = []): self
    {
        $message = 'Nexi API request failed.';

        if (isset($payload['errors'][0]['description']) && is_string($payload['errors'][0]['description'])) {
            $message = $payload['errors'][0]['description'];
        } elseif (isset($payload['title']) && is_string($payload['title'])) {
            $message = $payload['title'];
        }

        return new self(sprintf('%s (HTTP %d)', $message, $statusCode), $statusCode);
    }
}
