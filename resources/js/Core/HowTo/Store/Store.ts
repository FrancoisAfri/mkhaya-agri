import { defineStore } from 'pinia'
import axios from 'axios'
import type {
  HowToProperties,
  RibbonProperties,
  GroupedHowTos,
} from './type'

interface HowToState {
  ribbons: RibbonProperties[]
  howtos: GroupedHowTos
  loading: boolean
  snackbar: boolean
  snackMsg: string
  snackColor: 'success' | 'error' | 'warning'
  _interval: ReturnType<typeof setInterval> | null
}

export const useHowToStore = defineStore('howTo', {
  state: (): HowToState => ({
    ribbons: [],
    howtos: {},
    loading: false,
    snackbar: false,
    snackMsg: '',
    snackColor: 'success',
    _interval: null,
  }),

  getters: {
    /**
     * Returns how-tos for a given ribbon, empty array if none
     */
    getHowTosForRibbon:
      (state) =>
      (ribbonId: number): HowToProperties[] =>
        state.howtos[ribbonId] ?? [],

    /**
     * Total how-to count across all ribbons
     */
    totalCount: (state): number =>
      Object.values(state.howtos).reduce((sum, arr) => sum + arr.length, 0),
  },

  actions: {
    // ── Hydration from API ──────────────────────────────────────────────────

    async fetchByModule(moduleId: number): Promise<void> {
      this.loading = true
      try {
        const { data } = await axios.get<{
          ribbons: RibbonProperties[]
          howtos: GroupedHowTos
        }>(`/how-to/${moduleId}`)

        this.ribbons = data.ribbons
        this.howtos = data.howtos
      } catch {
        this.showSnack('Failed to load data', 'error')
      } finally {
        this.loading = false
      }
    },

    // ── Create ──────────────────────────────────────────────────────────────

    addHowTo(item: HowToProperties): void {
      if (!this.howtos[item.ribbon_id]) {
        this.howtos[item.ribbon_id] = []
      }
      this.howtos[item.ribbon_id].push(item)
      this.showSnack(`How-to added successfully`, 'success')
    },

    // ── Update ──────────────────────────────────────────────────────────────

    updateHowTo(updated: HowToProperties): void {
      const list = this.howtos[updated.ribbon_id]
      if (!list) return
      const idx = list.findIndex((h) => h.id === updated.id)
      if (idx !== -1) list[idx] = updated
      this.showSnack(`How-to updated successfully`, 'success')
    },

    // ── Delete ──────────────────────────────────────────────────────────────

    removeHowTo(id: number, ribbonId: number): void {
      const list = this.howtos[ribbonId]
      if (!list) return
      this.howtos[ribbonId] = list.filter((h) => h.id !== id)
      this.showSnack(`How-to deleted`, 'error')
    },

    // ── Toggle ──────────────────────────────────────────────────────────────

    toggleHowTo(updated: HowToProperties): void {
      const list = this.howtos[updated.ribbon_id]
      if (!list) return
      const idx = list.findIndex((h) => h.id === updated.id)
      if (idx !== -1) list[idx] = updated
      this.showSnack(
        `How-to ${updated.active ? 'activated' : 'deactivated'}`,
        updated.active ? 'success' : 'warning',
      )
    },

    // ── Optimistic helpers for immediate UI response ────────────────────────

    optimisticAdd(item: HowToProperties): void {
      this.addHowTo(item)
    },

    optimisticRemove(id: number, ribbonId: number): void {
      this.removeHowTo(id, ribbonId)
    },

    // ── Snackbar ────────────────────────────────────────────────────────────

    showSnack(
      msg: string,
      color: 'success' | 'error' | 'warning' = 'success',
    ): void {
      this.snackMsg = msg
      this.snackColor = color
      this.snackbar = true
    },

    // ── Polling ─────────────────────────────────────────────────────────────

    startPolling(moduleId: number, seconds = 30): void {
      this.stopPolling()
      this._interval = setInterval(() => {
        this.fetchByModule(moduleId)
      }, seconds * 1000)
    },

    stopPolling(): void {
      if (this._interval) {
        clearInterval(this._interval)
        this._interval = null
      }
    },
  },
})
