<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Support;

use DalPraS\Payment\Enum\PaymentStatus;

final class NexiStatusMapper
{
    public static function fromOrderPayload(array $payload, array $context = []): PaymentStatus
    {
        $operation = self::firstOperation($payload);

        $operationResult = strtoupper((string) (
            $operation['operationResult']
            ?? $payload['operationResult']
            ?? ''
        ));

        $operationType = strtoupper((string) (
            $operation['operationType']
            ?? $payload['operationType']
            ?? ''
        ));

        $status = strtoupper((string) ($payload['status'] ?? ''));

        $actionType = strtoupper((string) (
            $payload['paymentSession']['actionType']
            ?? $payload['paymentLink']['actionType']
            ?? $payload['actionType']
            ?? $context['nexi_action_type']
            ?? $context['action_type']
            ?? ''
        ));

        $captureType = strtoupper((string) (
            $payload['paymentSession']['captureType']
            ?? $payload['captureType']
            ?? $context['nexi_capture_type']
            ?? $context['capture_type']
            ?? ''
        ));

        if (in_array($operationResult, ['EXECUTED', 'SUCCESS', 'OK'], true)) {
            return match ($operationType) {
                'AUTHORIZATION' => self::executedAuthorizationStatus($actionType, $captureType),
                'CAPTURE', 'PAYMENT', 'PAY', 'SALE', 'ACCOUNTING' => PaymentStatus::Captured,
                'REFUND' => PaymentStatus::Refunded,
                'CANCEL', 'CANCELLATION', 'VOID' => PaymentStatus::Cancelled,
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

    /**
     * Nexi can report an executed HPP PAY/IMPLICIT payment as operationType
     * AUTHORIZATION even when the operation is already executed and cannot be
     * captured again.
     */
    private static function executedAuthorizationStatus(
        string $actionType,
        string $captureType,
    ): PaymentStatus {
        if ($actionType === 'PAY' && $captureType !== 'EXPLICIT') {
            return PaymentStatus::Captured;
        }

        if ($captureType === 'IMPLICIT') {
            return PaymentStatus::Captured;
        }

        return PaymentStatus::Authorized;
    }

    private static function firstOperation(array $payload): array
    {
        $operations = $payload['operations'] ?? [];

        if (!is_array($operations)) {
            return [];
        }

        $first = $operations[0] ?? [];

        return is_array($first) ? $first : [];
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
