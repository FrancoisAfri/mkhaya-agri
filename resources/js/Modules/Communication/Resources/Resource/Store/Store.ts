import { defineStore } from 'pinia'
import { router }      from '@inertiajs/vue3'
import type { Resource } from './type'

export function createResourceStore(storeId: string) {
  return defineStore(storeId, {
    state: () => ({
      list:       [] as Resource[],
      selected:   null as Resource | null,
      editing:    null as Resource | null,
      isLoading:  false,
      snackbar:   false,
      snackMsg:   '',
      snackColor: 'success' as 'success' | 'error',
    }),

    getters: {
      totalActive:   (state) => state.list.filter(r => r.is_active).length,
      totalInternal: (state) => state.list.filter(r => r.internal_only).length,
      totalPublic:   (state) => state.list.filter(r => !r.internal_only).length,
    },

    actions: {
      setList(data: Resource[]) {
        this.list = data ?? []
        if (this.list.length > 0 && !this.selected) {
          this.selected = this.list[0]
        }
      },

      select(item: Resource)     { this.selected = item },
      startEdit(item: Resource)  { this.editing  = { ...item } },
      clearEdit()                { this.editing  = null },

      // ── CREATE ────────────────────────────────────────────────────────────
      store(form: FormData, cb?: { onSuccess?: () => void; onError?: () => void }) {
        this.isLoading = true
        router.post('/resources/store', form, {
          forceFormData:  true,
          preserveScroll: true,
          onSuccess: () => {
            this.showSnack('Resource created successfully', 'success')
            this.isLoading = false
            cb?.onSuccess?.()
          },
          onError: () => {
            this.showSnack('Failed to create resource', 'error')
            this.isLoading = false
            cb?.onError?.()
          },
        })
      },

      // ── UPDATE ────────────────────────────────────────────────────────────
      update(id: number, form: FormData, cb?: { onSuccess?: () => void; onError?: () => void }) {
        this.isLoading = true
        router.put(`/resources/update/${id}`, form, {
          forceFormData:  true,
          preserveScroll: true,
          onSuccess: () => {
            this.showSnack('Resource updated successfully', 'success')
            this.isLoading = false
            this.editing = null
            cb?.onSuccess?.()
          },
          onError: () => {
            this.showSnack('Failed to update resource', 'error')
            this.isLoading = false
            cb?.onError?.()
          },
        })
      },

      // ── DELETE ────────────────────────────────────────────────────────────
      remove(id: number) {
        router.delete(`/resources/delete/${id}`, {
          preserveScroll: true,
          onSuccess: () => {
            this.list = this.list.filter(r => r.id !== id)
            if (this.selected?.id === id) this.selected = this.list[0] ?? null
            if (this.editing?.id  === id) this.editing  = null
            this.showSnack('Resource deleted', 'success')
          },
          onError: () => this.showSnack('Failed to delete resource', 'error'),
        })
      },

      showSnack(msg: string, color: 'success' | 'error' = 'success') {
        this.snackMsg   = msg
        this.snackColor = color
        this.snackbar   = true
        setTimeout(() => { this.snackbar = false }, 3500)
      },
    },
  })
}
