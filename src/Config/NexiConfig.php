<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Config;

final class NexiConfig
{
    public function __construct(
        public readonly string $apiKey,
        public readonly bool $sandbox = false,
        public readonly string $defaultLanguage = 'ita',
        public readonly string $defaultCaptureType = 'IMPLICIT',
        public readonly ?string $paymentService = null,
        public readonly ?string $merchantName = null,
        public readonly ?string $notificationSharedSecret = null,
        public readonly int $timeoutSeconds = 30,
    ) {
    }

    public function baseUri(): string
    {
        return $this->sandbox
            ? 'https://xpaysandbox.nexigroup.com/api/phoenix-0.0/psp/api/v1'
            : 'https://xpay.nexigroup.com/api/phoenix-0.0/psp/api/v1';
    }
}
