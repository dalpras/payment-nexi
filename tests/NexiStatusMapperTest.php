<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Tests;

use DalPraS\Payment\Enum\PaymentStatus;
use DalPraS\Payment\Nexi\Support\NexiStatusMapper;
use PHPUnit\Framework\TestCase;

final class NexiStatusMapperTest extends TestCase
{
    public function testPendingOperationDoesNotBecomeUnknown(): void
    {
        $status = NexiStatusMapper::fromOrderPayload([
            'orderStatus' => [
                'authorizedAmount' => '0',
                'capturedAmount' => '0',
            ],
            'operations' => [[
                'operationId' => '123456789',
                'operationType' => 'AUTHORIZATION',
                'operationResult' => 'PENDING',
            ]],
        ]);

        self::assertSame(PaymentStatus::PendingCustomerAction, $status);
    }

    public function testKnownOrderWithoutOperationsIsStillPendingCustomerAction(): void
    {
        $status = NexiStatusMapper::fromOrderPayload([
            'order' => [
                'orderId' => 'event-order-8082',
                'amount' => '150000',
                'currency' => 'EUR',
            ],
            'orderStatus' => [
                'authorizedAmount' => '0',
                'capturedAmount' => '0',
            ],
            'operations' => [],
        ]);

        self::assertSame(PaymentStatus::PendingCustomerAction, $status);
    }

    public function testPendingContextIsPreservedForUnrecognizedProviderPayload(): void
    {
        $status = NexiStatusMapper::fromOrderPayload(
            ['someFutureField' => 'future-value'],
            ['status' => PaymentStatus::PendingCustomerAction->value],
        );

        self::assertSame(PaymentStatus::PendingCustomerAction, $status);
    }

    public function testDocumentedFailureAndCancellationResultsAreMapped(): void
    {
        self::assertSame(
            PaymentStatus::Failed,
            NexiStatusMapper::fromOrderPayload([
                'operations' => [[
                    'operationType' => 'AUTHORIZATION',
                    'operationResult' => 'DENIED_BY_RISK',
                ]],
            ]),
        );

        self::assertSame(
            PaymentStatus::Cancelled,
            NexiStatusMapper::fromOrderPayload([
                'operations' => [[
                    'operationType' => 'AUTHORIZATION',
                    'operationResult' => 'CANCELED',
                ]],
            ]),
        );
    }
}
