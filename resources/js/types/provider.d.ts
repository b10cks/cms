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
