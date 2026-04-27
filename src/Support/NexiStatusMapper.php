<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Support;

use DalPraS\Payment\Enum\PaymentStatus;

final class NexiStatusMapper
{
    public static function fromOrderPayload(array $payload): PaymentStatus
    {
        $operationResult = strtoupper((string) ($payload['operations'][0]['operationResult'] ?? $payload['operationResult'] ?? ''));
        $operationType = strtoupper((string) ($payload['operations'][0]['operationType'] ?? $payload['operationType'] ?? ''));
        $status = strtoupper((string) ($payload['status'] ?? ''));

        if (in_array($operationResult, ['EXECUTED', 'SUCCESS', 'OK'], true)) {
            return match ($operationType) {
                'AUTHORIZATION' => PaymentStatus::Authorized,
                'CAPTURE', 'PAYMENT' => PaymentStatus::Captured,
                'REFUND' => PaymentStatus::Refunded,
                'CANCEL', 'VOID' => PaymentStatus::Cancelled,
                default => PaymentStatus::Captured,
            };
        }

        if (in_array($status, ['PENDING', 'CREATED', 'IN_PROGRESS'], true)) {
            return PaymentStatus::PendingCustomerAction;
        }

        if (in_array($operationResult, ['DECLINED', 'DENIED', 'FAILED', 'ERROR'], true)) {
            return PaymentStatus::Failed;
        }

        if (in_array($status, ['CANCELLED', 'VOIDED'], true)) {
            return PaymentStatus::Cancelled;
        }

        return PaymentStatus::Unknown;
    }

    public static function fromNotificationType(?string $eventType): PaymentStatus
    {
        return match (strtoupper((string) $eventType)) {
            'AUTHORIZATION' => PaymentStatus::Authorized,
            'CAPTURE', 'PAYMENT' => PaymentStatus::Captured,
            'REFUND' => PaymentStatus::Refunded,
            'CANCEL', 'VOID' => PaymentStatus::Cancelled,
            default => PaymentStatus::Unknown,
        };
    }
}
