<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\Currency\KnownCurrencies;
use App\Infrastructure\Query\PaymentDetailQuery;
use Payroad\Application\Exception\PaymentNotFoundException;
use Payroad\Application\Exception\PaymentNotRefundableException;
use Payroad\Application\Exception\ProviderNotFoundException;
use Payroad\Application\Exception\RefundExceedsPaymentAmountException;
use Payroad\Application\UseCase\Cash\InitiateCashAttemptCommand;
use Payroad\Application\UseCase\Cash\InitiateCashAttemptUseCase;
use Payroad\Application\UseCase\Cash\InitiateCashRefundCommand;
use Payroad\Application\UseCase\Cash\InitiateCashRefundUseCase;
use Payroad\Application\UseCase\Payment\CreatePaymentCommand;
use Payroad\Application\UseCase\Payment\CreatePaymentUseCase;
use Payroad\Application\UseCase\Webhook\HandleWebhookCommand;
use Payroad\Application\UseCase\Webhook\HandleWebhookUseCase;
use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\CustomerId;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\Payment\PaymentMetadata;
use Payroad\Domain\PaymentFlow\Cash\CashAttemptData;
use Payroad\Port\Provider\Cash\CashAttemptContext;
use Payroad\Port\Provider\WebhookResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CashCheckoutController extends AbstractController
{
    public function __construct(
        private readonly CreatePaymentUseCase         $createPayment,
        private readonly InitiateCashAttemptUseCase   $initiateCashAttempt,
        private readonly InitiateCashRefundUseCase    $initiateRefund,
        private readonly HandleWebhookUseCase         $handleWebhook,
        private readonly PaymentDetailQuery           $detailQuery,
    ) {}

    /**
     * POST /api/payments/cash
     *
     * Creates a payment and initiates a cash attempt (AWAITING_CONFIRMATION).
     * Body: { "amount": 1000, "currency": "USD", "customerId": "..." }
     */
    #[Route('/api/payments/cash', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $request->toArray();

        $currency = KnownCurrencies::get($body['currency'] ?? 'USD');
        $amount   = Money::ofMinor((int) ($body['amount'] ?? 0), $currency);

        $payment = $this->createPayment->execute(new CreatePaymentCommand(
            amount:     $amount,
            customerId: new CustomerId($body['customerId'] ?? 'guest'),
            metadata:   PaymentMetadata::fromArray($body['metadata'] ?? []),
        ));

        try {
            $attempt = $this->initiateCashAttempt->execute(new InitiateCashAttemptCommand(
                paymentId:    $payment->getId(),
                providerName: 'internal_cash',
                context:      new CashAttemptContext(customerPhone: $body['customerPhone'] ?? ''),
            ));
        } catch (ProviderNotFoundException) {
            return $this->json(['error' => 'Cash provider not configured'], Response::HTTP_BAD_REQUEST);
        }

        /** @var CashAttemptData $data */
        $data = $attempt->getData();

        return $this->json([
            'paymentId'       => $payment->getId()->value,
            'attemptId'       => $attempt->getId()->value,
            'depositCode'     => $data->getDepositCode(),
            'depositLocation' => $data->getDepositLocation(),
            'amount'          => $payment->getAmount()->getMinorAmount(),
            'currency'        => $payment->getAmount()->getCurrency()->code,
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/payments/{id}/confirm-cash
     *
     * Cashier confirms that cash was physically received.
     * Body: { "attemptId": "..." }
     */
    #[Route('/api/payments/{id}/confirm-cash', methods: ['POST'])]
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

        $this->handleWebhook->execute(new HandleWebhookCommand(
            'internal_cash',
            new WebhookResult(
                providerReference: $attempt['providerReference'],
                newStatus:         AttemptStatus::SUCCEEDED,
                providerStatus:    'cash_received',
                statusChanged:     true,
            ),
        ));

        return $this->json(['paymentId' => $id, 'status' => 'succeeded'], Response::HTTP_OK);
    }

    /**
     * POST /api/payments/cash/refunds
     *
     * Cashier hands cash back to the customer.
     * Body: { "paymentId": "uuid", "amount": 500 }
     */
    #[Route('/api/payments/cash/refunds', methods: ['POST'])]
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

        $currency = new \Payroad\Domain\Money\Currency($payment['currency'], $payment['currencyPrecision']);

        try {
            $refund = $this->initiateRefund->execute(new InitiateCashRefundCommand(
                paymentId: PaymentId::fromUuid($paymentId),
                amount:    Money::ofMinor($amount, $currency),
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
