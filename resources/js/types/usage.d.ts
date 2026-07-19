type UsageUnit = 'bytes' | 'count' | 'usd'

interface UsageMetric {
  key: string
  unit: UsageUnit
  used: number
  limit: number | null
  unlimited: boolean
  percentage: number
  exceeded: boolean
  available: boolean
}

interface SpaceUsage {
  storage: UsageMetric
  traffic: UsageMetric
  ai: UsageMetric
  period: {
    start: string
    end: string
    resets_at: string
  }
}

type PeriodCloseReason =
  | 'created'
  | 'renewed'
  | 'upgraded'
  | 'downgraded'
  | 'cancelled'
  | 'expired'

interface PeriodUsageMetric {
  used: number | null
  limit: number | null
  percentage: number | null
}

interface SubscriptionPeriod {
  id: string
  plan_id: string | null
  plan_name: string
  status: string
  price: number
  billing_period: 'month' | 'year' | 'forever'
  quotas: PlanQuotas | null
  started_at: string | null
  renews_at: string | null
  ended_at: string | null
  close_reason: PeriodCloseReason | null
  is_open: boolean
  usage: {
    storage: PeriodUsageMetric
    traffic: PeriodUsageMetric
    ai: PeriodUsageMetric
  }
}

type UsageTimeseriesMetric = 'traffic'

interface UsageTimeseriesPoint {
  date: string
  value: number
}

interface UsageTimeseries {
  metric: UsageTimeseriesMetric
  start: string | null
  end: string
  points: UsageTimeseriesPoint[]
}
