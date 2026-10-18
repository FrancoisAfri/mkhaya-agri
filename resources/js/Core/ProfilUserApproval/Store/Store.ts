// Store.ts

import { defineStore } from 'pinia'
import { router } from '@inertiajs/vue3'
import type { ApprovalItem, HRPerson } from '../type/type'

export const useApprovalStore = defineStore('approval', {
  state: () => ({
    approvals: [] as ApprovalItem[],
    selected: null as ApprovalItem | null,
    hrPerson: null as HRPerson | null,
    search: '',
    loading: false,
    snackbar: false,
    snackMsg: '',
    snackColor: 'success' as 'success' | 'error' | 'warning',
  }),

  getters: {
    filtered: (state) =>
      state.approvals.filter((a) => {
        const q = state.search.toLowerCase()
        return (
          a.first_name.toLowerCase().includes(q) ||
          a.surname.toLowerCase().includes(q) ||
          (a.employee_number ?? '').toLowerCase().includes(q)
        )
      }),

    pendingCount: (state) =>
      state.approvals.filter((a) => !a.is_approved).length,
  },

  actions: {
    setApprovals(data: ApprovalItem[] | null) {
      this.approvals = data ?? []
    },

    setSelected(item: ApprovalItem | null) {
      this.selected = item
    },

    setHRPerson(person: HRPerson | null) {
      this.hrPerson = person
    },

    // ── Approve ──
    approve(id: number) {
      this.loading = true
      router.post(
        `/settings/approvals/${id}/approve`,
        {},
        {
          preserveScroll: true,
          onSuccess: () => {
            const item = this.approvals.find((a) => a.id === id)
            if (item) item.is_approved = 1
            if (this.selected && this.selected.id === id) {
              this.selected.is_approved = 1
            }
            this.showSnack('Changes approved successfully', 'success')
          },
          onError: () => this.showSnack('Failed to approve', 'error'),
          onFinish: () => {
            this.loading = false
          },
        },
      )
    },

    // ── Reject ──
    reject(id: number, reason: string) {
      this.loading = true
      router.post(
        `/settings/approvals/${id}/reject`,
        { reason },
        {
          preserveScroll: true,
          onSuccess: () => {
            this.approvals = this.approvals.filter((a) => a.id !== id)
            this.selected = null
            this.hrPerson = null
            this.showSnack('Changes rejected successfully', 'warning')
          },
          onError: (errors) => {
            console.error('Rejection error:', errors)
            this.showSnack('Failed to reject changes', 'error')
          },
          onFinish: () => {
            this.loading = false
          },
        },
      )
    },

    showSnack(msg: string, color: 'success' | 'error' | 'warning' = 'success') {
      this.snackMsg = msg
      this.snackColor = color
      this.snackbar = true
    },
  },
})
