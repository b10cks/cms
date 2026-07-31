/**
 * The yearly-vs-monthly pricing rule in one place: a plan bills yearly only
 * when the chosen interval is 'year' AND the plan actually has a yearly price;
 * everything else falls back to the monthly price/period.
 */
export function usePlanPricing() {
  // `yearly_price` is a decimal string, so '0.00' is truthy — compare the
  // amount, not the string.
  const hasYearlyPrice = (plan: PlanResource) =>
    plan.yearly_price != null && Number(plan.yearly_price) > 0

  const billsYearly = (plan: PlanResource, interval: BillingInterval) =>
    interval === 'year' && hasYearlyPrice(plan)

  const planPrice = (plan: PlanResource, interval: BillingInterval) =>
    billsYearly(plan, interval) ? (plan.yearly_price as string) : plan.price

  /** i18n key suffix for the "/ month" | "/ year" period label. */
  const planPeriodKey = (plan: PlanResource, interval: BillingInterval) =>
    billsYearly(plan, interval) ? 'year' : plan.period

  /** The interval to actually check out with — monthly for plans without a yearly price. */
  const checkoutInterval = (plan: PlanResource, interval: BillingInterval): BillingInterval =>
    billsYearly(plan, interval) ? 'year' : 'month'

  return { planPrice, planPeriodKey, checkoutInterval }
}
