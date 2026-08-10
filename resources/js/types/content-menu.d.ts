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
  /** Live since — `null` means never published or unpublished again. */
  pat: string | null
  /** Has a draft version that is not the published one. */
  drf: boolean
  cat?: string
  uat: string
  sv?: string | number | null
}

interface ContentMenuItem extends FlatContentMenuItem {
  children: ContentMenuItem[]
}

interface ContentMenuTranslation {
  id: string
  name: string
  language_iso: string
  published_at: string | null
  drf: boolean
}

interface ContentMenuResponse {
  data: Record<string, FlatContentMenuItem>
}
