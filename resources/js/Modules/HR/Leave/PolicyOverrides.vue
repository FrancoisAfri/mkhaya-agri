<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Override = {
  id: number
  leave_request_id: number
  request_number: string
  employee_name: string
  leave_type: string
  override_by: string
  override_reason: string
  policy_rule: string
  created_at: string
}

const props = defineProps<{
  overrides: { data: Override[]; links: any[]; meta: any }
}>()

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const headers = [
  { title: 'Reference',     key: 'request_number', sortable: false },
  { title: 'Employee',      key: 'employee_name',  sortable: false },
  { title: 'Leave Type',    key: 'leave_type',     sortable: false },
  { title: 'Policy Rule',   key: 'policy_rule',    sortable: false },
  { title: 'Overridden By', key: 'override_by',    sortable: false },
  { title: 'Reason',        key: 'override_reason',sortable: false },
  { title: 'Date',          key: 'created_at',     sortable: false },
  { title: '',              key: 'actions',        sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
      <div>
        <h4 class="text-h4">Policy Overrides</h4>
        <p class="text-medium-emphasis mb-0">Audit log of approved policy exceptions</p>
      </div>
    </div>

    <VAlert type="warning" variant="tonal" class="mb-5">
      Policy overrides bypass standard leave rules. All overrides are permanently logged for compliance audits.
    </VAlert>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="overrides.data" hide-default-footer hover :items-per-page="25">
        <template #item.policy_rule="{ item }">
          <VChip color="warning" size="x-small" variant="tonal" class="text-capitalize">
            {{ item.policy_rule.replace('_', ' ') }}
          </VChip>
        </template>
        <template #item.override_reason="{ item }">
          <span class="text-body-2 text-medium-emphasis" style="max-width: 200px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ item.override_reason }}
          </span>
        </template>
        <template #item.created_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-eye"
            size="small"
            variant="text"
            color="primary"
            @click="router.visit(route('hr.leave.show', { leaveRequest: item.leave_request_id }))"
          />
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-shield-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No policy overrides on record.</p>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="overrides.meta?.last_page > 1" />
      <div v-if="overrides.meta?.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ overrides.meta.from }}–{{ overrides.meta.to }} of {{ overrides.meta.total }}</p>
        <div class="d-flex gap-1">
          <VBtn
            v-for="link in overrides.links"
            :key="link.label"
            :disabled="!link.url"
            :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'"
            size="small"
            @click="goToPage(link.url)"
            v-html="link.label"
          />
        </div>
      </div>
    </VCard>

  </VContainer>
</template>
