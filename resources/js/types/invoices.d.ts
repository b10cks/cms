interface InvoiceResource {
  id: string
  total: number
  total_formatted: string | null
  currency: string | null
  status: string | null
  status_formatted: string | null
  refunded: boolean
  card_brand: string | null
  card_last_four: string | null
  billing_reason: string | null
  invoice_url: string | null
  created_at: string | null
}
