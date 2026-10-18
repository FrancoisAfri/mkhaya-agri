<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Violation { id: number; uuid: string; type: string; description: string; severity: string; policy_rule: string; status: string; employee: string; loan_ref: string; advance_ref: string; resolved_by: string; detected_at: string; resolved_at: string }
interface Page<T> { data: T[]; total: number; current_page: number; per_page: number; last_page: number }

const props = defineProps<{ violations: Page<Violation>; filters: Record<string, string> }>()

const statusFilter   = ref(props.filters.status ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const resolveDialog = ref(false)
const selected      = ref<Violation | null>(null)
const resolveForm   = useForm({ resolution_notes: '' })

watch([statusFilter, severityFilter], () => {
  router.get(route('staff-loans.settings.violations'), { status: statusFilter.value, severity: severityFilter.value }, { preserveState: true, replace: true })
})

function openResolve(violation: Violation) { selected.value = violation; resolveDialog.value = true }

const sevColor = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' }[s] ?? 'grey')
const stColor  = (s: string) => ({ open: 'error', resolved: 'success', escalated: 'warning' }[s] ?? 'grey')

const headers =computed(()=> [
  { title: t('staff_loans_violations.table.employee'), key: 'employee' },
  { title: t('staff_loans_violations.table.type'), key: 'type' },
  { title: t('staff_loans_violations.table.description'), key: 'description' },
  { title: t('staff_loans_violations.table.reference'), key: 'ref' },
  { title: t('staff_loans_violations.table.severity'), key: 'severity' },
  { title: t('staff_loans_violations.table.status'), key: 'status' },
  { title: t('staff_loans_violations.table.detected'), key: 'detected_at' },
  { title: '', key: 'actions', sortable: false },
])

const statusItems =  computed(()=>[
  { title: t('staff_loans_violations.filters.statuses.open'), value: 'open' },
  { title: t('staff_loans_violations.filters.statuses.resolved'), value: 'resolved' },
  { title: t('staff_loans_violations.filters.statuses.escalated'), value: 'escalated' },
])

const severityItems = computed(()=> [
  { title: t('staff_loans_violations.filters.severities.low'), value: 'low' },
  { title: t('staff_loans_violations.filters.severities.medium'), value: 'medium' },
  { title: t('staff_loans_violations.filters.severities.high'), value: 'high' },
  { title: t('staff_loans_violations.filters.severities.critical'), value: 'critical' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <h1 class="text-h5 font-weight-bold">{{ t('staff_loans_violations.page_title') }}</h1>
      <VBtn variant="outlined" :href="route('staff-loans.settings.index')">{{ t('staff_loans_violations.back_to_settings') }}</VBtn>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="6" sm="3">
            <VSelect
              v-model="statusFilter"
              :label="t('staff_loans_violations.filters.status_label')"
              :items="statusItems"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </VCol>
          <VCol cols="6" sm="3">
            <VSelect
              v-model="severityFilter"
              :label="t('staff_loans_violations.filters.severity_label')"
              :items="severityItems"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VDataTableServer
        :items="violations.data"
        :items-length="violations.total"
        :headers="headers"
        hide-default-footer
      >
        <template #item.type="{ item }">{{ item.type.replace(/_/g, ' ') }}</template>
        <template #item.ref="{ item }">{{ item.loan_ref || item.advance_ref || '—' }}</template>
        <template #item.severity="{ item }">
          <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip size="x-small" :color="stColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>
        <template #item.detected_at="{ item }">{{ new Date(item.detected_at).toLocaleDateString() }}</template>
        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="x-small" variant="text" color="success" @click="openResolve(item)">
            {{ t('staff_loans_violations.resolve') }}
          </VBtn>
        </template>
      </VDataTableServer>
    </VCard>

    <VDialog v-model="resolveDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_loans_violations.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-3">{{ selected?.description }}</p>
          <VTextarea
            v-model="resolveForm.resolution_notes"
            :label="t('staff_loans_violations.dialog.resolution_notes')"
            variant="outlined"
            density="compact"
            rows="3"
            :error-messages="resolveForm.errors.resolution_notes"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('staff_loans_violations.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing"
            @click="resolveForm.post(route('staff-loans.settings.violations.resolve', selected!.id), { onSuccess: () => resolveDialog = false })">
            {{ t('staff_loans_violations.dialog.resolve_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
