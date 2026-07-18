interface PlanQuotas {
  requests: number | null // API requests per month
  traffic: number | null // bytes per month
  storage: number | null // bytes total
  aiCredit: number | null // AI spend cap (USD) per month
}

type BillingInterval = 'month' | 'year'

interface PlanResource {
  id: string
  name: string
  description: string | null
  features: string[]
  price: string
  yearly_price: string | null // set = the plan can be billed yearly
  period: 'month' | 'year' | 'forever'
  quotas: PlanQuotas | null
  is_free: boolean
  is_public?: boolean
  sort_order: number
  recommended?: boolean
  contact_url: string | null
}
