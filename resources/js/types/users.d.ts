import type { Clock, DateOrder } from '~/lib/display-formats'

export interface User {
  id: string
  firstname: string
  lastname: string
  email: string
  avatar?: string
  created_at: string
  updated_at: string
  email_verified_at?: string
  settings?: {
    languageIso?: string
    extendedSidebar?: boolean
    dateOrder?: DateOrder
    clock?: Clock
    /** `auto` (the browser's zone) or an IANA zone name. */
    timeZone?: string
  }
}
