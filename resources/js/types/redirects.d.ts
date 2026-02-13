interface RedirectResource {
  id: string
  source: string
  target: string
  status_code: number
  hits: number
  last_used_at: string | null
  created_at: string
  updated_at: string
}

interface CreateRedirectPayload {
  source: string
  target: string
  status_code: number
}

interface UpdateRedirectPayload {
  source?: string
  target?: string
  status_code?: number
}
