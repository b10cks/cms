interface ProviderSummaryMetric {
  total: number
  new: number
}

interface ProviderStats {
  range: {
    start_date: string
    end_date: string
  }
  summary: {
    teams: ProviderSummaryMetric
    spaces: ProviderSummaryMetric
    users: ProviderSummaryMetric
  }
}

interface ProviderNote {
  id: string
  title: string
  icon?: string | null
  url?: string | null
  color?: string | null
  content?: string | null
  is_pinned: boolean
  created_at: string
  updated_at: string
}

interface ProviderStatsQueryParams {
  start_date?: string
  end_date?: string
}

interface ProviderNotePayload {
  title: string
  icon?: string | null
  url?: string | null
  color?: string | null
  content?: string | null
  is_pinned?: boolean
}

type ProviderMetricsRange = '6m' | '12m' | '24m' | 'ytd'

/**
 * One dashboard figure: a value per month of the range (null where it has none), the current
 * value, and what to compare it with. Flows compare month to date with the same days of the
 * earlier month; `previous` and `year_ago` are null where the instance has no data yet.
 */
interface ProviderMetric {
  series: Array<number | null>
  current: number | null
  previous: number | null
  year_ago: number | null
}

/** `GET /provider/metrics`. Amounts are EUR, AI amounts USD, rates are fractions (0.25 = 25 %). */
interface ProviderMetrics {
  /** `YYYY-MM`, oldest first; the last is the current, partial month. */
  months: string[]
  as_of: string
  /** Omitted when billing is off (self-hosted). */
  revenue?: {
    mrr: ProviderMetric
    arr: number
    net_new_mrr: ProviderMetric
    movement: { new: number[]; expansion: number[]; churn: number[] }
    paying_spaces: ProviderMetric
    paying_teams: ProviderMetric
    arpa: ProviderMetric
    revenue_churn: ProviderMetric
    logo_churn: ProviderMetric
    by_plan: { name: string; mrr: number }[]
    /** Per start month: MRR a month later relative to the first month, up to 12 months. */
    retention: { month: string; mrr: number; values: number[] }[]
  }
  growth: {
    new_users: ProviderMetric
    new_spaces: ProviderMetric
    new_teams: ProviderMetric
  }
  engagement: {
    api_requests: ProviderMetric
    traffic_bytes: ProviderMetric
  }
}
