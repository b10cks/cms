export interface User {
  id: string
  firstname: string
  lastname: string
  email: string
  avatar?: string
  created_at: string
  updated_at: string
  settings?: {
    languageIso?: string
  }
}
