<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repository;

use App\Infrastructure\Persistence\Assembler\SavedPaymentMethodAssembler;
use App\Infrastructure\Persistence\Entity\SavedPaymentMethodEntity;
use Doctrine\ORM\EntityManagerInterface;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethod;
use Payroad\Domain\SavedPaymentMethod\SavedPaymentMethodId;
use Payroad\Port\Repository\SavedPaymentMethodRepositoryInterface;

final class DoctrineSavedPaymentMethodRepository implements SavedPaymentMethodRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface        $em,
        private readonly SavedPaymentMethodAssembler   $assembler,
    ) {}

    public function nextId(): SavedPaymentMethodId
    {
        return SavedPaymentMethodId::generate();
    }

    public function save(SavedPaymentMethod $method): void
    {
        $entity   = $this->assembler->toEntity($method);
        $existing = $this->em->find(SavedPaymentMethodEntity::class, $entity->id);

        if ($existing === null) {
            $this->em->persist($entity);
        } else {
            if ($existing->version !== $method->getVersion()) {
                throw new \RuntimeException(
                    "Optimistic lock conflict for SavedPaymentMethod '{$entity->id}'."
                );
            }
            $existing->status        = $entity->status;
            $existing->providerToken = $entity->providerToken;
            $existing->data          = $entity->data;
            $existing->version       = $entity->version + 1;
        }

        $this->em->flush();
        $method->incrementVersion();
    }

    public function findById(SavedPaymentMethodId $id): ?SavedPaymentMethod
    {
        $entity = $this->em->find(SavedPaymentMethodEntity::class, $id->value);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }

    public function findByCustomerId(CustomerId $customerId): array
    {
        $entities = $this->em->getRepository(SavedPaymentMethodEntity::class)->findBy([
            'customerId' => $customerId->value,
        ]);
        return array_map($this->assembler->toDomain(...), $entities);
    }

    public function findByProviderToken(string $providerName, string $token): ?SavedPaymentMethod
    {
        $entity = $this->em->getRepository(SavedPaymentMethodEntity::class)->findOneBy([
            'providerName'  => $providerName,
            'providerToken' => $token,
        ]);
        return $entity !== null ? $this->assembler->toDomain($entity) : null;
    }
}
