type MigrationState = 'pending' | 'processing' | 'completed' | 'failed'
type ConflictStrategy = 'skip' | 'overwrite' | 'merge_newer'

interface MigrationScope {
  blocks: boolean
  block_templates: boolean
  content: boolean
  assets: boolean
  data_sources: boolean
  redirects: boolean
}

interface MigrationStats {
  block_folders: number
  blocks: number
  asset_folders: number
  assets: number
  contents: number
  content_versions: number
  data_sources: number
  data_entries: number
  redirects: number
}

interface MigrationImportResult {
  created: Record<string, number>
  updated: Record<string, number>
  skipped: Record<string, number>
  errors: Array<{ entity: string; id: string; message: string }>
}

interface MigrationSpaceRef {
  id: string
  name: string
  slug: string
}

interface MigrationCreator {
  id: string
  display_name: string
  email?: string
}

interface MigrationResource {
  id: string
  source_space_id: string
  target_space_id: string
  state: MigrationState
  progress: number
  scope: MigrationScope
  conflict_strategy: ConflictStrategy
  stats?: MigrationStats
  result?: MigrationImportResult
  error_message?: string
  started_at?: string
  completed_at?: string
  failed_at?: string
  created_at: string
  source_space?: MigrationSpaceRef
  target_space?: MigrationSpaceRef
  created_by?: MigrationCreator
}

interface CreateMigrationPayload {
  target_space_id: string
  scope: MigrationScope
  conflict_strategy: ConflictStrategy
}
