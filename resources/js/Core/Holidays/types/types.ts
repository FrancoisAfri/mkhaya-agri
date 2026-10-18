export interface HolidayProperties {
  id:         number
  day:        string        // MM-DD format e.g. "12-25"
  name:       string
  year:       number | null // null = recurring
  country_id: number
  start?:      string        // YYYY-MM-DD for FullCalendar
}

export interface HolidayEvent {
  id:              string
  title:           string
  start:           string
  allDay:          true
  backgroundColor: string
  borderColor:     string
  textColor:       string
  extendedProps: {
    raw:        HolidayProperties
    type:       'recurring' | 'specific'
    country_id: number
  }
}

export interface BlankHolidayForm {
  id?:        number
  name:       string
  isRecurring: boolean
  year:       number | null
  month:      string
  day:        string
  country_id: number | null
}
