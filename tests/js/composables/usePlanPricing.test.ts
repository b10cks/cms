import { describe, expect, it } from 'vitest'

import { usePlanPricing } from '~/composables/usePlanPricing'

const { planPrice, planPeriodKey, checkoutInterval } = usePlanPricing()

const plan = (overrides: Partial<PlanResource> = {}): PlanResource =>
  ({
    id: 'plan-1',
    name: 'Pro',
    price: '29.00',
    yearly_price: '290.00',
    period: 'month',
    ...overrides,
  }) as PlanResource

describe('planPrice', () => {
  it('returns the yearly price for a yearly interval when the plan has one', () => {
    expect(planPrice(plan(), 'year')).toBe('290.00')
  })

  it('returns the monthly price for a monthly interval', () => {
    expect(planPrice(plan(), 'month')).toBe('29.00')
  })

  it('falls back to the monthly price when the plan has no yearly price', () => {
    expect(planPrice(plan({ yearly_price: null }), 'year')).toBe('29.00')
  })

  it('treats an empty yearly price as absent', () => {
    expect(planPrice(plan({ yearly_price: '' }), 'year')).toBe('29.00')
  })

  it('treats a "0.00" yearly price as absent — the string is truthy, the amount is not', () => {
    expect(planPrice(plan({ yearly_price: '0.00' }), 'year')).toBe('29.00')
  })
})

describe('planPeriodKey', () => {
  it('is "year" when the plan actually bills yearly', () => {
    expect(planPeriodKey(plan(), 'year')).toBe('year')
  })

  it('echoes the plan period otherwise', () => {
    expect(planPeriodKey(plan(), 'month')).toBe('month')
    expect(planPeriodKey(plan({ yearly_price: null }), 'year')).toBe('month')
  })

  it('keeps "forever" for a free plan even under a yearly interval', () => {
    expect(planPeriodKey(plan({ period: 'forever', yearly_price: null }), 'year')).toBe('forever')
  })

  it('reports "year" for a yearly-only plan under a monthly interval', () => {
    // Not billsYearly (the interval is 'month'), so the plan's own period wins.
    expect(planPeriodKey(plan({ period: 'year' }), 'month')).toBe('year')
  })
})

describe('checkoutInterval', () => {
  it('checks out yearly only when the plan has a yearly price', () => {
    expect(checkoutInterval(plan(), 'year')).toBe('year')
    expect(checkoutInterval(plan({ yearly_price: null }), 'year')).toBe('month')
  })

  it('checks out monthly for a monthly interval', () => {
    expect(checkoutInterval(plan(), 'month')).toBe('month')
  })

  it('checks out monthly for a plan whose own period is yearly but interval is monthly', () => {
    expect(checkoutInterval(plan({ period: 'year' }), 'month')).toBe('month')
  })
})
