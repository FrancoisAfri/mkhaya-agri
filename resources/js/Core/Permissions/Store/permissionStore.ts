import { defineStore } from 'pinia'
import type { PermissionProperties } from '../types/type'

export const usePermissionStore = defineStore('permission', {
  state: () => ({
    permissions: [] as PermissionProperties[],
    snackbar:    false,
    snackMsg:    '',
    snackColor:  'success' as 'success' | 'error' | 'warning',
  }),

  actions: {
    setPermissions(data: PermissionProperties[] | null) {
      this.permissions = data ?? []
    },

    addPermission(p: PermissionProperties) {
      this.permissions.unshift(p)
      this.showSnack('Permission created successfully', 'success')
    },

    updatePermission(p: PermissionProperties) {
      const idx = this.permissions.findIndex(x => x.id === p.id)
      if (idx !== -1) this.permissions[idx] = p
      this.showSnack('Permission updated successfully', 'success')
    },

    deletePermission(id: number) {
      this.permissions = this.permissions.filter(p => p.id !== id)
      this.showSnack('Permission deleted', 'error')
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },
  },
})
