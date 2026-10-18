import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'
import type { ModuleProperties } from '../types/type'

export const useModuleStore = defineStore('module', {
  state: () => ({
    modules:    [] as ModuleProperties[],
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error',
    _interval:  null as ReturnType<typeof setInterval> | null,
  }),

  actions: {
    setModules(data: ModuleProperties[]) {
      this.modules = data
    },

    addModule(data: ModuleProperties) {
      this.showSnack(`"${data.name}" added successfully`, 'success')
    },

    deleteModule(id: number, name: string) {
      this.modules = this.modules.filter(m => m.id !== id)
      this.showSnack(`"${name}" deleted`, 'error')
    },

    updateModule(data: ModuleProperties) {
      const index = this.modules.findIndex(m => m.id === data.id)
      if (index !== -1) this.modules[index] = data
      this.showSnack(`"${data.name}" updated successfully`, 'success')
    },

    showSnack(msg: string, color: 'success' | 'error' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },

    startPolling(seconds = 10) {
      this.stopPolling()
      this._interval = setInterval(() => {
        router.reload({ only: ['modules'] })
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
