import { defineStore } from 'pinia'
import type { RoleProperties } from '../types/type'

export const useRoleStore = defineStore('role', {
  state: () => ({
    roles:      [] as RoleProperties[],
    snackbar:   false,
    snackMsg:   '',
    snackColor: 'success' as 'success' | 'error' | 'warning',
  }),

  actions: {
    setRoles(data: RoleProperties[] | null) {
      this.roles = data ?? []
    },

    addRole(role: RoleProperties) {
      this.roles.unshift(role)
      this.showSnack('Role created successfully', 'success')
    },

    updateRole(role: RoleProperties) {
      const idx = this.roles.findIndex(r => r.id === role.id)
      if (idx !== -1) this.roles[idx] = role
      this.showSnack('Role updated successfully', 'success')
    },

    deleteRole(id: number) {
      this.roles = this.roles.filter(r => r.id !== id)
      this.showSnack('Role deleted successfully', 'error')
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg   = msg
      this.snackColor = color
      this.snackbar   = true
    },
  },
})
