type BackupState = 'pending' | 'active' | 'expired' | 'failed'
type BackupSortColumn = 'name' | 'state' | 'progress' | 'created_at' | 'expires_at'
type SortOrder = 'asc' | 'desc'

interface BackupCreator {
  id: string
  display_name: string
  email?: string
}

interface BackupResource {
  id: string
  space_id: string
  state: BackupState
  progress: number
  name: string
  description?: string
  recipients: string[]
  has_password: boolean
  file_size?: number
  checksum?: string
  expires_at: string
  started_at?: string
  completed_at?: string
  failed_at?: string
  error_message?: string
  created_at: string
  created_by?: BackupCreator
}

interface CreateBackupPayload {
  name: string
  description?: string
  to: string[]
  password?: string
  expires_at: string
}

interface UpdateBackupPayload {
  name?: string
  description?: string
  expires_at?: string
}

interface BackupsQueryParams extends BaseQueryParams {
  name?: string
  state?: BackupState
  sort?: BackupSortColumn
  order?: SortOrder
}
