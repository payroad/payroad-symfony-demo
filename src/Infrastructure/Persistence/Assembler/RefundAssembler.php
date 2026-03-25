<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Assembler;

use App\Infrastructure\Persistence\Entity\RefundEntity;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Card\CardRefund;
use Payroad\Domain\PaymentFlow\Cash\CashRefund;
use Payroad\Domain\PaymentFlow\Crypto\CryptoRefund;
use Payroad\Domain\PaymentFlow\P2P\P2PRefund;
use Payroad\Domain\Refund\Refund;
use Payroad\Domain\Refund\RefundId;
use Payroad\Domain\Refund\RefundStatus;

final class RefundAssembler
{

    public function toEntity(Refund $refund): RefundEntity
    {
        $entity                          = new RefundEntity();
        $entity->id                      = $refund->getId()->value;
        $entity->paymentId               = $refund->getPaymentId()->value;
        $entity->originalAttemptId       = $refund->getOriginalAttemptId()->value;
        $entity->providerName            = $refund->getProviderName();
        $entity->amountMinor             = $refund->getAmount()->getMinorAmount();
        $entity->amountCurrencyCode      = $refund->getAmount()->getCurrency()->code;
        $entity->amountCurrencyPrecision = $refund->getAmount()->getCurrency()->precision;
        $entity->providerReference       = $refund->getProviderReference();
        $entity->status                  = $refund->getStatus()->value;
        $entity->providerStatus          = $refund->getProviderStatus();
        $entity->createdAt               = $refund->getCreatedAt();
        $entity->methodType              = $refund->getMethodType()->value;
        $refundData                      = $refund->getData();
        $entity->data                    = $refundData !== null
            ? ['_class' => $refundData::class, ...$refundData->toArray()]
            : [];
        $entity->version                 = $refund->getVersion();

        return $entity;
    }

    public function toDomain(RefundEntity $entity): Refund
    {
        $currency          = new Currency($entity->amountCurrencyCode, $entity->amountCurrencyPrecision);
        $amount            = Money::ofMinor($entity->amountMinor, $currency);
        $id                = RefundId::fromUuid($entity->id);
        $paymentId         = PaymentId::fromUuid($entity->paymentId);
        $originalAttemptId = PaymentAttemptId::fromUuid($entity->originalAttemptId);
        $status            = RefundStatus::from($entity->status);
        $data = null;
        if (isset($entity->data['_class'])) {
            $class = $entity->data['_class'];
            $data  = $class::fromArray(array_diff_key($entity->data, ['_class' => true]));
        }

        $refund = match ($entity->methodType) {
            'card'   => new CardRefund($id, $paymentId, $originalAttemptId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            'crypto' => new CryptoRefund($id, $paymentId, $originalAttemptId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            'p2p'    => new P2PRefund($id, $paymentId, $originalAttemptId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            'cash'   => new CashRefund($id, $paymentId, $originalAttemptId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            default  => throw new \RuntimeException("Unknown refund method type: '{$entity->methodType}'."),
        };

        $refund->setVersion($entity->version);

        return $refund;
    }
}
