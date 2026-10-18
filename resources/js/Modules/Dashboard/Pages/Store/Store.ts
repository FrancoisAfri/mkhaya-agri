import { defineStore } from 'pinia'
import type { Content, EmployeeSpotlight } from './type'

export const useDashboardStore = defineStore('dashboard', {
  state: () => ({
    news:            [] as Content[],
    events:          [] as Content[],
    announcements:   [] as Content[],
    internalNotices: [] as Content[],
    spotlights:      [] as EmployeeSpotlight[],
    ceoMessage:      null as any,
  }),

  actions: {
    hydrate(data: {
      news?:            Content[]
      events?:          Content[]
      announcements?:   Content[]
      internalNotices?: Content[]
      spotlights?:      EmployeeSpotlight[]
      ceoMessage?:      any
    }) {
      this.news            = data.news            ?? []
      this.events          = data.events          ?? []
      this.announcements   = data.announcements   ?? []
      this.internalNotices = data.internalNotices ?? []
      this.spotlights      = data.spotlights      ?? []
      this.ceoMessage      = data.ceoMessage      ?? null
    },
  },
})
