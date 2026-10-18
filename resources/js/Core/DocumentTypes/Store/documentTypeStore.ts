import { defineStore } from 'pinia'
import { router }      from '@inertiajs/vue3'
import type { DocTypeProperties } from '../types/type'

export const useDocTypeStore = defineStore('docType', {
  state: () => ({
    items:      [] as DocTypeProperties[],
    search:     '',
    loading:    false,
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error' | 'warning',

    // Dialogs
    isAddOpen:    false,
    isEditOpen:   false,
    isDeleteOpen: false,
    selected:     null as DocTypeProperties | null,
  }),

  getters: {
    filtered: (state) =>
      state.items.filter(i =>
        i.name.toLowerCase().includes(state.search.toLowerCase()) ||
        (i.description ?? '').toLowerCase().includes(state.search.toLowerCase())
      ),

    activeCount:   (state) => state.items.filter(i => i.is_active).length,
    inactiveCount: (state) => state.items.filter(i => !i.is_active).length,
  },

  actions: {
    // ── Initialisation depuis les props Inertia ──
    setItems(data: DocTypeProperties[] | null) {
      this.items = data ?? []
    },

    // ── CRUD local ──
    addItem(item: DocTypeProperties) {
      this.items.unshift(item)
      this.showSnack('Document type created', 'success')
    },

    updateItem(item: DocTypeProperties) {
      const idx = this.items.findIndex(i => i.id === item.id)
      if (idx !== -1) this.items[idx] = item
      this.showSnack('Document type updated', 'success')
    },

    removeItem(id: number) {
      this.items = this.items.filter(i => i.id !== id)
      this.showSnack('Document type deleted', 'error')
    },

    toggleActive(id: number) {
      const item = this.items.find(i => i.id === id)
      if (item) {
        item.is_active = !item.is_active
        this.showSnack(
          item.is_active ? 'Document type activated' : 'Document type deactivated',
          item.is_active ? 'success' : 'warning'
        )
      }
    },

    // ── Dialog helpers ──
    openAdd()   { this.selected = null; this.isAddOpen    = true },
    closeAdd()  { this.isAddOpen    = false },

    openEdit(item: DocTypeProperties)   { this.selected = item; this.isEditOpen   = true },
    closeEdit() { this.isEditOpen   = false },

    openDelete(item: DocTypeProperties) { this.selected = item; this.isDeleteOpen = true },
    closeDelete() { this.isDeleteOpen = false; this.selected = null },

    // ── Snackbar ──
    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },

    // ── Polling ──
    reload() {
      router.reload({ only: ['docTypes'] })
    },
  },
})
