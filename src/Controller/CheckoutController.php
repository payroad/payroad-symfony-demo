<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CheckoutController extends AbstractController
{
    #[Route('/checkout', methods: ['GET'])]
    public function form(): Response
    {
        return $this->render('checkout/form.html.twig', [
            'stripe_publishable_key' => $this->getParameter('stripe.publishable_key'),
        ]);
    }

    #[Route('/checkout/crypto', methods: ['GET'])]
    public function cryptoRedirect(): Response
    {
        return $this->redirectToRoute('app_checkout_form', ['method' => 'crypto']);
    }

    #[Route('/checkout/return', methods: ['GET'])]
    public function return(): Response
    {
        return $this->render('checkout/return.html.twig');
    }
}
