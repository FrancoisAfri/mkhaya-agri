<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Log = {
  id: number; action: string; auditable_type: string | null; auditable_id: number | null
  employee_id: number | null; performed_by: number | null; performed_by_name: string | null
  old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null
  ip_address: string | null; notes: string | null; created_at: string
}

type Paginated<T> = {
  data: T[]; current_page: number; last_page: number; per_page: number; total: number
  links: { url: string | null; label: string; active: boolean }[]
}

const props = defineProps<{
  logs: Paginated<Log>
  actionCounts: Record<string, number>
  actions: string[]
}>()

const subNav           = ref('audit')
const filterAction     = ref('')
const filterEmployeeId = ref('')
const filterFrom       = ref('')
const filterTo         = ref('')
const expandedLog      = ref<number | null>(null)

const actionColors: Record<string, string> = {
  clock_in: 'success', clock_out: 'info', manual_clock: 'warning', clock_edit: 'warning',
  clock_override: 'error', overtime_approved: 'purple', leave_applied: 'teal', leave_approved: 'teal',
  roster_created: 'secondary', roster_published: 'secondary', roster_assignment_changed: 'secondary',
  policy_violation_detected: 'error', policy_violation_resolved: 'success', policy_violation_escalated: 'warning',
  discipline_case_opened: 'error', discipline_case_resolved: 'success', settings_changed: 'grey', record_deleted: 'error',
}

const filterActionOptions = computed(() => [
  { title: t('attendance_audit.filter_all_actions'), value: '' },
  ...props.actions.map(a => ({ title: formatAction(a), value: a })),
])

function applyFilters() {
  router.get('/attendance/audit', {
    action: filterAction.value || undefined, employee_id: filterEmployeeId.value || undefined,
    from: filterFrom.value || undefined, to: filterTo.value || undefined,
  }, { preserveScroll: true, preserveState: true })
}

function clearFilters() {
  filterAction.value = ''; filterEmployeeId.value = ''; filterFrom.value = ''; filterTo.value = ''
  router.get('/attendance/audit', {}, { preserveScroll: true })
}

function formatAction(a: string) { return a.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }
function toggleExpand(id: number) { expandedLog.value = expandedLog.value === id ? null : id }

const headers = computed(() => [
  { title: t('attendance_audit.table_time'), key: 'created_at', width: '160px' },
  { title: t('attendance_audit.table_action'), key: 'action' },
  { title: t('attendance_audit.table_employee_id'), key: 'employee_id' },
  { title: t('attendance_audit.table_performed_by'), key: 'performed_by_name' },
  { title: t('attendance_audit.table_ip'), key: 'ip_address' },
  { title: t('attendance_audit.table_notes'), key: 'notes' },
  { title: '', key: 'expand', sortable: false, width: '40px' },
])
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="audit" href="/attendance/audit"><VIcon start size="16" icon="tabler-clipboard-list" />{{ t('attendance_audit.tab_audit_logs') }}</VTab>
            <VTab value="devices" href="/attendance/clocking/device-logs"><VIcon start size="16" icon="tabler-devices" />{{ t('attendance_audit.tab_device_logs') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_audit.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_audit.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_audit.subtitle') }}</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <div class="d-flex flex-wrap gap-2">
          <VChip v-for="(count, action) in actionCounts" :key="action" :color="actionColors[action] ?? 'default'" variant="tonal" size="small" @click="filterAction = action; applyFilters()">{{ formatAction(action) }} ({{ count }})</VChip>
        </div>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText>
            <VRow dense align="center">
              <VCol cols="12" md="3">
                <VSelect v-model="filterAction" :items="filterActionOptions" :label="t('attendance_audit.filter_action')" density="compact" hide-details />
              </VCol>
              <VCol cols="12" md="2"><VTextField v-model="filterEmployeeId" :label="t('attendance_audit.filter_employee_id')" type="number" density="compact" hide-details /></VCol>
              <VCol cols="12" md="2"><VTextField v-model="filterFrom" :label="t('attendance_audit.filter_from')" type="date" density="compact" hide-details /></VCol>
              <VCol cols="12" md="2"><VTextField v-model="filterTo" :label="t('attendance_audit.filter_to')" type="date" density="compact" hide-details /></VCol>
              <VCol cols="auto" class="d-flex gap-2">
                <VBtn color="primary" density="compact" @click="applyFilters"><i class="fas fa-search me-1"></i>{{ t('attendance_audit.filter_btn') }}</VBtn>
                <VBtn variant="text" density="compact" @click="clearFilters">{{ t('attendance_audit.clear') }}</VBtn>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_audit.table_time') }}</th>
                <th>{{ t('attendance_audit.table_action') }}</th>
                <th>{{ t('attendance_audit.table_employee_id') }}</th>
                <th>{{ t('attendance_audit.table_performed_by') }}</th>
                <th>{{ t('attendance_audit.table_ip') }}</th>
                <th>{{ t('attendance_audit.table_notes') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="log in logs.data" :key="log.id">
                <tr>
                  <td class="text-caption text-medium-emphasis">{{ log.created_at }}</td>
                  <td><VChip :color="actionColors[log.action] ?? 'default'" size="small" variant="tonal">{{ formatAction(log.action) }}</VChip></td>
                  <td>{{ log.employee_id ?? '—' }}</td>
                  <td>{{ log.performed_by_name ?? '—' }}</td>
                  <td class="text-caption">{{ log.ip_address ?? '—' }}</td>
                  <td class="text-caption">{{ log.notes ?? '—' }}</td>
                  <td><VBtn v-if="log.old_values || log.new_values" icon size="x-small" variant="text" @click="toggleExpand(log.id)"><i :class="expandedLog === log.id ? 'fas fa-chevron-up' : 'fas fa-chevron-down'"></i></VBtn></td>
                </tr>
                <tr v-if="expandedLog === log.id">
                  <td colspan="7" class="pa-3 bg-surface-variant">
                    <VRow dense>
                      <VCol cols="12" md="6" v-if="log.old_values"><p class="text-caption font-weight-medium text-medium-emphasis mb-1">{{ t('attendance_audit.before') }}</p><pre class="text-caption" style="white-space:pre-wrap">{{ JSON.stringify(log.old_values, null, 2) }}</pre></VCol>
                      <VCol cols="12" md="6" v-if="log.new_values"><p class="text-caption font-weight-medium text-medium-emphasis mb-1">{{ t('attendance_audit.after') }}</p><pre class="text-caption" style="white-space:pre-wrap">{{ JSON.stringify(log.new_values, null, 2) }}</pre></VCol>
                    </VRow>
                  </td>
                </tr>
              </template>
              <tr v-if="logs.data.length === 0"><td colspan="7" class="text-center py-8 text-medium-emphasis">{{ t('attendance_audit.no_logs') }}</td></tr>
            </tbody>
          </VTable>
          <VCardText v-if="logs.last_page > 1" class="d-flex justify-center pt-0">
            <VPagination :model-value="logs.current_page" :length="logs.last_page" @update:model-value="p => router.get('/attendance/audit', { page: p }, { preserveScroll: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
