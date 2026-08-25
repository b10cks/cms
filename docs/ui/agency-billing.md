---
description: "The agency handover: build a space for a client, then let the client pay for it and own the billing relationship."
---

# Agency billing

Agencies usually build and run a space while **the client pays for it**. Handing over a credit card by email is not a process, so b10cks makes the handover a proper step: you pick the plan, your client completes the checkout, and the client becomes the billing owner who receives the invoices and manages the payment method.

## How it works

1. **Build the space.** Create it on the Free plan and set everything up. There is no time pressure: a space without a paid subscription simply runs with Free-plan allowances.
2. **Request the payment.** Under **Settings → Subscription**, click **Request payment**. Choose the plan, including any custom plan that was granted to the space, the billing interval, and the email address of the person on the client side who handles payment.
3. **Your client gets access.**
   - If that email address is not a member of the space yet, an invitation with the **Billing** role is sent. That role grants access to subscription, usage, and invoices only. No content, no files, no settings.
   - If the address already belongs to a member, they receive a notification about the payment request instead.
4. **Your client pays.** On the subscription page they see the proposed plan with its price and interval and complete the checkout with their own email address and payment method. They become the customer at the billing provider, so invoices, receipts, and the billing portal belong to them.
5. **Done.** The subscription activates as usual, and the payment request closes itself.

Throughout, the agency keeps full control of the content and the settings. Only the billing relationship moves.

## The rules

- **One open request per space.** Sending a new one replaces the previous one.
- **Requests expire after 14 days.** After that your client can no longer act on it, so send a fresh one.
- **You can withdraw it at any time.** The banner has a **Withdraw** action for anybody with billing permissions.
- **Any paid subscription closes the request.** If your client, or anybody else, subscribes the space to any paid plan, the open request counts as accepted, even if they picked a different plan than you proposed. The proposal is a suggestion, not a lock-in.
- **Nothing is charged up front.** The request itself does not touch billing at all. The checkout happens when your client starts it, and that is exactly what makes them the billing owner.

## Custom agency plans

Payment requests work well together with **custom plans**. The platform operator can create a plan that is not publicly listed, with its own pricing and allowances, and grant it to your client's space. It then appears in the plan picker of the request dialog, so your client pays precisely the deal you agreed. Ask your operator about it, or, if you run your own installation, see [Plans and pricing](../self-hosting/plans-and-pricing.md#custom-plans).

## Moving billing on a space that is already paid

An existing paid subscription cannot change its payer. That is fixed when the checkout happens. To move billing from the agency to the client:

1. Cancel the current subscription. It stays active until the end of the period you already paid for.
2. Send a payment request to your client.
3. Your client subscribes, and the space switches to their subscription.

Overlapping timing does no harm. The space never loses access, and if the paid period runs out before your client has paid, the space falls back to the Free plan until they do.
