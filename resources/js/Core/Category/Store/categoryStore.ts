import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'
import type { CategoriesProperties } from '../types/type'

export const useCategoryStore = defineStore('category', {
  state: () => ({
    categories: [] as CategoriesProperties[],
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error',
    _interval:  null as ReturnType<typeof setInterval> | null,
  }),

  actions: {
    setCategories(data: CategoriesProperties[]) {
      this.categories = data
    },

    addCategory(data: CategoriesProperties) {
      this.showSnack(`"${data.name}" added successfully`, 'success')
    },

    deleteCategory(id: number, name: string) {
      this.categories = this.categories.filter(c => c.id !== id)
      this.showSnack(`"${name}" deleted`, 'error')
    },

    showSnack(msg: string, color: 'success' | 'error' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },
    updateCategory(data: CategoriesProperties) {
  const index = this.categories.findIndex(c => c.id === data.id)
  if (index !== -1) this.categories[index] = data
  this.showSnack(`"${data.name}" updated successfully`, 'success')
},

    startPolling(seconds = 10) {
      this.stopPolling()
      this._interval = setInterval(() => {
        router.reload({ only: ['categories'] })
      }, seconds * 1000)
    },

    stopPolling() {
      if (this._interval) {
        clearInterval(this._interval)
        this._interval = null
      }
    },
  },
})
