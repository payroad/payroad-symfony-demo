<?php

declare(strict_types=1);

namespace App\Controller;

use Payroad\Application\UseCase\Crypto\InitiateCryptoAttemptCommand;
use Payroad\Application\UseCase\Crypto\InitiateCryptoAttemptUseCase;
use Payroad\Application\UseCase\Payment\CreatePaymentCommand;
use Payroad\Application\UseCase\Payment\CreatePaymentUseCase;
use App\Infrastructure\Currency\KnownCurrencies;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Application\Exception\ProviderNotFoundException;
use Payroad\Domain\Channel\Crypto\CryptoAttemptData;
use Payroad\Port\Provider\Crypto\CryptoAttemptContext;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CryptoCheckoutController extends AbstractController
{
    public function __construct(
        private readonly CreatePaymentUseCase          $createPayment,
        private readonly InitiateCryptoAttemptUseCase  $initiateCryptoAttempt,
    ) {}

    /**
     * POST /api/payments/crypto
     *
     * Body: { "amount": 1000, "currency": "USD", "customerId": "...", "network": "usdttrc20" }
     * Response: { "paymentId": "...", "walletAddress": "...", "payCurrency": "...", "payAmount": "..." }
     */
    #[Route('/api/payments/crypto', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $request->toArray();

        $currency = KnownCurrencies::get($body['currency'] ?? 'USD');
        $amount   = Money::ofMinor((int) ($body['amount'] ?? 0), $currency);
        $network  = $body['network']  ?? 'usdttrc20';
        $provider = $body['provider'] ?? 'nowpayments';

        $payment = $this->createPayment->execute(new CreatePaymentCommand(
            amount:     $amount,
            customerId: new CustomerId($body['customerId'] ?? 'guest'),
            metadata:   PaymentMetadata::fromArray($body['metadata'] ?? []),
        ));

        try {
            $attempt = $this->initiateCryptoAttempt->execute(new InitiateCryptoAttemptCommand(
                attemptId:    PaymentAttemptId::generate(),
                paymentId:    $payment->getId(),
                providerName: $provider,
                context:      new CryptoAttemptContext(network: $network),
            ));
        } catch (ProviderNotFoundException) {
            return $this->json(['error' => "Unknown crypto provider: {$provider}"], Response::HTTP_BAD_REQUEST);
        }

        /** @var CryptoAttemptData $data */
        $data = $attempt->getData();

        return $this->json([
            'paymentId'     => $payment->getId()->value,
            'attemptId'     => $attempt->getId()->value,
            'walletAddress' => $data->getWalletAddress(),
            'payCurrency'   => $data->getPayCurrency(),
            'payAmount'     => $data->getPayAmount(),
            'memo'          => $data->getMemo(),
            'paymentUrl'    => $data->getPaymentUrl(),
        ], Response::HTTP_CREATED);
    }
}
