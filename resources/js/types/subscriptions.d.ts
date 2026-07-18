type SubscriptionStatus =
  | 'active'
  | 'on_trial'
  | 'pending'
  | 'paused'
  | 'past_due'
  | 'unpaid'
  | 'cancelled'
  | 'expired'

interface SubscriptionResource {
  id: string
  space_id: string
  plan_id: string | null
  plan: PlanResource | null
  name: string
  status: SubscriptionStatus
  is_active: boolean
  is_free: boolean
  variant_id: string
  product_id: string
  quantity: number
  billing_interval: BillingInterval
  quotas: PlanQuotas | null
  billing_portal_url: string | null
  renews_at: string | null
  ends_at: string | null
  trial_ends_at: string | null
  created_at: string
  updated_at: string
}

interface CheckoutResponse {
  checkout_url: string | null
  upgraded?: boolean
  scheduled?: boolean
  message?: string
}
