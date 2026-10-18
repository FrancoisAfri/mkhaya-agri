// Store/store.ts
import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'

export interface JobCategory {
  id: number
  name: string
  description: string | null
  status: number
  positions?: HrPosition[]
}

export interface HrPosition {
  id: number
  name: string
  description: string | null
  status: number
  job_category_id: number
}

export const usePositionStore = defineStore('position', {
  state: () => ({
    categories: [] as JobCategory[],
    search: '',
    view: 'categories' as 'categories' | 'positions',
    activeCategory: null as JobCategory | null,
    positionSearch: '',
    isCatAddOpen: false,
    isCatEditOpen: false,
    isCatDeleteOpen: false,
    selectedCat: null as JobCategory | null,
    isPosAddOpen: false,
    isPosEditOpen: false,
    isPosDeleteOpen: false,
    selectedPos: null as HrPosition | null,
    snackbar: false,
    snackMsg: '',
    snackColor: 'success' as 'success' | 'error' | 'warning',
    loading: false,
      positionsVersion: 0,
  }),

  getters: {
    filteredCategories: (state) => {
      return state.categories.filter(c =>
        c.name.toLowerCase().includes(state.search.toLowerCase())
      )
    },
filteredPositions: (state) => {
  const _ = state.positionsVersion
  const positions = state.activeCategory?.positions ?? []
  return positions.filter(p =>
    p.name.toLowerCase().includes(state.positionSearch.toLowerCase())
  )
},

    activeCategoryName: (state) => state.activeCategory?.name ?? '',
  },

  actions: {
    setCategories(data: JobCategory[] | null) {
      this.categories = data ?? []
    },

    goToPositions(cat: JobCategory) {
      this.activeCategory = { ...cat, positions: [...(cat.positions || [])] }
      this.positionSearch = ''
      this.view = 'positions'
    },

    goToCategories() {
      this.view = 'categories'
      this.activeCategory = null
      this.positionSearch = ''
    },

    // ════════════════════════════
    // CATEGORY CRUD
    // ════════════════════════════
    openCatAdd() { this.isCatAddOpen = true },
    closeCatAdd() { this.isCatAddOpen = false },

    openCatEdit(cat: JobCategory) {
      this.selectedCat = cat
      this.isCatEditOpen = true
    },
    closeCatEdit() { this.isCatEditOpen = false; this.selectedCat = null },

    openCatDelete(cat: JobCategory) {
      this.selectedCat = cat
      this.isCatDeleteOpen = true
    },
    closeCatDelete() { this.isCatDeleteOpen = false; this.selectedCat = null },

    addCategory(cat: JobCategory) {
      this.categories = [{ ...cat, positions: [] }, ...this.categories]
      this.showSnack('Category created', 'success')
    },

    updateCategory(cat: JobCategory) {
      const index = this.categories.findIndex(c => c.id === cat.id)
      if (index !== -1) {
        const updatedCategories = [...this.categories]
        updatedCategories[index] = { ...updatedCategories[index], ...cat }
        this.categories = updatedCategories

        // Si c'est la catégorie active, mettre à jour aussi
        if (this.activeCategory?.id === cat.id) {
          this.activeCategory = { ...this.activeCategory, ...cat }
        }
      }
      this.showSnack('Category updated', 'success')
    },

    deleteCategory(id: number) {
      this.categories = this.categories.filter(c => c.id !== id)
      if (this.activeCategory?.id === id) {
        this.goToCategories()
      }
      this.showSnack('Category deleted', 'error')
    },

    openPosAdd() { this.isPosAddOpen = true },
    closePosAdd() { this.isPosAddOpen = false },

    openPosEdit(pos: HrPosition) {
      this.selectedPos = pos
      this.isPosEditOpen = true
    },
    closePosEdit() { this.isPosEditOpen = false; this.selectedPos = null },

    openPosDelete(pos: HrPosition) {
      this.selectedPos = pos
      this.isPosDeleteOpen = true
    },
    closePosDelete() { this.isPosDeleteOpen = false; this.selectedPos = null },

    addPosition(pos: HrPosition) {
      if (!this.activeCategory) return

      const currentPositions = this.activeCategory.positions || []
      const newPositions = [pos, ...currentPositions]

      this.activeCategory = {
        ...this.activeCategory,
        positions: newPositions
      }

      const catIndex = this.categories.findIndex(c => c.id === this.activeCategory!.id)
      if (catIndex !== -1) {
        const updatedCategories = [...this.categories]
        updatedCategories[catIndex] = {
          ...updatedCategories[catIndex],
          positions: newPositions
        }
        this.categories = updatedCategories
      }
        this.positionsVersion++

      this.showSnack('Position created', 'success')
    },

    updatePosition(pos: HrPosition) {
      if (!this.activeCategory?.positions) return

      const updatedPositions = this.activeCategory.positions.map(p =>
        p.id === pos.id ? pos : p
      )

      this.activeCategory = {
        ...this.activeCategory,
        positions: updatedPositions
      }

      const catIndex = this.categories.findIndex(c => c.id === this.activeCategory!.id)
      if (catIndex !== -1) {
        const updatedCategories = [...this.categories]
        updatedCategories[catIndex] = {
          ...updatedCategories[catIndex],
          positions: updatedPositions
        }
        this.categories = updatedCategories
      }

      this.showSnack('Position updated', 'success')
    },

    deletePosition(id: number) {
      if (!this.activeCategory?.positions) return

      const filteredPositions = this.activeCategory.positions.filter(p => p.id !== id)

      this.activeCategory = {
        ...this.activeCategory,
        positions: filteredPositions
      }

      const catIndex = this.categories.findIndex(c => c.id === this.activeCategory!.id)
      if (catIndex !== -1) {
        const updatedCategories = [...this.categories]
        updatedCategories[catIndex] = {
          ...updatedCategories[catIndex],
          positions: filteredPositions
        }
        this.categories = updatedCategories
      }

      this.showSnack('Position deleted', 'error')
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg = msg
      this.snackColor = color
      this.snackbar = true
    },
  },
})
