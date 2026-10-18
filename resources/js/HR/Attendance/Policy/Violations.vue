<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Violation = {
  id: number; employee: string; employee_id: number
  policy_rule: string; category: string; category_label: string
  severity: string; severity_color: string; action: string
  detected_value: string; threshold_value: string
  status: string; status_color: string; blocked: boolean
  ai_enforcement_decision: string | null
  period_start: string; period_end: string
  escalated_at: string | null; resolved_at: string | null
  resolution_notes: string | null; created_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  violations: Paginated<Violation>
  filters:    { status: string; category: string }
  categories: Record<string, string>
}>()

const subNav         = ref('violations')
const filterStatus   = ref(props.filters.status)
const filterCategory = ref(props.filters.category)
const expandedId     = ref<number | null>(null)
const aiLoadingId    = ref<number | null>(null)

const resolveDialog  = ref(false)
const resolveTarget  = ref<Violation | null>(null)
const exceptionDialog = ref(false)
const exceptionTarget = ref<Violation | null>(null)

const resolveForm   = useForm({ resolution_notes: '' })
const exceptionForm = useForm({ reason: '', expires_at: '', notes: '' })

const filterStatusOptions = computed(() => [
  { title: t('attendance_policy_pages.status_all'), value: 'all' },
  { title: t('attendance_policy_pages.status_open'), value: 'open' },
  { title: t('attendance_policy_pages.status_escalated'), value: 'escalated' },
  { title: t('attendance_policy_pages.status_resolved'), value: 'resolved' },
  { title: t('attendance_policy_pages.status_excepted'), value: 'excepted' },
])

const filterCategoryOptions = computed(() => [
  { title: t('attendance_policy_pages.filter_all_categories'), value: 'all' },
  ...Object.entries(props.categories).map(([v, t]) => ({ title: t, value: v })),
])

function applyFilter() {
  router.get('/attendance/policy/violations', {
    status:   filterStatus.value   !== 'all' ? filterStatus.value   : undefined,
    category: filterCategory.value !== 'all' ? filterCategory.value : undefined,
  }, { preserveState: true })
}

function openResolve(v: Violation) { resolveTarget.value = v; resolveForm.reset(); resolveDialog.value = true }
function submitResolve() { if (!resolveTarget.value) return; resolveForm.post(`/attendance/policy/violations/${resolveTarget.value.id}/resolve`, { onSuccess: () => { resolveDialog.value = false } }) }
function openException(v: Violation) { exceptionTarget.value = v; exceptionForm.reset(); exceptionDialog.value = true }
function submitException() { if (!exceptionTarget.value) return; exceptionForm.post(`/attendance/policy/violations/${exceptionTarget.value.id}/grant-exception`, { onSuccess: () => { exceptionDialog.value = false } }) }
function escalate(id: number) { router.post(`/attendance/policy/violations/${id}/escalate`, {}, { preserveState: false }) }

async function generateAi(v: Violation) {
  aiLoadingId.value = v.id
  try { const res = await axios.post(`/attendance/policy/violations/${v.id}/ai-enforcement`); v.ai_enforcement_decision = res.data.decision; expandedId.value = v.id }
  finally { aiLoadingId.value = null }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="engine" href="/attendance/policy"><VIcon start size="16" icon="tabler-cog-outline" />{{ t('attendance_policy_pages.tab_policy_engine') }}</VTab>
            <VTab value="violations" href="/attendance/policy/violations"><VIcon start size="16" icon="tabler-alert" />{{ t('attendance_policy_pages.tab_violations') }}</VTab>
            <VTab value="compliance" href="/attendance/policy/compliance"><VIcon start size="16" icon="tabler-shield-check" />{{ t('attendance_policy_pages.tab_compliance') }}</VTab>
            <VTab value="exceptions" href="/attendance/policy/exceptions"><VIcon start size="16" icon="tabler-shield-off-outline" />{{ t('attendance_policy_pages.tab_exceptions') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_policy_pages.violations_header') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_policy_pages.violations_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_policy_pages.violations_subtitle') }}</p>
            </div>
            <VBtn variant="text" color="primary" href="/attendance/policy">
              <i class="fas fa-arrow-left me-2"></i>{{ t('attendance_policy_pages.policy_engine_back') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap gap-3 py-3">
            <VSelect v-model="filterStatus" :items="filterStatusOptions" :label="t('attendance_policy_pages.filter_status')" density="compact" hide-details style="max-width:160px" />
            <VSelect v-model="filterCategory" :items="filterCategoryOptions" :label="t('attendance_policy_pages.filter_category')" density="compact" hide-details style="max-width:200px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_policy_pages.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_policy_pages.table_employee') }}</th>
                <th>{{ t('attendance_policy_pages.table_policy_rule') }}</th>
                <th>{{ t('attendance_policy_pages.table_category') }}</th>
                <th>{{ t('attendance_policy_pages.table_severity') }}</th>
                <th>{{ t('attendance_policy_pages.table_status') }}</th>
                <th>{{ t('attendance_policy_pages.table_detected') }}</th>
                <th>{{ t('attendance_policy_pages.table_period') }}</th>
                <th class="text-center">{{ t('attendance_policy_pages.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!violations.data.length">
                <td colspan="8" class="text-center py-8 text-medium-emphasis">{{ t('attendance_policy_pages.no_violations') }}</td>
              </tr>
              <template v-for="v in violations.data" :key="v.id">
                <tr @click="expandedId = expandedId === v.id ? null : v.id" style="cursor:pointer">
                  <td class="font-weight-medium">{{ v.employee }}</td>
                  <td class="text-caption">{{ v.policy_rule }}</td>
                  <td><VChip size="x-small" color="secondary" variant="tonal">{{ v.category_label }}</VChip></td>
                  <td><VChip :color="v.severity_color" size="x-small" variant="tonal" class="text-capitalize">{{ v.severity }}</VChip></td>
                  <td>
                    <VChip :color="v.status_color" size="small" variant="tonal" class="text-capitalize">{{ v.status }}</VChip>
                    <VChip v-if="v.blocked" color="error" size="x-small" variant="outlined" class="ms-1">{{ t('attendance_policy_pages.blocked_chip') }}</VChip>
                  </td>
                  <td class="text-caption">{{ v.detected_value }}</td>
                  <td class="text-caption">{{ v.period_start }} – {{ v.period_end }}</td>
                  <td class="text-center" @click.stop>
                    <VBtn icon size="small" variant="text" color="warning" title="AI Enforcement Decision" :loading="aiLoadingId === v.id" @click="generateAi(v)"><i class="fas fa-robot"></i></VBtn>
                    <VBtn v-if="v.status === 'open'" icon size="small" variant="text" color="deep-purple" title="Escalate" @click="escalate(v.id)"><i class="fas fa-level-up-alt"></i></VBtn>
                    <VBtn v-if="['open','escalated'].includes(v.status)" icon size="small" variant="text" color="success" title="Resolve" @click="openResolve(v)"><i class="fas fa-check-circle"></i></VBtn>
                    <VBtn v-if="['open','escalated'].includes(v.status)" icon size="small" variant="text" color="info" title="Grant Exception" @click="openException(v)"><i class="fas fa-shield-alt"></i></VBtn>
                  </td>
                </tr>
                <tr v-if="expandedId === v.id">
                  <td colspan="8" class="py-3 px-6" style="background:rgba(0,0,0,0.02)">
                    <VAlert v-if="v.ai_enforcement_decision" type="warning" variant="tonal" density="compact" style="font-size:0.82rem">
                      <i class="fas fa-robot me-2"></i><strong>{{ t('attendance_policy_pages.ai_enforcement_decision') }}</strong> {{ v.ai_enforcement_decision }}
                    </VAlert>
                    <div class="d-flex gap-4 text-caption text-medium-emphasis mt-1">
                      <span>{{ t('attendance_policy_pages.threshold_label') }} {{ v.threshold_value }}</span>
                      <span v-if="v.escalated_at">{{ t('attendance_policy_pages.escalated_label') }} {{ v.escalated_at }}</span>
                      <span v-if="v.resolution_notes">{{ t('attendance_policy_pages.resolution_label') }} {{ v.resolution_notes }}</span>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="violations.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="violations.current_page" :length="violations.last_page" @update:model-value="page => router.get('/attendance/policy/violations', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Resolve Dialog -->
    <VDialog v-model="resolveDialog" max-width="440">
      <VCard v-if="resolveTarget">
        <VCardTitle class="py-4 px-5">{{ t('attendance_policy_pages.resolve_dialog_title', { employee: resolveTarget.employee }) }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <div class="text-caption text-medium-emphasis mb-3">{{ resolveTarget.policy_rule }}: {{ resolveTarget.detected_value }}</div>
          <VTextarea v-model="resolveForm.resolution_notes" :label="t('attendance_policy_pages.field_resolution_notes')" rows="3" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="resolveDialog = false">{{ t('attendance_policy_pages.cancel') }}</VBtn><VBtn color="success" :loading="resolveForm.processing" @click="submitResolve">{{ t('attendance_policy_pages.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Exception Dialog -->
    <VDialog v-model="exceptionDialog" max-width="480">
      <VCard v-if="exceptionTarget">
        <VCardTitle class="py-4 px-5">{{ t('attendance_policy_pages.exception_dialog_title', { employee: exceptionTarget.employee }) }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <div class="text-caption text-medium-emphasis mb-3">{{ exceptionTarget.policy_rule }}</div>
          <VTextarea v-model="exceptionForm.reason" :label="t('attendance_policy_pages.field_exception_reason')" rows="3" density="compact" class="mb-3" />
          <VTextField v-model="exceptionForm.expires_at" :label="t('attendance_policy_pages.field_expires_on')" type="date" density="compact" class="mb-3" />
          <VTextarea v-model="exceptionForm.notes" :label="t('attendance_policy_pages.field_additional_notes')" rows="2" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="exceptionDialog = false">{{ t('attendance_policy_pages.cancel') }}</VBtn><VBtn color="info" :loading="exceptionForm.processing" @click="submitException">{{ t('attendance_policy_pages.grant_exception') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
