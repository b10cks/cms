---
description: "Operate billing on your own instance: LemonSqueezy setup, the public plan lineup, custom agency plans, and per-space quota overrides."
---

# Plans & pricing

Billing is **optional**. On a single-team instance you can skip this page entirely — spaces work without a subscription provider, and nothing is metered or gated. Configure it when you run b10cks as a platform: for clients, for an agency, or as a product.

How plans, quotas, and the subscription lifecycle *behave* for the people using a space is described in the user guide under [Subscription & Billing](../ui/subscription.md). This page covers the operator side: setting billing up and shaping the plan lineup.

## LemonSqueezy setup

Payments are handled by [LemonSqueezy](https://www.lemonsqueezy.com/). Connect your store:

```bash
LEMONSQUEEZY_API_KEY=…
LEMONSQUEEZY_STORE_ID=…
LEMONSQUEEZY_WEBHOOK_SECRET=…
```

Then point a LemonSqueezy webhook at your instance:

```
POST https://cms.example.com/webhooks/lemonsqueezy
```

subscribed to the subscription events, signed with the webhook secret. As a safety net, the scheduler also runs an hourly `subscriptions:sync-lemonsqueezy` reconciliation, so a missed webhook heals itself — another reason the [cron scheduler](installation.md#processes) is mandatory in production.

## The public plan lineup

The default public plans are bootstrapped with:

```bash
php artisan plans:setup          # idempotent — creates missing plans, leaves existing ones alone
php artisan plans:setup --list   # show the current lineup
php artisan plans:setup --link   # interactively connect plans to LemonSqueezy product/variant IDs
php artisan plans:setup --force  # overwrite with defaults
```

A plan carries monthly **soft quotas** — `traffic` (delivery + asset egress, bytes/month), `storage` (asset footprint, bytes) and `aiCredit` (AI spend cap, USD/month). Nothing is hard-blocked when quotas are exceeded; users with billing visibility are notified at 80% and 100%. The one inherently enforced limit is `aiCredit`, which caps the space's AI key. API requests are never metered.

Plans without a linked LemonSqueezy variant can't be purchased; a plan with price `0` needs no variant and activates directly (that's how the Free plan works). Plans may also carry a discounted `yearly_price` with its own yearly variant.

## Custom plans

Besides the public lineup you can create **custom plans** — agency or subsidized deals with their own pricing and quotas. Custom plans never appear in the public plan list; they are visible only in the plan dialog of spaces they have been granted to.

### Creating a custom plan

```bash
php artisan plans:custom create \
  --name="Agency Deal" \
  --price=59.00 \
  --yearly-price=590.00 \
  --quotas='{"traffic":536870912000,"storage":53687091200,"aiCredit":25}'
```

Notes on the fields:

- **Quotas** are a JSON object with the keys `traffic` (bytes/month), `storage` (bytes), and `aiCredit` (USD spend cap/month). Omitted keys are unlimited; `--quotas` omitted entirely means an unlimited plan.
- **Pricing** is display-only until the plan is linked to LemonSqueezy. For a paid custom plan, create a product/variant in LemonSqueezy first and pass `--product-id` and `--variant-id` (plus `--yearly-variant-id` if a yearly option should exist). Without a variant, checkout reports the plan as not purchasable.
- A plan created with price `0` and no variant becomes a **free custom plan** — useful for sponsored spaces.
- Custom plans are always created non-public (`is_public = false`) and sort after the public plans.

### Granting it to a space

```bash
php artisan plans:custom grant --plan=<plan-id> --space=<space-id-or-slug>
php artisan plans:custom list                # all custom plans + their grants
php artisan plans:custom revoke --plan=<plan-id> --space=<space-id-or-slug>
```

Once granted, the plan shows up in that space's plan dialog and in the [payment request](../ui/agency-billing.md) picker like any other plan; checkout validates the grant, so an ungranted space can never buy a custom plan — even with the plan ID.

### Per-space quota overrides

For one-off deals that don't warrant their own plan, a **quota override** can be written onto a space's current subscription. Overridden keys replace the plan's quotas at read time; everything else keeps following the plan:

```bash
php artisan plans:custom override --space=<space> --quotas='{"aiCredit":100}'
php artisan plans:custom override --space=<space> --quotas=null   # back to plan defaults
```

The override survives billing renewals but is dropped automatically when the subscription switches to a different plan.

## The agency flow

Custom plans combine with **payment requests**: an agency builds a space, you grant it the negotiated plan, and the agency invites the client-side billing contact to complete the checkout — making the client the billing owner. The whole flow is user-facing and documented in [Agency Billing](../ui/agency-billing.md).
