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

## Custom plans

Besides the public plans, negotiated **custom plans** exist — agency or subsidized deals with their own pricing and quotas. A custom plan never shows up in the public plan list; once it has been granted to your space, it appears in the plan dialog (and the [payment request](agency-billing.md) picker) like any other plan, and you subscribe to it the same way.

If you think your space qualifies for a custom deal, talk to your platform operator or agency. Running your own instance? Creating and granting custom plans is covered in [Self-hosting → Plans & pricing](../self-hosting/plans-and-pricing.md#custom-plans).

## Paying for someone else's space — or having someone else pay

If the person who builds the space is not the person who should pay for it, use a **payment request**: pick the plan and invite a billing contact to complete the checkout, making them the billing owner. See [Agency Billing](agency-billing.md).
