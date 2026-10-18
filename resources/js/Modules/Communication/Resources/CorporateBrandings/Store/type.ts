// ─── Raw data from API / Laravel ──────────────────────────────────────────────

export interface RawAssetsFile {
  id: number
  title: string | null
  image: string | null
  video: string | null
}

export interface RawCorporateBranding {
  id: number
  name: string
  company_unit_id: number | null
  user_id: number | null

  logo_light_id: number | null
  logo_dark_id: number | null
  favicon_id: number | null

  logo_light?: RawAssetsFile | null
  logo_dark?: RawAssetsFile | null
  favicon?: RawAssetsFile | null

  logo_light_url: string | null
  logo_dark_url: string | null
  favicon_url: string | null

  primary_color: string | null
  secondary_color: string | null
  accent_color: string | null
  background_color: string | null
  text_color: string | null

  font_family: string | null
  font_url: string | null

  meta_data: Record<string, unknown> | null
  is_active: boolean

  created_at: string
  updated_at: string
  deleted_at: string | null
}

// ─── Normalised internal model ────────────────────────────────────────────────

export interface AssetsFile {
  id: number
  title: string | null
  image: string | null
  video: string | null
}

export interface CorporateBranding {
  id: number
  name: string
  company_unit_id: number | null
  user_id: number | null

  logo_light_id: number | null
  logo_dark_id: number | null
  favicon_id: number | null

  logoLight: AssetsFile | null
  logoDark: AssetsFile | null
  favicon: AssetsFile | null

  logo_light_url: string | null
  logo_dark_url: string | null
  favicon_url: string | null

  primary_color: string
  secondary_color: string
  accent_color: string
  background_color: string
  text_color: string

  font_family: string
  font_url: string

  meta_data: Record<string, unknown> | null
  is_active: boolean

  created_at: string
  updated_at: string
  deleted_at: string | null
}

// ─── Form fields ──────────────────────────────────────────────────────────────

export interface BrandingFormFields {
  name: string
  company_unit_id?: number | null
  primary_color: string
  secondary_color: string
  accent_color: string
  background_color: string
  text_color: string
  font_family: string
  font_url: string
  is_active: boolean
  meta_data?: Record<string, unknown> | null
}

export interface BrandingSubmitPayload extends BrandingFormFields {
  logo_light?: File | null
  logo_dark?: File | null
  favicon?: File | null
}
