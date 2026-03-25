<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Assembler\RefundAssembler;
use App\Infrastructure\Persistence\Entity\RefundEntity;
use Doctrine\ORM\EntityManagerInterface;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Refund\Refund;
use Payroad\Domain\Refund\RefundId;
use Payroad\Port\Repository\RefundRepositoryInterface;

final class DoctrineRefundRepository implements RefundRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RefundAssembler        $assembler,
    ) {}

    public function nextId(): RefundId
    {
        return RefundId::generate();
    }

    public function save(Refund $refund): void
    {
        $entity   = $this->assembler->toEntity($refund);
        $existing = $this->em->find(RefundEntity::class, $entity->id);

        if ($existing === null) {
            $this->em->persist($entity);
        } else {
            if ($existing->version !== $refund->getVersion()) {
                throw new \RuntimeException(
                    "Optimistic lock conflict for Refund '{$entity->id}'."
                );
            }
            $existing->providerReference = $entity->providerReference;
            $existing->status            = $entity->status;
            $existing->providerStatus    = $entity->providerStatus;
            $existing->data              = $entity->data;
            $existing->version           = $entity->version + 1;
        }

        $this->em->flush();
        $refund->incrementVersion();
    }

    public function findById(RefundId $id): ?Refund
    {
        $entity = $this->em->find(RefundEntity::class, $id->value);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }

    public function findByProviderReference(string $providerName, string $reference): ?Refund
    {
        $entity = $this->em->getRepository(RefundEntity::class)->findOneBy([
            'providerName'      => $providerName,
            'providerReference' => $reference,
        ]);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }

    public function findByPaymentId(PaymentId $paymentId): array
    {
        $entities = $this->em->getRepository(RefundEntity::class)->findBy([
            'paymentId' => $paymentId->value,
        ]);
        return array_map($this->assembler->toDomain(...), $entities);
    }
}
