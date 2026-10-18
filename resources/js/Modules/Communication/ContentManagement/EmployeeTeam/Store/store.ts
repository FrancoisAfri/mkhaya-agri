/**
 * Store for Employee & Team Spotlight
 */
import { defineStore } from 'pinia'
import { router }      from '@inertiajs/vue3'
import type { EmployeeSpotlight, EmployeeSpotlightType } from './type'

export const useSpotlightStore = defineStore('spotlight', {
  state: () => ({
    list:       [] as EmployeeSpotlight[],
    selected:   null as EmployeeSpotlight | null,
    editing:    null as EmployeeSpotlight | null,
    isLoading:  false,
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error' | 'warning',
  }),

  getters: {
    byType: (state) => (type: EmployeeSpotlightType) =>
      state.list.filter(s => s.type === type),

    awards:        (state) => state.list.filter(s => s.type === 1),
    anniversaries: (state) => state.list.filter(s => s.type === 2),
    competitions:  (state) => state.list.filter(s => s.type === 3),
  },

  actions: {
    setList(data: EmployeeSpotlight[] | null) {
      this.list = data ?? []
      if (this.list.length > 0 && !this.selected) {
        this.selected = this.list[0]
      }
    },

    select(item: EmployeeSpotlight) {
      this.selected = item
    },

    startEdit(item: EmployeeSpotlight) {
      this.editing = { ...item }
    },

    cancelEdit() {
      this.editing = null
    },

    // ── Créer ─────────────────────────────────────────────────────────────────
    store(
      form: FormData,
      callbacks?: { onSuccess?: () => void; onError?: () => void },
      routeName = 'employee-team.store',
    ) {
      this.isLoading = true
      router.post(route(routeName), form, {
        forceFormData:  true,
        preserveScroll: true,
        onSuccess: () => {
          this.showSnack('Spotlight created successfully', 'success')
          this.isLoading = false
          callbacks?.onSuccess?.()
        },
        onError: () => {
          this.showSnack('Failed to create spotlight', 'error')
          this.isLoading = false
          callbacks?.onError?.()
        },
      })
    },

    // ──Updated ─────────────────────────────────────────────────────────
update(
  id: number,
  form: FormData,
  callbacks?: { onSuccess?: () => void; onError?: () => void },
  routeName = 'employee-team.update',
) {
  this.isLoading = true
  // Changer post en put
  router.put(route(routeName, id), form, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      this.showSnack('Spotlight updated successfully', 'success')
      this.isLoading = false
      this.editing = null
      callbacks?.onSuccess?.()
    },
    onError: () => {
      this.showSnack('Failed to update spotlight', 'error')
      this.isLoading = false
      callbacks?.onError?.()
    },
  })
},

    // ── Supprimer ─────────────────────────────────────────────────────────────
    remove(id: number, routeName = 'spotlight.destroy') {
      router.delete(route(routeName, id), {
        preserveScroll: true,
        onSuccess: () => {
          this.list = this.list.filter(s => s.id !== id)
          if (this.selected?.id === id) this.selected = this.list[0] ?? null
          if (this.editing?.id  === id) this.editing  = null
          this.showSnack('Spotlight deleted successfully', 'success')
        },
        onError: () => this.showSnack('Failed to delete spotlight', 'error'),
      })
    },

    patchLocal(updated: EmployeeSpotlight) {
      const idx = this.list.findIndex(s => s.id === updated.id)
      if (idx !== -1) this.list[idx] = updated
      if (this.selected?.id === updated.id) this.selected = updated
    },

    addLocal(item: EmployeeSpotlight) {
      this.list.unshift(item)
    },

    removeLocal(id: number) {
      this.list = this.list.filter(s => s.id !== id)
      if (this.selected?.id === id) this.selected = this.list[0] ?? null
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
      setTimeout(() => { this.snackbar = false }, 3500)
    },
  },
})
