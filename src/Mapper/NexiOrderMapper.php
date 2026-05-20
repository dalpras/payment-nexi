<?php declare(strict_types=1);

namespace DalPraS\Payment\Nexi\Mapper;

use DalPraS\Payment\Dto\CancelRequest;
use DalPraS\Payment\Dto\CaptureRequest;
use DalPraS\Payment\Dto\CheckoutRequest;
use DalPraS\Payment\Dto\RefundRequest;
use DalPraS\Payment\Enum\PaymentIntent;
use DalPraS\Payment\ValueObject\Address;
use DalPraS\Payment\ValueObject\Customer;

final class NexiOrderMapper
{
    public function mapCreateHostedPaymentPayload(CheckoutRequest $request): array
    {
        $payload = [
            'order' => [
                'orderId' => $request->merchantReference,
                'amount' => (string) $request->amounts->grandTotal->minorAmount(),
                'currency' => $request->amounts->grandTotal->currency()->value,
                'customerId' => $request->customer->id,
                'description' => $request->metadata['description'] ?? $request->merchantReference,
                'customField' => $request->paymentReference,
            ],
            'customerInfo' => $this->mapCustomerInfo($request->customer),
            'billingAddress' => $this->mapBillingAddress($request->customer),
            'language' => $request->providerOptions['language'] ?? 'ita',
            'paymentService' => $request->providerOptions['payment_service'] ?? null,
            'captureType' => $request->providerOptions['capture_type'] ?? $this->mapCaptureType($request->intent),
            'resultUrl' => $request->returnUrl,
            'cancelUrl' => $request->cancelUrl,
            'notificationUrl' => $request->webhookUrl,
        ];

        if (isset($request->providerOptions['customer_info']) && is_array($request->providerOptions['customer_info'])) {
            $payload['customerInfo'] = array_replace(
                $payload['customerInfo'],
                $request->providerOptions['customer_info']
            );
        }

        if (isset($request->providerOptions['billing_address']) && is_array($request->providerOptions['billing_address'])) {
            $payload['billingAddress'] = array_replace(
                $payload['billingAddress'],
                $request->providerOptions['billing_address']
            );
        }

        if (isset($payload['billingAddress']['country']) && is_string($payload['billingAddress']['country'])) {
            $payload['billingAddress']['country'] = $this->normalizeCountryCode($payload['billingAddress']['country']);
        }

        return $this->filterRecursive($payload);
    }

    public function mapCapturePayload(CaptureRequest $request): array
    {
        return $this->filterRecursive([
            'amount' => $request->metadata['amount_minor'] ?? null,
            'currency' => $request->metadata['currency'] ?? null,
            'description' => $request->metadata['description'] ?? null,
        ]);
    }

    public function mapRefundPayload(RefundRequest $request): array
    {
        return $this->filterRecursive([
            'amount' => $request->metadata['amount_minor'] ?? null,
            'currency' => $request->metadata['currency'] ?? null,
            'description' => $request->metadata['description'] ?? null,
        ]);
    }

    public function mapCancelPayload(CancelRequest $request): array
    {
        return $this->filterRecursive([
            'amount' => $request->metadata['amount_minor'] ?? null,
            'currency' => $request->metadata['currency'] ?? null,
            'description' => $request->metadata['description'] ?? null,
        ]);
    }

    private function mapCaptureType(PaymentIntent $intent): string
    {
        return match ($intent) {
            PaymentIntent::Sale => 'IMPLICIT',
            PaymentIntent::Authorize,
            PaymentIntent::CaptureLater => 'EXPLICIT',
        };
    }

    private function mapCustomerInfo(Customer $customer): array
    {
        return $this->filterRecursive([
            'cardHolderName' => $customer->fullName,
            'cardHolderEmail' => $customer->email,
        ]);
    }

    private function mapBillingAddress(Customer $customer): array
    {
        $address = $customer->billingAddress;

        if (!$address instanceof Address) {
            return [];
        }

        return $this->filterRecursive([
            'name' => $address->fullName ?? $customer->fullName,
            'street' => $address->line1,
            'additionalInfo' => $address->line2,
            'city' => $address->city,
            'postCode' => $address->postalCode,
            'province' => $address->province,
            'country' => $this->normalizeCountryCode($address->countryCode),
            'phoneNumber' => $address->phone,
        ]);
    }

    private function normalizeCountryCode(?string $countryCode): ?string
    {
        if ($countryCode === null || trim($countryCode) === '') {
            return null;
        }

        $countryCode = strtoupper(trim($countryCode));

        return match ($countryCode) {
            'IT' => 'ITA',
            'FR' => 'FRA',
            'DE' => 'DEU',
            'ES' => 'ESP',
            'GB', 'UK' => 'GBR',
            'US' => 'USA',
            default => $countryCode,
        };
    }

    private function filterRecursive(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->filterRecursive($value);
            }

            if ($payload[$key] === [] || $payload[$key] === null || $payload[$key] === '') {
                unset($payload[$key]);
            }
        }

        return $payload;
    }
}