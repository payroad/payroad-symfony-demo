<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Assembler\PaymentAttemptAssembler;
use App\Infrastructure\Persistence\Entity\PaymentAttemptEntity;
use Doctrine\ORM\EntityManagerInterface;
use Payroad\Domain\Attempt\PaymentAttempt;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Port\Repository\PaymentAttemptRepositoryInterface;

final class DoctrinePaymentAttemptRepository implements PaymentAttemptRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly PaymentAttemptAssembler $assembler,
    ) {}

    public function nextId(): PaymentAttemptId
    {
        return PaymentAttemptId::generate();
    }

    public function save(PaymentAttempt $attempt): void
    {
        $entity   = $this->assembler->toEntity($attempt);
        $existing = $this->em->find(PaymentAttemptEntity::class, $entity->id);

        if ($existing === null) {
            $this->em->persist($entity);
        } else {
            if ($existing->version !== $attempt->getVersion()) {
                throw new \RuntimeException(
                    "Optimistic lock conflict for PaymentAttempt '{$entity->id}'."
                );
            }
            $existing->providerReference       = $entity->providerReference;
            $existing->status                  = $entity->status;
            $existing->providerStatus          = $entity->providerStatus;
            $existing->data                    = $entity->data;
            $existing->version                 = $entity->version + 1;
        }

        $this->em->flush();
        $attempt->incrementVersion();
    }

    public function findById(PaymentAttemptId $id): ?PaymentAttempt
    {
        $entity = $this->em->find(PaymentAttemptEntity::class, $id->value);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }

    public function findByProviderReference(string $providerName, string $reference): ?PaymentAttempt
    {
        $entity = $this->em->getRepository(PaymentAttemptEntity::class)->findOneBy([
            'providerName'      => $providerName,
            'providerReference' => $reference,
        ]);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }

    public function findByPaymentId(PaymentId $paymentId): array
    {
        $entities = $this->em->getRepository(PaymentAttemptEntity::class)->findBy([
            'paymentId' => $paymentId->value,
        ]);
        return array_map($this->assembler->toDomain(...), $entities);
    }
}
