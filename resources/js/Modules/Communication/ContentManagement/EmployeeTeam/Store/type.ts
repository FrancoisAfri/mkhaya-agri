/**
 * Type corresponding to the values in the 'type' column
 * 1 = Award
 * 2 = Anniversary
 * 3 = Competition
 */
export enum EmployeeSpotlightType {
  Award       = 1,
  Anniversary = 2,
  Competition = 3,
}

export interface EmployeeSpotlight {
  id: number

  user_id:         number
  company_unit_id: number | null
  employee_id:     number | null
  asset_file_id:   number | null

  title:       string
  description: string | null

  type:  EmployeeSpotlightType
  month: string

  created_at: string
  updated_at: string
  deleted_at: string | null

  // Relations
  author?:      UserProperties | null
  employee?:    UserProperties | null
  asset?:       AssetsFile     | null
  companyUnit?: CompanyUnit    | null
}

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
  user_id:    number | null
  company_id: number
  type:       string | null
  sort_order: number
  active:     boolean
  created_at: string
  updated_at: string

  parent?:   CompanyUnit | null
  children?: CompanyUnit[]
}

export interface HrPerson {
  id:         number
  user_id:    number | null
  first_name: string | null
  surname:    string | null
}

export interface UserProperties {
  id:    number
  email: string
  roles: { id: number; name: string }[]
  status: number
  person?: {
    first_name:          string | null
    surname:             string | null
    uuid:                string
    initial?:            string | null

  } | null
}

export interface EmployeeSpotlightFormData {
  title:            string
  description?:     string
  type:             EmployeeSpotlightType
  month:            string
  company_unit_id?: number
  employee_id?:     number
  asset_file_id?:   number
}

export interface EmployeeSpotlightFilters {
  type?:            EmployeeSpotlightType
  month?:           string
  search?:          string
  company_unit_id?: number
  employee_id?:     number
}

export interface PaginatedData<T> {
  data:         T[]
  total:        number
  current_page: number
  per_page:     number
  last_page:    number
}
