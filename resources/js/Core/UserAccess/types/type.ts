export interface UserProperties {
  id:         number
  email:      string
  roles?: { id: number; name: string }[];
  status:     number
  person?: {
    first_name: string
    surname:    string
    initial?:   string
  } | null
}

export interface CategoryAccess {
  id:            number
  category_id:   number
  category_name: string
  category_icon: string
  access_level:  string | null
  active:        boolean | null
}

export interface ModuleAccess {
  id:           number
  module_id:    number
  module_name:  string
  module_icon:  string
  access_level: number | null
  category_id:  number
}

// export interface RibbonAccess {
//   id:           number
//   ribbon_id:    number
//   ribbon_name:  string
//   font_awesome: string
//   access_level: number | null
//   module_id:    number
//   active:       boolean | null
// }

export interface UserAccessData {
  categories: CategoryAccess[]
  modules:    ModuleAccess[]
//   ribbons:    RibbonAccess[]
}
