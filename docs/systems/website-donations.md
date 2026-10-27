# Website crystal donations

The Chronicles website sells fixed Sacred Relic Crystal packages through Stripe Checkout. Prices and crystal amounts
are authoritative in both the PHP presentation layer and `DonationStore`; the game server rejects a browser-supplied
account, character, package, amount, or currency that does not match its prepared order.

## Flow

1. An authenticated player selects one of their characters and a package on `panel.php`.
2. The PHP backend asks the loopback-only accounts API to prepare a `website_donation` row.
3. PHP creates a one-time BRL Checkout Session containing only the server-generated order id and package id.
4. PHP attaches the Stripe Session id to the prepared row before redirecting the player.
5. Stripe signs `checkout.session.completed` and sends it to `/api/stripe-webhook.php`.
6. The webhook accepts only `payment_status=paid`. The game server then checks the session, order, package, amount and
   currency and atomically marks the order paid while appending an idempotent in-game letter.
7. The selected character claims unbound Sacred Relic Crystals (ItemConfig 3) from in-game mail.

The Stripe Session id is unique and the mail delivery key is derived from it. Replayed events therefore cannot credit
the same payment twice. Website endpoints are loopback-only on the game server; public clients interact only with PHP,
which also requires the existing authenticated session and CSRF token when Checkout is created.

## Configuration

Apache supplies `CHRONICLES_STRIPE_SECRET_KEY` and `CHRONICLES_STRIPE_WEBHOOK_SECRET`. They must never be placed in the
repository or web root. The webhook is a separate Stripe endpoint from other games because Stripe signing secrets are
endpoint-specific.

Refunds and disputes do not remove already delivered currency automatically. They require an operator review so a
refund cannot produce a negative balance after the player has spent the crystals.
