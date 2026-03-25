<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Assembler;

use App\Infrastructure\Persistence\Entity\PaymentEntity;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\Payment;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Domain\Payment\PaymentStatus;

final class PaymentAssembler
{
    public function toEntity(Payment $payment): PaymentEntity
    {
        $entity                          = new PaymentEntity();
        $entity->id                      = $payment->getId()->value;
        $entity->amountMinor             = $payment->getAmount()->getMinorAmount();
        $entity->amountCurrencyCode      = $payment->getAmount()->getCurrency()->code;
        $entity->amountCurrencyPrecision = $payment->getAmount()->getCurrency()->precision;
        $entity->customerId              = $payment->getCustomerId()->value;
        $entity->status                  = $payment->getStatus()->value;
        $entity->successfulAttemptId     = $payment->getSuccessfulAttemptId()?->value;
        $entity->metadata                = $payment->getMetadata()->toArray();
        $entity->createdAt               = $payment->getCreatedAt();
        $entity->expiresAt               = $payment->getExpiresAt();
        $entity->refundedAmountMinor     = $payment->getRefundedAmount()->getMinorAmount();
        $entity->version                 = $payment->getVersion();

        return $entity;
    }

    public function toDomain(PaymentEntity $entity): Payment
    {
        $currency = new Currency($entity->amountCurrencyCode, $entity->amountCurrencyPrecision);

        $payment = new Payment(
            id:                  PaymentId::fromUuid($entity->id),
            amount:              Money::ofMinor($entity->amountMinor, $currency),
            customerId:          new CustomerId($entity->customerId),
            metadata:            PaymentMetadata::fromArray($entity->metadata),
            expiresAt:           $entity->expiresAt,
            status:              PaymentStatus::from($entity->status),
            successfulAttemptId: $entity->successfulAttemptId !== null
                                     ? PaymentAttemptId::fromUuid($entity->successfulAttemptId)
                                     : null,
            createdAt:           $entity->createdAt,
            refundedAmount:      Money::ofMinor($entity->refundedAmountMinor, $currency),
        );

        $payment->setVersion($entity->version);

        return $payment;
    }
}
