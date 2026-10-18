import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'

export interface AuditLog {
  id:           number
  log_name:     string
  description:  string
  subject_type: string
  event:        string
  causer_id:    number | null
  created_at:   string
  firstname:    string | null
  lastname:     string | null
  properties:   {
    old?:        Record<string, unknown>
    attributes?: Record<string, unknown>
    [key: string]: unknown
  } | null
}

export interface AuditMeta {
  total:        number
  per_page:     number
  current_page: number
  last_page:    number
}

export interface ChartData {
  labels: string[]
  counts: number[]
}

export interface AuditStats {
  total:   number
  created: number
  updated: number
  deleted: number
}

export const useAuditStore = defineStore('audit', {
  state: () => ({
    logs:       [] as AuditLog[],
    meta:       null as AuditMeta | null,
    chartData:  null as ChartData | null,
    stats:      null as AuditStats | null,
    loading:    false,
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error',

    selectedLog: null as AuditLog | null,

    filters: {
      log_name:   '',
      event:      '',
      user:       '',
      date_from:  '',
      date_to:    '',
      page:       1,
    },
  }),

  actions: {
    setLogs(logs: AuditLog[], meta: AuditMeta) {
      this.logs = logs
      this.meta = meta
    },

    setChartData(data: ChartData) {
      this.chartData = data
    },

    setStats(stats: AuditStats) {
      this.stats = stats
    },

    setFilter(key: keyof typeof this.filters, value: string | number) {
      (this.filters as any)[key] = value
      if (key !== 'page') this.filters.page = 1
      this.reload()
    },

    resetFilters() {
      this.filters = { log_name: '', event: '', user: '', date_from: '', date_to: '', page: 1 }
      this.reload()
    },

    reload() {
      router.get('/settings/audits', this.filters as any, {
        preserveState:  true,
        preserveScroll: true,
        replace:        true,
      })
    },

    showSnack(msg: string, color: 'success' | 'error' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },
  },
})
