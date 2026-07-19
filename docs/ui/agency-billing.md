---
description: "The agency flow: build a space for a client, then hand the payment to a client-side billing contact via a payment request."
---

# Agency Billing

Agencies often build and manage a space while the **client pays for it**. The payment request flow makes this a first-class handover: you pick the plan, the client completes the checkout, and the client — not the agency — becomes the billing owner who receives all invoices and manages the payment method.

## How it works

1. **Build the space.** Create the space on the Free plan and set everything up. Nothing about the agency flow is time-critical: a space without a paid subscription simply runs with Free-tier allowances.
2. **Request the payment.** On **Settings → Subscription**, click **Request payment**. Pick the plan (including any custom plan granted to the space), the billing interval, and the email address of the client-side billing contact.
3. **The client gets access.**
   - If the email address is not a member of the space yet, an invitation with the **Billing** role is sent. That role only grants access to subscription, usage, and invoices — no content, assets, or settings.
   - If the address already belongs to a member, they get a payment-request notification instead.
4. **The client pays.** On the subscription page the client sees the proposed plan with price and interval, and completes the checkout with their own email and payment method. They become the LemonSqueezy customer: invoices, receipts, and the billing portal are theirs.
5. **Done.** The subscription activates as usual, and the payment request resolves automatically.

The agency keeps full control of the space content and settings throughout; only the billing relationship moves to the client.

## Rules & lifecycle

- **One open request per space.** Sending a new request supersedes the previous one.
- **Requests expire after 14 days.** After that the client can no longer act on it; just send a new one.
- **Withdraw any time.** The request banner has a **Withdraw** action for anyone with billing management rights.
- **Any paid activation resolves it.** If the client (or anyone else) subscribes the space to *any* paid plan, the open request is marked accepted — even if a different plan was chosen. The proposal is a suggestion, not a lock-in: the client can pick another plan on the subscription page.
- **No subscription is created up front.** The request itself doesn't touch billing. The checkout only happens when the client initiates it, which is what makes the client the billing owner.

## Custom agency plans

Payment requests combine well with **custom plans**: an operator can create a non-public plan (special pricing, custom quotas) and grant it to the client's space. It then appears in the plan picker of the request dialog, so the client pays exactly the agreed deal. See [Custom plans & pricing](subscription.md#custom-plans--pricing) for how to create, grant, and override them.

## Transferring billing of an already-paid space

An existing paid subscription cannot change its billing owner — the payer is fixed when the checkout happens. To move billing from the agency to the client:

1. Cancel the current subscription. It stays active until the end of the paid period.
2. Send a payment request to the client.
3. The client subscribes; the space switches to the client-paid subscription.

If the timing overlaps, no harm is done — the space never loses access, and if the paid period runs out before the client pays, the space falls back to the Free plan until they do.
