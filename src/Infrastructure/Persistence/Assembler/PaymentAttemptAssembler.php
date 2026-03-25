<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Assembler;

use App\Infrastructure\Persistence\Entity\PaymentAttemptEntity;
use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttempt;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Card\CardPaymentAttempt;
use Payroad\Domain\PaymentFlow\Cash\CashPaymentAttempt;
use Payroad\Domain\PaymentFlow\Crypto\CryptoPaymentAttempt;
use Payroad\Domain\PaymentFlow\P2P\P2PPaymentAttempt;

final class PaymentAttemptAssembler
{
    public function toEntity(PaymentAttempt $attempt): PaymentAttemptEntity
    {
        $entity                          = new PaymentAttemptEntity();
        $entity->id                      = $attempt->getId()->value;
        $entity->paymentId               = $attempt->getPaymentId()->value;
        $entity->providerName            = $attempt->getProviderName();
        $entity->amountMinor             = $attempt->getAmount()->getMinorAmount();
        $entity->amountCurrencyCode      = $attempt->getAmount()->getCurrency()->code;
        $entity->amountCurrencyPrecision = $attempt->getAmount()->getCurrency()->precision;
        $entity->providerReference       = $attempt->getProviderReference();
        $entity->status                  = $attempt->getStatus()->value;
        $entity->providerStatus          = $attempt->getProviderStatus();
        $entity->createdAt               = $attempt->getCreatedAt();
        $entity->methodType              = $attempt->getMethodType()->value;
        $entity->data                    = ['_class' => $attempt->getData()::class, ...$attempt->getData()->toArray()];
        $entity->version                 = $attempt->getVersion();

        return $entity;
    }

    public function toDomain(PaymentAttemptEntity $entity): PaymentAttempt
    {
        $currency = new Currency($entity->amountCurrencyCode, $entity->amountCurrencyPrecision);
        $amount   = Money::ofMinor($entity->amountMinor, $currency);
        $id       = PaymentAttemptId::fromUuid($entity->id);
        $paymentId = PaymentId::fromUuid($entity->paymentId);
        $status   = AttemptStatus::from($entity->status);
        $class    = $entity->data['_class'] ?? throw new \RuntimeException('Missing _class in attempt data.');
        $data     = $class::fromArray(array_diff_key($entity->data, ['_class' => true]));

        $attempt = match ($entity->methodType) {
            'card'   => new CardPaymentAttempt($id, $paymentId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            'crypto' => new CryptoPaymentAttempt($id, $paymentId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            'p2p'    => new P2PPaymentAttempt($id, $paymentId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            'cash'   => new CashPaymentAttempt($id, $paymentId, $entity->providerName, $amount, $data,
                            $status, $entity->providerStatus, $entity->providerReference, $entity->createdAt),
            default  => throw new \RuntimeException("Unknown attempt method type: '{$entity->methodType}'."),
        };

        $attempt->setVersion($entity->version);

        return $attempt;
    }
}
