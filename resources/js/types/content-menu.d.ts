interface FlatContentMenuItem {
  id: string
  name: string
  slug: string
  block_id: string
  position: number
  type: 'root' | 'nestable' | 'single' | 'universal'
  color: string | null
  pid: string | null
  children: boolean
  icon?: string
  settings: Partial<ContentSettings>
  i18n: ContentMenuTranslation[]
  pat: string | null
  uat: string
}

interface ContentMenuItem extends FlatContentMenuItem {
  children: ContentMenuItem[]
}

interface ContentMenuTranslation {
  id: string
  name: string
  language_iso: string
  published_at: string | null
}

interface ContentMenuResponse {
  data: Record<string, FlatContentMenuItem>
}
