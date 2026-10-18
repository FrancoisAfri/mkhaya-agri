export interface UserProperties {
  id: number;
  email: string;
  roles: { id: number; name: string }[];
  status: number;
  person?: {
    first_name: string | null;
    uuid:string
    surname: string | null;
    initial?: string | null;
    employee_number: string | null;
    date_joined?: string | null;
    date_left?: string | null;
    leave_profile?: string | null;
    position: number | null;
    division?: string | null;
    department?: string | null;
    occupational_level?: number | null;
    job_function?: number | null;
    nature_of_disability?: string | null;
    disabled?: boolean | null;
    gender: number | null;
  } | null;
}
