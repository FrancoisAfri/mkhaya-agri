export interface ModuleProperties {
  id:           number
  category_id:  number  // ✅ number pas string
  name:         string
  font_awesome: string
  code_name:    string
  path:         string
  active:       boolean
}
