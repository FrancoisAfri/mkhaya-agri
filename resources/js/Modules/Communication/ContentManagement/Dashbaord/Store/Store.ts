import { defineStore } from 'pinia'

// ── Types ──────────────────────────────────────────────────────────────────────
export interface ContentStat {
  type:       number
  label:      string
  total:      number
  active:     number
  inactive:   number
  this_month: number
}

export interface SpotlightStat {
  type:       number
  label:      string
  total:      number
  this_month: number
}

export interface MonthlyTrend {
  month: string
  news:            number
  events:          number
  announcements:   number
  internal_notices:number
  spotlights:      number
}

export interface TopAuthor {
  name:  string
  count: number
}

export interface CeoMessageStat {
  total:        number
  active:       number
  last_updated: string | null
}

export interface CommunicationDashboardData {
  contentStats:    ContentStat[]
  spotlightStats:  SpotlightStat[]
  monthlyTrends:   MonthlyTrend[]
  topAuthors:      TopAuthor[]
  ceoMessageStat:  CeoMessageStat
}

export const useCommunicationDashboardStore = defineStore('communicationDashboard', {
  state: (): CommunicationDashboardData => ({
    contentStats:   [],
    spotlightStats: [],
    monthlyTrends:  [],
    topAuthors:     [],
    ceoMessageStat: { total: 0, active: 0, last_updated: null },
  }),

  getters: {
    totalContent: (state) =>
      state.contentStats.reduce((s, c) => s + c.total, 0),

    totalActive: (state) =>
      state.contentStats.reduce((s, c) => s + c.active, 0),

    totalSpotlights: (state) =>
      state.spotlightStats.reduce((s, c) => s + c.total, 0),

    totalThisMonth: (state) =>
      state.contentStats.reduce((s, c) => s + c.this_month, 0) +
      state.spotlightStats.reduce((s, c) => s + c.this_month, 0),
  },

  actions: {
    hydrate(data: CommunicationDashboardData) {
      this.contentStats   = data.contentStats   ?? []
      this.spotlightStats = data.spotlightStats ?? []
      this.monthlyTrends  = data.monthlyTrends  ?? []
      this.topAuthors     = data.topAuthors     ?? []
      this.ceoMessageStat = data.ceoMessageStat ?? { total: 0, active: 0, last_updated: null }
    },
  },
})
