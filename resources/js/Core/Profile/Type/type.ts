// types/profile.ts

export interface PositionData {
    id: number;
    name: string;
    description?: string;
    status?: number;
    job_category_id?: number;
}

export interface ManagerData {
    id: number;
    first_name: string;
    surname: string;
    full_name?: string;
    email?: string;
}

export interface CompanyUnitData {
    id: number;
    name: string;
    type: string;
    parent_id?: number | null;
}

export interface RoleData {
    id?: number;
    name: string;
}
interface LeaveProfileData {
    id: number;
    name: string;
}

export interface EmployeeDocumentData {
    id: number;
    doc_description: string | null;
    category_id: number | null;
    division_level_2: number | null;
    division_level_1: number | null;
    hr_person_id: number | null;
    date_from: string | null;
    expirydate: string | null;
    supporting_docs: string | null;
    doc_type_id: number | null;
    qualification_type_id: number | null;
     hr_change_id: number | null;
}

export interface HRPersonData {
    id: number;

    // ── Personal Information ──
    title: number | null;
    uuid: string | null;
    first_name: string | null;
    surname: string | null;
    middle_name: string | null;
    maiden_name: string | null;
    aka: string | null;
    initial: string | null;
    known_as: string | null;
    gender: number | null;
    date_of_birth: string | null;
    id_number: string | null;
    passport_number: string | null;
    marital_status: number | null;
    ethnicity: number | null;
    citizenship: number | null;

    // ── Contact Information ──
    email: string | null;
    cell_number: string | null;
    phone_number: string | null;
    alternate_number: string | null;

    // ── Residential Address ──
    res_address: string | null;
    res_suburb: string | null;
    res_city: string | null;
    res_postal_code: number | null;
    res_province_id: number | null;
    res_country_id: number | null;

    // ── Emergency Contact ──
    next_of_kin: string | null;
    next_of_kin_number: string | null;
    next_of_kin_work_number: string | null;

    // ── Tax Information ──
    income_tax_number: string | null;
    tax_office: string | null;

    // ── Banking Details ──
    account_type: string | null;
    account_holder_name: string | null;
    bank_name: string | null;
    branch_name: string | null;
    account_number: string | null;

    // ── Medical Aid ──
    med_start_date: string | null;
    med_split: string | null;
    med_plan_name: string | null;
    med_dep_spouse: string | null;
    med_dep_adult: string | null;
    med_dep_kids: string | null;
    med_amount: number | null;
    package_med_aid: number | null;

    // ── Provident Fund ──
    provident_start_date: string | null;
    provident_name: string | null;
    provident_amount: number | null;
    provident_fund_product: string | null;

    // ── Work Details ──
    employee_number: string | null;
    position_id: number | null;
    position: number | null;
    date_joined: string | null;
    date_left: string | null;
    start_time: string | null;
    end_time: string | null;
    leave_profile: number | null;
    role_id: number | null;
    manager_id: number | null;
    second_manager_id: number | null;
    division_level_1: number | null;
    division_level_2: number | null;
    division_level_3: number | null;
    division_level_4: number | null;
    division_level_5: number | null;
    complex_name: string | null;
    unit_number: string | null;

    // ── Package Information ──
    package_components: string | null;
    monthly_package: number | null;
    package_cash: number | null;
    package_travel: number | null;
    package_incentive: number | null;
    package_cell_phone: number | null;

    // ── Disability & Employment ──
    disabled: number | null;
    nature_of_disability: string | null;
    employment_type: number | null;
    occupational_level: number | null;
    job_function: number | null;

    // ── Driving ──
    can_drive: number | null;
    drivers_licence_number: string | null;
    drivers_licence_code: string | null;
    drivers_licence_exp_date: string | null;
    proof_drive_permit: string | null;
    proof_drive_permit_exp_date: string | null;
    proof_drive_permit_url: string | null;
    proof_drive_permit_mime: string | null;
    proof_drive_permit_original_name: string | null;
    own_transport: number | null;

    // ── Profile ──
    profile_pic: string | null;
    profile_pic_url: string | null;
    status: number | null;

    // ── Relations ──
    hr_position?: PositionData | null;
    manager?: ManagerData | null;
    leaveProfile?: LeaveProfileData | null;
    province?: { id: number; name: string } | null;
    country?: { id: number; name: string } | null;
    documents?: EmployeeDocumentData[] | null;
}

export interface ProfileUser {
    id: number;
    uuid: string;
    email: string;
    name: string;
    type: number;
    status: number;
    phone_number: string | null;
    roles: RoleData[];
    person: HRPersonData | null;
    company_units?: CompanyUnitData[] | null;
    companies?: CompanyUnitData[] | null;
    created_at: string;
}

// ── Enums / Constant Types ──
export type ProfileTab =
    | "profile"
    | "work-details"
    | "personal-info"
    | "contact-details"
    | "emergency-contact"
    | "tax-banking"
    | "medical-aid"
    | "provident-fund"
    | "package-info"
    | "driving"
    | "documents";

export type Gender = 1 | 2;
export type Title = 1 | 2 | 3 | 4;
export type Disabled = 1 | 2;
export type EmploymentType = 1 | 2;
export type JobFunction = 1 | 2;
export type CanDrive = 1 | 2;

// ── Labels Maps ──
export const TITLE_LABELS: Record<number, string> = {
    1: "Mr",
    2: "Miss",
    3: "Ms",
    4: "Dr",
};

export const GENDER_LABELS: Record<number, string> = {
    1: "Male",
    2: "Female",
};

export const MARITAL_STATUS_LABELS: Record<number, string> = {
    1: "Single",
    2: "Married",
    3: "Divorced",
    4: "Widowed",
    5: "Separated",
    6: "In a relationship",
    7: "Co-habiting",
};

export const ETHNICITY_LABELS: Record<number, string> = {
    1: "African",
    2: "Coloured",
    3: "Indian/Asian",
    4: "White",
    5: "Other",
};

export const EMPLOYMENT_TYPE_LABELS: Record<number, string> = {
    1: "Permanent",
    2: "Temporary",
};

export const OCCUPATIONAL_LEVEL_LABELS: Record<number, string> = {
    1: "Senior Management",
    2: "Middle Management",
    3: "Junior Management",
    4: "Semi Skilled",
    5: "Unskilled",
    6: "Professional",
    7: "Skilled",
};

export const JOB_FUNCTION_LABELS: Record<number, string> = {
    1: "Core/Operational",
    2: "Support",
};

export const DISABLED_LABELS: Record<number, string> = {
    1: "Yes",
    2: "No",
};

export const CAN_DRIVE_LABELS: Record<number, string> = {
    1: "Yes",
    2: "No",
};
