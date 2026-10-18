export default interface CompanyIdentity {
  id:                       number
  company_name:             string
  full_company_name:        string
  header_name_bold:         string
  header_name_regular:      string
  header_acronym_bold:      string
  header_acronym_regular:   string
  company_logo:             string | null
  login_background_image:   string | null
  system_background_image:  string | null
  mailing_name:             string
  mailing_address:          string
  support_email:            string
  company_website:          string
  password_expiring_month:  number
  brought_to_text:          string | null
  brought_to_text_image:    string | null
  sys_theme_color:          string | null
}

export interface CompanyUser {
  id:      number
  name:    string
  email:   string
  avatar:  string | null
  initial: string
}
