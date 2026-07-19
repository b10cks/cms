---
description: "How plans, quotas, billing intervals, and the subscription lifecycle work — and where to see usage, history, and invoices."
---

# Subscription & Billing

Every space has its own subscription. The plan determines the space's monthly allowances; payment is handled by our billing partner LemonSqueezy. Everything described here lives under **Settings → Subscription** and **Settings → Usage** and requires billing permissions (see [Roles](#roles--permissions)).

## Plans & quotas

A plan carries a set of **soft quotas** — monthly allowances that are monitored but never hard-enforced:

| Quota | Meaning |
| --- | --- |
| **Traffic** | Delivery and asset egress per calendar month, including asset package downloads. |
| **Storage** | Total asset storage footprint (point-in-time, not monthly). |
| **AI credit** | The monthly AI spend cap in USD for the space's AI key. This is the one limit that is inherently enforced — AI requests stop when the credit is used up. |

**API requests are unlimited on every plan.** Request counts still show up in the space dashboard as analytics, but they are not metered or billed.

Quotas are *soft*: nothing gets blocked when you exceed traffic or storage. Instead, everyone with billing visibility is notified when a metric crosses **80%** and **100%** of its allowance (at most once per threshold per month). Sustained overuse may require moving to a plan that fits.

Allowances reset with the calendar month, regardless of your billing interval.

## Billing intervals

Plans are priced monthly; many also offer a **yearly** price at a discount. Pick the interval in the plan dialog when subscribing. Quota allowances stay monthly either way — a yearly subscription doesn't grant a year's worth of traffic up front.

## The subscription lifecycle

| Status | Meaning |
| --- | --- |
| **Active / On trial** | The plan's entitlements apply. |
| **Pending** | A checkout was started but not completed. Use **Retry payment** to resume it. |
| **Past due** | A renewal payment failed and is being retried (dunning). Your access continues; update your payment method via **Manage billing**. |
| **Cancelled** | You cancelled, but access continues until the end of the paid period. You can **Resume** any time before it ends. |
| **Unpaid / Expired** | The subscription lapsed. The space automatically falls back to the Free plan so it keeps working with Free-tier allowances. |

Cancelling never cuts you off mid-period: the plan stays fully active until the period you paid for ends, then the space moves to the Free plan automatically.

## Usage & history

**Settings → Usage** shows:

- **Live usage** against the current plan's quotas.
- A **traffic trend chart** for the selected billing period.
- **Billing period history** — one entry per period, with the usage that accrued in it. Periods close on renewal, plan change, or cancellation.
- **Invoices**, fetched from LemonSqueezy, with download links. The **Manage billing** button opens the LemonSqueezy customer portal for payment methods and receipts.

## Roles & permissions

Two space abilities control access: `space.billing.view` (see plan, usage, invoices — also who receives usage alerts) and `space.billing.manage` (subscribe, change plan, cancel, request payments).

- **Owner** and **Admin** have both.
- The dedicated **Billing** role has *only* these two — it can see and manage billing but nothing else in the space. It exists so a finance contact (for example on the client side of an [agency setup](agency-billing.md)) can own payment without any content access.

## Custom plans & pricing

Besides the public plans, operators can create **custom plans** — agency or subsidized deals with their own pricing and quotas. Custom plans are invisible to the public plan list; they only appear in the plan dialog of spaces they have been granted to. On self-hosted installations (or as the platform operator) they are managed with the `plans:custom` artisan command.

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
- Custom plans are always created inactive-for-the-public (`is_public = false`) and sort after the public plans.

### Granting it to a space

```bash
php artisan plans:custom grant --plan=<plan-id> --space=<space-id-or-slug>
php artisan plans:custom list                # all custom plans + their grants
php artisan plans:custom revoke --plan=<plan-id> --space=<space-id-or-slug>
```

Once granted, the plan shows up in that space's plan dialog and in the [payment request](agency-billing.md) picker like any other plan; checkout validates the grant, so an ungranted space can never buy a custom plan — even with the plan ID.

### Per-space quota overrides

For one-off deals that don't warrant their own plan, a **quota override** can be written onto a space's current subscription. Overridden keys replace the plan's quotas at read time; everything else keeps following the plan:

```bash
php artisan plans:custom override --space=<space> --quotas='{"aiCredit":100}'
php artisan plans:custom override --space=<space> --quotas=null   # back to plan defaults
```

The override survives billing renewals but is dropped automatically when the subscription switches to a different plan.

### Default public plans

The public plan lineup itself is bootstrapped with `php artisan plans:setup` (idempotent; `--force` overwrites with defaults, `--link` interactively connects plans to LemonSqueezy product/variant IDs, `--list` shows the current state).

## Paying for someone else's space — or having someone else pay

If the person who builds the space is not the person who should pay for it, use a **payment request**: pick the plan and invite a billing contact to complete the checkout, making them the billing owner. See [Agency Billing](agency-billing.md).
