<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use DateTimeImmutable;

final class RefundEntity
{
    public string            $id;
    public string            $paymentId;
    public string            $originalAttemptId;
    public string            $providerName;
    public int               $amountMinor;
    public string            $amountCurrencyCode;
    public int               $amountCurrencyPrecision;
    public ?string           $providerReference = null;
    public string            $status;
    public string            $providerStatus;
    public DateTimeImmutable $createdAt;
    public string            $methodType;
    public array             $data              = [];
    public int               $version           = 0;
}
