<?php

declare(strict_types=1);

namespace App\Infrastructure\Currency;

use Payroad\Domain\Money\Currency;
use Symfony\Component\Intl\Currencies;

/**
 * Resolves currency precision from ISO 4217 (via symfony/intl) for fiat,
 * and from a hardcoded map for crypto currencies not covered by ISO 4217.
 *
 * This lives in the infrastructure layer — the domain Currency value object
 * remains unaware of any registry.
 */
final class KnownCurrencies
{
    /**
     * Crypto currencies not present in ISO 4217.
     * Precision = number of decimal places (minor units per major unit).
     */
    private const CRYPTO = [
        'BTC'  => 8,   // 1 BTC = 100_000_000 satoshi
        'ETH'  => 18,  // 1 ETH = 10^18 wei  ⚠ int overflow above ~9.2 ETH
        'LTC'  => 8,
        'BCH'  => 8,
        'XRP'  => 6,
        'XLM'  => 7,
        'ADA'  => 6,
        'SOL'  => 9,
        'DOT'  => 10,
        'DOGE' => 8,
        'USDT' => 6,
        'USDC' => 6,
        'DAI'  => 18,
        'BNB'  => 8,
        'MATIC' => 18,
    ];

    public static function get(string $code): Currency
    {
        return new Currency($code, self::precision($code));
    }

    public static function precision(string $code): int
    {
        if (isset(self::CRYPTO[$code])) {
            return self::CRYPTO[$code];
        }

        if (Currencies::exists($code)) {
            return Currencies::getFractionDigits($code);
        }

        throw new \InvalidArgumentException(
            "Unknown currency \"{$code}\". Add it to KnownCurrencies::CRYPTO or check the ISO 4217 code."
        );
    }

    public static function isCrypto(string $code): bool
    {
        return isset(self::CRYPTO[$code]);
    }
}
