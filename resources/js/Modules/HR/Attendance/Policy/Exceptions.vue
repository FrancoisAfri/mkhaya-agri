<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type PolicyException = {
  id: number
  employee: string
  policy_rule: string | null
  reason: string
  granted_by: string | null
  approved_at: string
  expires_at: string | null
  is_expired: boolean
  notes: string | null
}

defineProps<{
  exceptions: { data: PolicyException[]; links: any[]; meta: any }
}>()

const headers = [
  { title: 'Employee',   key: 'employee',   sortable: false },
  { title: 'Policy Rule',key: 'policy_rule',sortable: false },
  { title: 'Reason',     key: 'reason',     sortable: false },
  { title: 'Granted By', key: 'granted_by', sortable: false },
  { title: 'Approved',   key: 'approved_at',sortable: false },
  { title: 'Expires',    key: 'expires_at', sortable: false },
  { title: 'Status',     key: 'is_expired', sortable: false },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.policy.index'))" />
      <div>
        <h4 class="text-h4">Policy Exceptions</h4>
        <p class="text-medium-emphasis mb-0">Approved exceptions to attendance policy rules</p>
      </div>
    </div>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="exceptions.data" hide-default-footer hover :items-per-page="20">
        <template #item.reason="{ item }">
          <span class="text-body-2" style="max-width:220px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="item.reason">{{ item.reason }}</span>
        </template>
        <template #item.expires_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ item.expires_at ?? 'Permanent' }}</span>
        </template>
        <template #item.is_expired="{ item }">
          <VChip :color="item.is_expired ? 'secondary' : 'success'" size="x-small" variant="tonal">{{ item.is_expired ? 'Expired' : 'Active' }}</VChip>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-clipboard-check" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No policy exceptions recorded.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
