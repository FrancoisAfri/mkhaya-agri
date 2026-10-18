export interface Content {
  id:              number
  title:           string
  summary:         string | null
  description:     string | null
  icon:            string | null
  type:            number
  is_active:       boolean
  event_date:      string | null
  event_start:     string | null
  event_end:       string | null
  expired_at:      string | null
  company_unit_id: number | null
  created_at:      string
  updated_at:      string

  asset?:       { image: any; video: string | null } | null
  author?:      { id: number; email: string; person?: { first_name: string | null; surname: string | null } | null } | null
  companyUnit?: { id: number; name: string } | null
}

export interface EmployeeSpotlight {
  id:              number
  title:           string
  description:     string | null
  type:            number
  month:           string
  employee_id:     number | null
  company_unit_id: number | null
  created_at:      string

  employee?:    { id: number; email: string; person?: { first_name: string | null; surname: string | null } | null } | null
  companyUnit?: { id: number; name: string } | null
  asset?:       { image: any ,video:any} | null
}

export interface CeoMessage {
  id:         number
  title:      string | null
  message:    string | null
  is_active:  boolean

  asset?:       { image: any ,video:any} | null
  updated_at: string
}

export interface ReactionSummary {
    like_count: number
    user_liked: boolean
    reaction_id: number | null
}

// ── Quick Access & Ribbon Types ────────────────────────────────────────────
export interface Module {
  id: number
  name: string
  code_name: string | null
  path: string
  font_awesome: string | null
  active: number
}

export interface Ribbon {
  id: number
  module_id: number
  ribbon_name: string | null
  ribbon_path: string | null
  font_awesome: string | null
  access_level: number | null
  active: number | null
  sort_order: number | null
  description: string | null
  uuid: string | null
  created_at: string
  updated_at: string
  module?: Module
  pivot?: {
    name?: string | null
  }
}

export interface QuickAccessItem {
  id: number
  ribbon_id: number
  created_at: string
  updated_at: string
  deleted_at?: string | null
  ribbon?: Ribbon
}
