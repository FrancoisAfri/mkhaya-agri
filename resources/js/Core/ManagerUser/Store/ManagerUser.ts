import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'
import type { UserProperties } from '../types/type'

export const useManagerUserStore = defineStore('managerUser', {
  state: () => ({
    users:      [] as UserProperties[],
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error' | 'warning',
    _interval:  null as ReturnType<typeof setInterval> | null,

    // ✅ Import bulk
    isImportOpen:    false,
    importLoading:   false,
    importSuccess:   false,
    importError:     '',
  }),

  actions: {
    setUsers(data: UserProperties[] | null) {
      this.users = data ?? []
    },

    addUser(data: UserProperties) {
      this.users.unshift(data)
      this.showSnack('User added successfully', 'success')
    },

    updateUserStatus(id: number, status: number) {
      const user = this.users.find(u => u.id === id)
      if (user) user.status = status
    },

    deleteUser(id: number) {
      this.users = this.users.filter(u => u.id !== id)
      this.showSnack('User deleted successfully', 'error')
    },

    // ✅ Import helpers
    openImport()  {
      this.isImportOpen  = true
      this.importSuccess = false
      this.importError   = ''
    },
    closeImport() {
      this.isImportOpen  = false
      this.importSuccess = false
      this.importError   = ''
      this.importLoading = false
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },

    startPolling(seconds = 30) {
      this.stopPolling()
      this._interval = setInterval(() => {
        router.reload({ only: ['users'] })
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
