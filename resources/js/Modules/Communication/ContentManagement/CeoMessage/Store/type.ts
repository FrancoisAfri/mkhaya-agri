// Types bruts reçus du backend Laravel
export interface RawAssetsFile {
  id: number
  title?: string | null
  image?: Array<{
    uuid: string
    original_name: string
    mime: string
    type: string
    data: string
    size: number
  }> | null
  video?: {
    path: string
    original_name: string
    mime: string
    size: number
  } | null
  document?: any | null
  created_at?: string
  updated_at?: string
  deleted_at?: string | null
}

export interface RawCeoMessage {
  id: number
  message: string
  assets_file_id: number | null
  assets_file: RawAssetsFile | null
  created_at?: string
  updated_at?: string
  deleted_at?: string | null
}

// Types formatés pour le frontend
export interface AssetsFile {
  id: number
  title?: string | null
  image?: Array<{
    uuid: string
    original_name: string
    mime: string
    data: string
    size: number
  }> | null
  video?: {
    path: string
    original_name: string
    mime: string
    size: number
  } | null
}

export interface CeoMessage {
  id: number
  message: string
  assets_file_id: number | null
  assetsFile: AssetsFile | null
  image_url: string | null
  video_url: string | null
  created_at?: string
  updated_at?: string
  deleted_at?: string | null
}

// Type pour le formulaire de soumission
export interface CeoMessageForm {
  message: string
  image?: File | null
  video?: File | null
  removeMedia?: boolean
}
export interface RawCeoMessage {
  id: number
  message: string
  assets_file_id: number | null
  assets_file: RawAssetsFile | null
  image_url?: string | null
  video_url?: string | null
  created_at?: string
  updated_at?: string
  deleted_at?: string | null
}
