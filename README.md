# payroad-symfony-demo

Reference Symfony application demonstrating all payment flows of the [Payroad](https://github.com/payroad/payroad-core) platform.

## Included providers

| Provider | Flow | Package |
|----------|------|---------|
| Stripe | Card (one-step, Stripe.js) | `payroad/stripe-provider` |
| Braintree | Card (two-step, Drop-in UI) | `payroad/braintree-provider` |
| NOWPayments | Crypto | `payroad/nowpayments-provider` |
| CoinGate | Crypto | `payroad/coingate-provider` |
| Internal Cash | Cash (manual) | `payroad/internal-cash-provider` |

## Requirements

- Docker + Docker Compose

## Getting started

```bash
cp .env .env.local
# fill in your API keys in .env.local

docker compose up -d
docker compose exec app composer install
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app npm install
docker compose exec app npm run build
```

Open http://localhost in your browser.

## Checkout

`/checkout` — multi-tab checkout form:

- **Card** — Stripe.js or Braintree Drop-in depending on selected provider
- **Crypto** — deposit address (NOWPayments) or redirect (CoinGate)
- **Cash** — deposit code + cashier confirmation polling

## Dashboard

`/dashboard` — payment list with:

- Status badges
- Detail modal (attempts, refunds)
- Refund and cancel actions
- Cash confirmation button for `awaiting_confirmation` attempts

## Environment variables

| Variable | Description |
|----------|-------------|
| `STRIPE_PUBLISHABLE_KEY` | Stripe publishable key |
| `STRIPE_SECRET_KEY` | Stripe secret key |
| `STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret |
| `BRAINTREE_ENVIRONMENT` | `sandbox` or `production` |
| `BRAINTREE_MERCHANT_ID` | Braintree merchant ID |
| `BRAINTREE_PUBLIC_KEY` | Braintree public key |
| `BRAINTREE_PRIVATE_KEY` | Braintree private key |
| `NOWPAYMENTS_API_KEY` | NOWPayments API key |
| `NOWPAYMENTS_IPN_SECRET` | NOWPayments IPN secret |
| `NOWPAYMENTS_IPN_CALLBACK_URL` | Public URL for NOWPayments webhooks |
| `COINGATE_API_KEY` | CoinGate API key |
| `COINGATE_IPN_CALLBACK_URL` | Public URL for CoinGate webhooks |

For local webhook testing use [ngrok](https://ngrok.com) or similar.

## Architecture

See [payroad-core](https://github.com/payroad/payroad-core) for domain architecture details.

```
payroad-symfony-demo
├── src/Controller/          # HTTP layer (one controller per flow)
├── src/Infrastructure/
│   ├── Persistence/         # Doctrine ORM entities, assemblers, repositories
│   ├── Query/               # Read-side query services (PaymentListQuery, PaymentDetailQuery)
│   ├── Event/               # Symfony Messenger domain event dispatcher
│   └── Currency/            # KnownCurrencies precision lookup
├── config/packages/
│   └── payroad.yaml         # Provider registration
└── templates/               # Twig templates (Tailwind CSS)
```
