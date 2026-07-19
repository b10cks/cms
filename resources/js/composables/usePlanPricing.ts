/**
 * The yearly-vs-monthly pricing rule in one place: a plan bills yearly only
 * when the chosen interval is 'year' AND the plan actually has a yearly price;
 * everything else falls back to the monthly price/period.
 */
export function usePlanPricing() {
  const billsYearly = (plan: PlanResource, interval: BillingInterval) =>
    interval === 'year' && !!plan.yearly_price

  const planPrice = (plan: PlanResource, interval: BillingInterval) =>
    interval === 'year' && plan.yearly_price ? plan.yearly_price : plan.price

  /** i18n key suffix for the "/ month" | "/ year" period label. */
  const planPeriodKey = (plan: PlanResource, interval: BillingInterval) =>
    billsYearly(plan, interval) ? 'year' : plan.period

  /** The interval to actually check out with — monthly for plans without a yearly price. */
  const checkoutInterval = (plan: PlanResource, interval: BillingInterval): BillingInterval =>
    billsYearly(plan, interval) ? 'year' : 'month'

  return { planPrice, planPeriodKey, checkoutInterval }
}
