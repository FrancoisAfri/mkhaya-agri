export interface Permission {
  id:          number
  name:        string
  guard_name?: string
}

export interface PersonName {
  first_name: string | null
  surname:    string | null
  uuid:        string
}

export interface SampleUser {
  id:      number
  person?: PersonName | null
}

export interface RoleProperties {
  id:           number
  name:         string
  guard_name?:  string
  permissions:  Permission[]
  users_count?: number
  sample_users?: SampleUser[]
}

export interface UserWithRole {
  id:     number
  email:  string
  status: number
  type?:  number | null
  roles:  Array<{ id: number; name: string }>
  person?: PersonName | null
}

export interface PaginatedUsers {
  data:         UserWithRole[]
  current_page: number
  last_page:    number
  per_page:     number
  total:        number
  from:         number | null
  to:           number | null
}
