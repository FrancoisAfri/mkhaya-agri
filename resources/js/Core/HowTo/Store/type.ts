export interface RibbonProperties {
  id: number
  module_id: number
  ribbon_name: string
  ribbon_path: string
  font_awesome: string | null
  description: string | null
  access_level: number
  sort_order: number
  active: boolean
  uuid: string
}

export interface HowToProperties {
  id: number
  module_id: number
  ribbon_id: number
  question: string
  answer: string | null
  active: boolean
  created_at?: string
  updated_at?: string
}

export interface ModuleProperties {
  id: number
  category_id: number
  name: string
  path: string
  active: boolean
  font_awesome: string | null
  code_name: string
  uuid: string
}

export interface CategoryProperties {
  id: number
  name: string
  icon: string
  description: string
  active: boolean
  modules?: ModuleProperties[]
}

// Grouped howtos: ribbon_id => HowToProperties[]
export type GroupedHowTos = Record<number, HowToProperties[]>
