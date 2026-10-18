// types.ts

export type Gender = 1 | 2
export type Title = 1 | 2 | 3 | 4
export type Disabled = 1 | 2
export type EmploymentType = 1 | 2
export type JobFunction = 1 | 2
export type CanDrive = 1 | 2

// ── Labels Maps ──
export const TITLE_LABELS: Record<number, string> = {
  1: 'Mr',
  2: 'Miss',
  3: 'Ms',
  4: 'Dr',
}

export const GENDER_LABELS: Record<number, string> = {
  1: 'Male',
  2: 'Female',
}

export const MARITAL_STATUS_LABELS: Record<number, string> = {
  1: 'Single',
  2: 'Married',
  3: 'Divorced',
  4: 'Widowed',
  5: 'Separated',
  6: 'In a relationship',
  7: 'Co-habiting',
}

export const ETHNICITY_LABELS: Record<number, string> = {
  1: 'African',
  2: 'Coloured',
  3: 'Indian/Asian',
  4: 'White',
  5: 'Other',
}

export const EMPLOYMENT_TYPE_LABELS: Record<number, string> = {
  1: 'Permanent',
  2: 'Temporary',
}

export const OCCUPATIONAL_LEVEL_LABELS: Record<number, string> = {
  1: 'Senior Management',
  2: 'Middle Management',
  3: 'Junior Management',
  4: 'Semi Skilled',
  5: 'Unskilled',
  6: 'Professional',
  7: 'Skilled',
}

export const JOB_FUNCTION_LABELS: Record<number, string> = {
  1: 'Core/Operational',
  2: 'Support',
}

export const DISABLED_LABELS: Record<number, string> = {
  1: 'Yes',
  2: 'No',
}

export const CAN_DRIVE_LABELS: Record<number, string> = {
  1: 'Yes',
  2: 'No',
}

export const CITIZENSHIP_LABELS: Record<number, string> = {
  1: 'Citizen',
  2: 'Permanent Resident',
  3: 'Work Permit',
  4: 'Other',
}

// Label map global pour le formatValue
export const LABEL_MAPS: Record<string, Record<number, string>> = {
  title: TITLE_LABELS,
  gender: GENDER_LABELS,
  marital_status: MARITAL_STATUS_LABELS,
  ethnicity: ETHNICITY_LABELS,
  employment_type: EMPLOYMENT_TYPE_LABELS,
  occupational_level: OCCUPATIONAL_LEVEL_LABELS,
  job_function: JOB_FUNCTION_LABELS,
  disabled: DISABLED_LABELS,
  can_drive: CAN_DRIVE_LABELS,
  citizenship: CITIZENSHIP_LABELS,
}

export interface ApprovalItem {
  id: number
  hr_main_id: number
  first_name: string
  surname: string
  employee_number: string | null
  is_approved: boolean | number
  created_at: string
  profile_pic_url?: string | null
  [key: string]: unknown
}

export interface HRPerson {
  id: number
  first_name: string
  surname: string
  employee_number: string | null
  date_joined: number | null
  date_left: number | null
  date_of_birth: number | null
  gender: number | null
  title: number | null
  marital_status: number | null
  ethnicity: number | null
  employment_type: number | null
  occupational_level: number | null
  job_function: number | null
  disabled: number | null
  can_drive: number | null
  citizenship: number | null
  email: string | null
  cell_number: string | null
  phone_number: string | null
  alternate_number: string | null
  id_number: string | null
  passport_number: string | null
  drivers_licence_number: string | null
  drivers_licence_code: string | null
  drivers_licence_exp_date: number | null
  proof_drive_permit: string | null
  proof_drive_permit_exp_date: number | null
  own_transport: number | null
  res_address: string | null
  res_suburb: string | null
  res_city: string | null
  res_postal_code: number | null
  res_province_id: number | null
  res_country_id: number | null
  middle_name: string | null
  maiden_name: string | null
  aka: string | null
  known_as: string | null
  initial: string | null
  profile_pic: string | null
  profile_pic_url?: string | null
  next_of_kin: string | null
  next_of_kin_number: string | null
  next_of_kin_work_number: string | null
  complex_name: string | null
  unit_number: string | null
  income_tax_number: string | null
  tax_office: string | null
  account_type: string | null
  account_holder_name: string | null
  bank_name: string | null
  branch_name: string | null
  account_number: string | null
  start_time: string | null
  end_time: string | null
  nature_of_disability: string | null
  other: string | null
  leave_profile: number | null
  leave_profile_data?: { name: string } | null
  province?: { name: string } | null
  country?: { name: string } | null
}
