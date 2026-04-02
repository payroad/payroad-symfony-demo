<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Currency\KnownCurrencies;
use App\Infrastructure\Query\PaymentDetailQuery;
use Payroad\Application\Exception\PaymentNotFoundException;
use Payroad\Application\Exception\PaymentNotRefundableException;
use Payroad\Application\Exception\RefundExceedsPaymentAmountException;
use Payroad\Application\UseCase\P2P\InitiateP2PAttemptCommand;
use Payroad\Application\UseCase\P2P\InitiateP2PAttemptUseCase;
use Payroad\Application\UseCase\P2P\InitiateP2PRefundCommand;
use Payroad\Application\UseCase\P2P\InitiateP2PRefundUseCase;
use Payroad\Application\UseCase\Payment\CreatePaymentCommand;
use Payroad\Application\UseCase\Payment\CreatePaymentUseCase;
use Payroad\Application\UseCase\Webhook\HandleWebhookCommand;
use Payroad\Application\UseCase\Webhook\HandleWebhookUseCase;
use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Port\Provider\P2P\P2PAttemptContext;
use Payroad\Port\Provider\P2P\P2PRefundContext;
use Payroad\Port\Provider\WebhookResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class P2PCheckoutController extends AbstractController
{
    public function __construct(
        private readonly CreatePaymentUseCase      $createPayment,
        private readonly InitiateP2PAttemptUseCase $initiateAttempt,
        private readonly InitiateP2PRefundUseCase  $initiateRefund,
        private readonly HandleWebhookUseCase      $handleWebhook,
        private readonly PaymentDetailQuery        $detailQuery,
    ) {}

    /**
     * POST /api/payments/p2p
     *
     * Creates a payment and initiates a P2P attempt (AWAITING_CONFIRMATION).
     * Body: { "amount": 1000, "currency": "USD", "customerId": "...", "providerName": "internal_p2p" }
     */
    #[Route('/api/payments/p2p', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body         = $request->toArray();
        $providerName = (string) ($body['providerName'] ?? 'internal_p2p');

        $currency = KnownCurrencies::get($body['currency'] ?? 'USD');
        $amount   = Money::ofMinor((int) ($body['amount'] ?? 0), $currency);

        $payment = $this->createPayment->execute(new CreatePaymentCommand(
            amount:     $amount,
            customerId: new CustomerId($body['customerId'] ?? 'guest'),
            metadata:   PaymentMetadata::fromArray($body['metadata'] ?? []),
        ));

        $attempt = $this->initiateAttempt->execute(new InitiateP2PAttemptCommand(
            paymentId:    $payment->getId(),
            providerName: $providerName,
            context:      new P2PAttemptContext(
                customerName: $body['customerName'] ?? 'Guest',
            ),
        ));

        /** @var \Payroad\Domain\Channel\P2P\P2PAttemptData $data */
        $data = $attempt->getData();

        return $this->json([
            'paymentId'         => $payment->getId()->value,
            'attemptId'         => $attempt->getId()->value,
            'transferReference' => $data->getTransferReference(),
            'recipientAccount'  => $data->getTransferTarget(),
            'recipientBank'     => $data->getRecipientBankName(),
            'recipientHolder'   => $data->getRecipientHolderName(),
            'amount'            => $payment->getAmount()->getMinorAmount(),
            'currency'          => $payment->getAmount()->getCurrency()->code,
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/payments/{id}/confirm-p2p
     *
     * Simulates the merchant confirming that a bank transfer was received.
     * Body: { "attemptId": "..." }
     */
    #[Route('/api/payments/{id}/confirm-p2p', methods: ['POST'])]
    public function confirm(string $id, Request $request): JsonResponse
    {
        $body      = $request->toArray();
        $attemptId = (string) ($body['attemptId'] ?? '');

        if ($attemptId === '') {
            return $this->json(['error' => 'attemptId is required'], Response::HTTP_BAD_REQUEST);
        }

        $payment = $this->detailQuery->find($id);
        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        }

        $attempt = null;
        foreach ($payment['attempts'] as $a) {
            if ($a['id'] === $attemptId) {
                $attempt = $a;
                break;
            }
        }

        if ($attempt === null) {
            return $this->json(['error' => 'Attempt not found'], Response::HTTP_NOT_FOUND);
        }

        if ($attempt['status'] !== 'awaiting_confirmation') {
            return $this->json(
                ['error' => "Cannot confirm: attempt is in status \"{$attempt['status']}\"."],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // P2P state machine: awaiting_confirmation → processing → succeeded
        $this->handleWebhook->execute(new HandleWebhookCommand(
            'internal_p2p',
            new WebhookResult(
                providerReference: $attempt['providerReference'],
                newStatus:         AttemptStatus::PROCESSING,
                providerStatus:    'transfer_received',
                statusChanged:     true,
            ),
        ));

        $this->handleWebhook->execute(new HandleWebhookCommand(
            'internal_p2p',
            new WebhookResult(
                providerReference: $attempt['providerReference'],
                newStatus:         AttemptStatus::SUCCEEDED,
                providerStatus:    'transfer_confirmed',
                statusChanged:     true,
            ),
        ));

        return $this->json(['paymentId' => $id, 'status' => 'succeeded'], Response::HTTP_OK);
    }

    /**
     * POST /api/payments/p2p/refunds
     *
     * Body: { "paymentId": "uuid", "amount": 500 }
     */
    #[Route('/api/payments/p2p/refunds', methods: ['POST'])]
    public function refund(Request $request): JsonResponse
    {
        $body      = $request->toArray();
        $paymentId = (string) ($body['paymentId'] ?? '');
        $amount    = (int) ($body['amount'] ?? 0);

        if ($paymentId === '' || $amount <= 0) {
            return $this->json(
                ['error' => 'paymentId and a positive amount are required'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $payment = $this->detailQuery->find($paymentId);
        if ($payment === null) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        }

        $currency = new Currency($payment['currency'], $payment['currencyPrecision']);

        try {
            $refund = $this->initiateRefund->execute(new InitiateP2PRefundCommand(
                paymentId: PaymentId::fromUuid($paymentId),
                amount:    Money::ofMinor($amount, $currency),
                context:   new P2PRefundContext($body['reason'] ?? null),
            ));
        } catch (PaymentNotFoundException) {
            return $this->json(['error' => 'Payment not found'], Response::HTTP_NOT_FOUND);
        } catch (PaymentNotRefundableException | RefundExceedsPaymentAmountException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException | \RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'refundId' => $refund->getId()->value,
            'status'   => $refund->getStatus()->value,
            'amount'   => $amount,
            'currency' => $payment['currency'],
        ], Response::HTTP_CREATED);
    }
}
