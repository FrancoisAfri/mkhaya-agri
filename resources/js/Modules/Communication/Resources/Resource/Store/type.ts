// ── Resource Types ─────────────────────────────────────────────────────────────
export enum ResourceType {
  Form         = 1,
  Legal        = 2,
  Handbook     = 3,
  HealthSafety = 4,
  Template     = 5,
  Training     = 6,
  SOP          = 7,
  Download     = 8,
}

export const RESOURCE_TYPE_CONFIG: Record<number, {
  label:    string
  icon:     string
  color:    string
  bg:       string
  gradient: string
}> = {
  1: { label: 'Forms',               icon: 'mdi-form-select',           color: '#7367F0', bg: '#f3f1ff', gradient: 'linear-gradient(135deg,#7367F0,#5e52d4)' },
  2: { label: 'Legal Documents',     icon: 'mdi-scale-balance',         color: '#EA5455', bg: '#ffeaea', gradient: 'linear-gradient(135deg,#EA5455,#c73c3c)' },
  3: { label: 'Employee Handbook',   icon: 'mdi-book-open-page-variant', color: '#FF9F43', bg: '#fff8ee', gradient: 'linear-gradient(135deg,#FF9F43,#e0852a)' },
  4: { label: 'Health & Safety',     icon: 'mdi-heart-pulse',           color: '#28C76F', bg: '#edfaf1', gradient: 'linear-gradient(135deg,#28C76F,#1da058)' },
  5: { label: 'Templates',           icon: 'mdi-file-document-outline', color: '#00CFE8', bg: '#e8fafd', gradient: 'linear-gradient(135deg,#00CFE8,#009db0)' },
  6: { label: 'Training Materials',  icon: 'mdi-school-outline',        color: '#9C27B0', bg: '#f9f0ff', gradient: 'linear-gradient(135deg,#9C27B0,#7b1f8c)' },
  7: { label: 'SOPs',                icon: 'mdi-clipboard-list-outline', color: '#FF5722', bg: '#fff3ee', gradient: 'linear-gradient(135deg,#FF5722,#d4431a)' },
  8: { label: 'Downloads',           icon: 'mdi-download-circle-outline',color: '#607D8B', bg: '#f0f4f5', gradient: 'linear-gradient(135deg,#607D8B,#4a606b)' },
}

export function getResourceTypeConfig(type: number) {
  return RESOURCE_TYPE_CONFIG[type] ?? {
    label: 'Resource', icon: 'mdi-file-outline', color: '#aaa', bg: '#f5f5f5',
    gradient: 'linear-gradient(135deg,#aaa,#888)',
  }
}

// ── Shared interfaces ──────────────────────────────────────────────────────────
export interface AssetsFile {
  id:         number
  title:      string | null
  image:      Record<string, any> | null
  video:      string | null
  document:   Record<string, any> | null
  created_at: string
  updated_at: string
}

export interface CompanyUnit {
  id:         number
  name:       string
  parent_id:  number | null
  type:       string | null
  sort_order: number
  active:     boolean
}

export interface GroupedUnits {
  divisions?:   CompanyUnit[]
  departments?: CompanyUnit[]
  teams?:       CompanyUnit[]
  sections?:    CompanyUnit[]
  units?:       CompanyUnit[]
}

export interface UserProperties {
  id:    number
  email: string
  name:string
  person?: {
    first_name: string | null
    surname:    string | null
    uuid:       string
  } | null
}

// ── Resource ───────────────────────────────────────────────────────────────────
export interface Resource {
  id:              number
  title:           string
  description:     string | null
  type:            ResourceType
  internal_only:   boolean
  is_active:       boolean
  meta_data:       Record<string, any> | null
  published_at:    string | null
  expired_at:      string | null
  assets_file_id:  number | null
  user_id:         number | null
  company_unit_id: number | null
  created_at:      string
  updated_at:      string
  deleted_at:      string | null

  asset?:       AssetsFile     | null
  author?:      UserProperties | null
  companyUnit?: CompanyUnit    | null
}

// ── Pagination ─────────────────────────────────────────────────────────────────
export interface PaginatedData<T> {
  data:         T[]
  total:        number
  current_page: number
  per_page:     number
  last_page:    number
}
