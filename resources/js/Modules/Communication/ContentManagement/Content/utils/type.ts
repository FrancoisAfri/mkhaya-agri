export enum ContentType {
  Announcement = 1,
  News = 2,
  Event = 3,
  InternalNotice = 4
}

export interface Content {
  id: number;

  assets_file_id: number | null;
  user_id: number | null;
  company_unit_id: number | null;

  title: string;
  description: string | null;
  summary: string | null;
  icon: string | null;

  event_start: string | null;
  event_end: string | null;
  event_date: string | null;
  expired_at: string | null;

  type: ContentType;
  is_active: boolean;
  meta_data: Record<string, any> | null;

  created_at: string;
  updated_at: string;
  deleted_at: string | null;

  author?: HrPerson | null;
  asset?: AssetsFile | null;
  companyUnit?: CompanyUnit | null;
}

// AssetsFile type
export interface AssetsFile {
  id: number;
  title: string | null;
  image: Record<string, any> | null;
  video: string | null;
  document: Record<string, any> | null;
  created_at: string;
  updated_at: string;
}

// CompanyUnit type
export interface CompanyUnit {
  id: number;
  name: string;
  parent_id: number | null;
  user_id: number | null;
  company_id: number;
  type: string | null;
  sort_order: number;
  active: boolean;
  created_at: string;
  updated_at: string;

  parent?: CompanyUnit | null;
  children?: CompanyUnit[];
}

export interface HrPerson {
  id: number;
  user_id: number | null;
  first_name: string | null;
  surname: string | null;
}

export interface ContentFormData {
  title: string;
  description?: string;
  summary?: string;
  icon?: string;
  event_start?: string;
  event_end?: string;
  event_date?: string;
  expired_at?: string;
  type: ContentType;
  is_active: boolean;
  meta_data?: Record<string, any>;
  assets_file_id?: number;
  company_unit_id?: number;
}

export interface ContentFilters {
  type?: ContentType;
  is_active?: boolean;
  search?: string;
  date_from?: string;
  date_to?: string;
  company_unit_id?: number;
}

export interface UserProperties {
  id: number;
  email: string;
  roles: { id: number; name: string }[];
  status: number;
  person?: {
    first_name: string | null;
    uuid: string;
    surname: string | null;
    initial?: string | null;

  } | null;
}
