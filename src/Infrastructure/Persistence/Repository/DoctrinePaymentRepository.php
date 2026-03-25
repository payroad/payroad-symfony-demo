<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Assembler\PaymentAssembler;
use App\Infrastructure\Persistence\Entity\PaymentEntity;
use Doctrine\ORM\EntityManagerInterface;
use Payroad\Domain\Payment\Payment;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Port\Repository\PaymentRepositoryInterface;

final class DoctrinePaymentRepository implements PaymentRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentAssembler       $assembler,
    ) {}

    public function nextId(): PaymentId
    {
        return PaymentId::generate();
    }

    public function save(Payment $payment): void
    {
        $entity   = $this->assembler->toEntity($payment);
        $existing = $this->em->find(PaymentEntity::class, $entity->id);

        if ($existing === null) {
            $this->em->persist($entity);
        } else {
            if ($existing->version !== $payment->getVersion()) {
                throw new \RuntimeException(
                    "Optimistic lock conflict for Payment '{$entity->id}': "
                    . "expected version {$payment->getVersion()}, got {$existing->version}."
                );
            }
            // Merge scalar fields into the managed entity.
            $existing->amountMinor             = $entity->amountMinor;
            $existing->amountCurrencyCode      = $entity->amountCurrencyCode;
            $existing->amountCurrencyPrecision = $entity->amountCurrencyPrecision;
            $existing->customerId              = $entity->customerId;
            $existing->status                  = $entity->status;
            $existing->successfulAttemptId     = $entity->successfulAttemptId;
            $existing->metadata                = $entity->metadata;
            $existing->createdAt               = $entity->createdAt;
            $existing->expiresAt               = $entity->expiresAt;
            $existing->refundedAmountMinor     = $entity->refundedAmountMinor;
            $existing->version                 = $entity->version + 1;
            $entity = $existing;
        }

        $this->em->flush();
        $payment->incrementVersion();
    }

    public function findById(PaymentId $id): ?Payment
    {
        $entity = $this->em->find(PaymentEntity::class, $id->value);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }
}
