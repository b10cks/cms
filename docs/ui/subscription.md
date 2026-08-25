---
description: "Plans, allowances, billing intervals, what happens when a payment fails, and where to find usage history and invoices."
---

# Subscription and billing

Every space has its own subscription, and the plan decides the space's monthly allowances. Payment is handled by LemonSqueezy, the billing provider behind b10cks, which is also who sends your invoices and stores your payment method.

Everything on this page lives under **Settings → Subscription** and **Settings → Usage**, and needs billing permissions, which are explained [further down](#who-may-see-and-change-billing).

## Plans and allowances

A plan comes with a set of monthly allowances. They are watched, and you are told when you approach them, but with one exception they are not enforced.

| Allowance | What it counts |
| --- | --- |
| **Traffic** | Everything your website and its visitors download from b10cks in a calendar month, including files people download through share links |
| **Storage** | How much space your uploaded files occupy right now. This one is a snapshot, not a monthly total. |
| **AI credit** | How much may be spent on AI in a month, in US dollars. This is the one limit that genuinely stops: when the credit is used up, AI requests stop until the next period. |

**API requests are unlimited on every plan.** You will see request counts on the space dashboard, but purely as information. They are neither limited nor billed.

Traffic and storage are **soft** allowances. Nothing gets switched off when you go over. Instead, everybody with billing visibility is notified when a number passes **80 percent** and again at **100 percent**, at most once per threshold per month. If you exceed an allowance month after month, you will be asked to move to a plan that fits.

Allowances reset with the calendar month, no matter which billing interval you chose.

## Monthly or yearly

Plans are priced per month, and most also offer a discounted yearly price. You pick the interval when subscribing. Allowances stay monthly either way: a yearly subscription does not hand you a year's worth of traffic on day one.

## What the status means

| Status | What it means for you |
| --- | --- |
| **Active** or **On trial** | Everything the plan includes is available |
| **Pending** | A checkout was started but never finished. **Retry payment** picks it up again. |
| **Past due** | A renewal payment failed and is being retried automatically. Your access continues in the meantime. Update your payment method under **Manage billing**. |
| **Cancelled** | You cancelled, and access continues until the end of the period you already paid for. **Resume** any time before then. |
| **Unpaid** or **Expired** | The subscription lapsed. The space automatically falls back to the Free plan and keeps working with Free allowances. |

Cancelling never cuts you off in the middle of a paid period. The plan stays fully active until that period ends, then the space moves to the Free plan on its own. Nothing is deleted.

## Usage and history

**Settings → Usage** shows:

- **Current usage** against your plan's allowances.
- A **traffic chart** for the selected billing period.
- **The history**, one entry per billing period with the usage that accumulated in it. A period closes when the subscription renews, when the plan changes, or when it is cancelled.
- **Invoices**, fetched from LemonSqueezy, with download links. **Manage billing** opens LemonSqueezy's own portal for payment methods and receipts.

## Who may see and change billing

Two permissions control this. One allows *seeing* the plan, the usage, and the invoices, and also determines who receives the usage alerts. The other allows *acting*: subscribing, changing the plan, cancelling, and sending payment requests.

- **Owner** and **Admin** have both.
- The separate **Billing** role has *only* these two and nothing else. Somebody with it can look after payment without being able to see or change a single page. That exists so a finance contact, often on the client's side in an [agency setup](agency-billing.md), can own the payment relationship without getting access to the content.

## Custom plans

Besides the public plans there are negotiated **custom plans**, for example an agency deal or a subsidized rate with its own quotas. A custom plan never appears in the public list. Once it has been granted to your space it shows up in your plan dialog like any other, and you subscribe to it the same way.

If you think your space qualifies, talk to your platform operator or your agency. Running your own installation? Creating and granting custom plans is covered in [Plans and pricing](../self-hosting/plans-and-pricing.md#custom-plans).

## When somebody else should pay

If the people building the space are not the people who should pay for it, use a **payment request**: you pick the plan, and the person who completes the checkout becomes the billing owner. See [Agency billing](agency-billing.md).
