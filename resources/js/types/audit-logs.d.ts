interface AuditLogItemRoute {
  exists: boolean
  route_name: string | null
  route_params: Record<string, string>
  route_query: Record<string, string> | null
}

interface AuditLogOwner {
  id: string
  name: string
  avatar: string | null
  initials: string
  email: string
  created_at: string
}

interface AuditLogResource {
  id: string
  created_at: string
  referenced_type: string
  referenced_id: string
  name: string
  operation: string
  /** Composite key for translation lookup: `${referenced_type}.${operation}` */
  key: string
  owner_type: 'user' | 'system'
  owner_id: string | null
  owner_name: string | null
  owner: AuditLogOwner | null
  item: AuditLogItemRoute
}

