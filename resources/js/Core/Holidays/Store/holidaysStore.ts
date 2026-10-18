import { defineStore } from 'pinia'
import type { HolidayProperties, HolidayEvent } from '../types/types'

const RECURRING_COLOR = '#10b981' // emerald-500
const SPECIFIC_COLOR  = '#f59e0b' // amber-500

export const useHolidayStore = defineStore('holiday', {
  state: () => ({
    allHolidays:       [] as HolidayProperties[],
    selectedCountryId: null as number | null,
    selectedTypes:     ['recurring', 'specific'] as ('recurring' | 'specific')[],
    snackbar:          false,
    snackMsg:          '',
    snackColor:        'success' as 'success' | 'error',
  }),

  getters: {
    calendarEvents(state): HolidayEvent[] {
      return state.allHolidays
        .filter(h => {
          const matchCountry = !state.selectedCountryId || h.country_id === state.selectedCountryId
          const type         = h.year === null ? 'recurring' : 'specific'
          const matchType    = state.selectedTypes.includes(type)
          return matchCountry && matchType
        })
        .map(h => {
          const type  = h.year === null ? 'recurring' : 'specific'
          const color = type === 'recurring' ? RECURRING_COLOR : SPECIFIC_COLOR

          // For recurring, also project into current year (for accurate rendering)
          const currentYear = new Date().getFullYear()
          const start = h.year
            ? `${h.year}-${h.day}`
            : `${currentYear}-${h.day}`

          return {
            id:              String(h.id),
            title:           h.name,
            start,
            allDay:          true as true,
            backgroundColor: color,
            borderColor:     color,
            textColor:       '#ffffff',
            extendedProps: {
              raw:        h,
              type,
              country_id: h.country_id,
            },
          }
        })
    },
  },

  actions: {
    setHolidays(data: HolidayProperties[]) {
      this.allHolidays = data ?? []
    },

    addHoliday(h: HolidayProperties) {
      this.allHolidays.push(h)
      this.showSnack(`"${h.name}" added`, 'success')
    },

    updateHoliday(h: HolidayProperties) {
      const i = this.allHolidays.findIndex(x => x.id === h.id)
      if (i !== -1) this.allHolidays[i] = h
      this.showSnack(`"${h.name}" updated`, 'success')
    },

    removeHoliday(id: number) {
      const h = this.allHolidays.find(x => x.id === id)
      this.allHolidays = this.allHolidays.filter(x => x.id !== id)
      if (h) this.showSnack(`"${h.name}" deleted`, 'error')
    },

    showSnack(msg: string, color: 'success' | 'error' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },
  },
})
