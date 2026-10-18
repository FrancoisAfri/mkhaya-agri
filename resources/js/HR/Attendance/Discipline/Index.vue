<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type DisciplineCase = {
  id: number; employee: string; employee_id: number
  case_number: string; case_type: string; case_type_label: string
  status: string; status_color: string
  severity: string; severity_color: string
  summary: string; violations: string[]
  ai_recommendation: string | null
  period_start: string; period_end: string
  has_pack: boolean; created_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  cases:     Paginated<DisciplineCase>
  summary:   { open: number; pending: number }
  filters:   { status: string; case_type: string }
  caseTypes: Record<string, string>
}>()

const subNav         = ref('cases')
const scanDialog     = ref(false)
const resolveDialog  = ref(false)
const resolveTarget  = ref<DisciplineCase | null>(null)
const packLoading    = ref<number | null>(null)
const recLoading     = ref<number | null>(null)
const filterStatus   = ref(props.filters.status)
const filterCaseType = ref(props.filters.case_type)

const scanForm    = useForm({
  period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
  period_end:   new Date().toISOString().split('T')[0],
})
const resolveForm = useForm({ status: 'resolved' as string, resolution_notes: '' })

const filterStatusOptions = computed(() => [
  { title: t('attendance_discipline.status_all'), value: 'all' },
  { title: t('attendance_discipline.status_pending'), value: 'pending' },
  { title: t('attendance_discipline.status_in_progress'), value: 'in_progress' },
  { title: t('attendance_discipline.status_resolved'), value: 'resolved' },
  { title: t('attendance_discipline.status_closed'), value: 'closed' },
  { title: t('attendance_discipline.status_dismissed'), value: 'dismissed' },
])

const filterCaseTypeOptions = computed(() => [
  { title: t('attendance_discipline.filter_all_types'), value: 'all' },
  ...Object.entries(props.caseTypes).map(([v, t]) => ({ title: t, value: v })),
])

const resolveStatusOptions = computed(() => [
  { title: t('attendance_discipline.status_resolved'), value: 'resolved' },
  { title: t('attendance_discipline.status_dismissed'), value: 'dismissed' },
  { title: t('attendance_discipline.status_closed'), value: 'closed' },
])

function applyFilter() {
  router.get('/attendance/discipline', {
    status:    filterStatus.value   !== 'all' ? filterStatus.value   : undefined,
    case_type: filterCaseType.value !== 'all' ? filterCaseType.value : undefined,
  }, { preserveState: true })
}

function runScan() { scanForm.post('/attendance/discipline/scan', { onSuccess: () => { scanDialog.value = false } }) }

function generatePack(id: number) {
  packLoading.value = id
  useForm({}).post(`/attendance/discipline/cases/${id}/generate-pack`, { onFinish: () => { packLoading.value = null } })
}

function generateRecommendation(id: number) {
  recLoading.value = id
  useForm({}).post(`/attendance/discipline/cases/${id}/generate-recommendation`, { onFinish: () => { recLoading.value = null } })
}

function openResolve(c: DisciplineCase) { resolveTarget.value = c; resolveForm.reset(); resolveForm.status = 'resolved'; resolveDialog.value = true }

function submitResolve() {
  if (!resolveTarget.value) return
  resolveForm.post(`/attendance/discipline/cases/${resolveTarget.value.id}/resolve`, { onSuccess: () => { resolveDialog.value = false } })
}

const caseTypeIcon: Record<string, string> = {
  verbal_warning: 'fas fa-comment-alt', written_warning: 'fas fa-file-alt',
  final_warning: 'fas fa-exclamation-circle', suspension: 'fas fa-ban', dismissal: 'fas fa-user-slash',
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Sub-nav -->
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="cases" href="/attendance/discipline">
              <VIcon start size="16" icon="tabler-gavel" />{{ t('attendance_discipline.tab_cases') }}
            </VTab>
            <VTab value="offenders" href="/attendance/discipline/offenders">
              <VIcon start size="16" icon="tabler-account-alert" />{{ t('attendance_discipline.tab_offenders') }}
            </VTab>
          </VTabs>
        </VCard>
      </VCol>

      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_discipline.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_discipline.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_discipline.subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="error" href="/attendance/discipline/offenders">
                <i class="fas fa-user-slash me-2"></i>{{ t('attendance_discipline.repeat_offenders_btn') }}
              </VBtn>
              <VBtn color="primary" @click="scanDialog = true">
                <i class="fas fa-search me-2"></i>{{ t('attendance_discipline.run_discipline_scan') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Summary -->
      <VCol cols="12" md="6">
        <VCard color="warning" variant="tonal" class="text-center">
          <VCardText class="py-4">
            <div class="text-h4 font-weight-bold">{{ summary.open }}</div>
            <div class="text-caption">{{ t('attendance_discipline.open_cases') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard color="info" variant="tonal" class="text-center">
          <VCardText class="py-4">
            <div class="text-h4 font-weight-bold">{{ summary.pending }}</div>
            <div class="text-caption">{{ t('attendance_discipline.pending_cases') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Filters -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap gap-3 py-3">
            <VSelect
              v-model="filterStatus"
              :items="filterStatusOptions"
              :label="t('attendance_discipline.filter_status')"
              density="compact"
              hide-details
              style="max-width:160px"
            />
            <VSelect
              v-model="filterCaseType"
              :items="filterCaseTypeOptions"
              :label="t('attendance_discipline.filter_case_type')"
              density="compact"
              hide-details
              style="max-width:200px"
            />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_discipline.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Cases table -->
      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_discipline.table_case_number') }}</th>
                <th>{{ t('attendance_discipline.table_employee') }}</th>
                <th>{{ t('attendance_discipline.table_type') }}</th>
                <th>{{ t('attendance_discipline.table_severity') }}</th>
                <th>{{ t('attendance_discipline.table_status') }}</th>
                <th>{{ t('attendance_discipline.table_period') }}</th>
                <th>{{ t('attendance_discipline.table_pack') }}</th>
                <th class="text-center">{{ t('attendance_discipline.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!cases.data.length">
                <td colspan="8" class="text-center py-8 text-medium-emphasis">{{ t('attendance_discipline.no_cases') }}</td>
              </tr>
              <template v-for="c in cases.data" :key="c.id">
                <tr>
                  <td class="text-caption font-weight-medium">{{ c.case_number }}</td>
                  <td class="font-weight-medium">{{ c.employee }}</td>
                  <td>
                    <div class="d-flex align-center gap-1">
                      <i :class="caseTypeIcon[c.case_type] ?? 'fas fa-gavel'" style="font-size:11px;opacity:0.6"></i>
                      <span class="text-caption">{{ c.case_type_label }}</span>
                    </div>
                  </td>
                  <td><VChip :color="c.severity_color" size="x-small" variant="tonal" class="text-capitalize">{{ c.severity }}</VChip></td>
                  <td><VChip :color="c.status_color" size="small" variant="tonal" class="text-capitalize">{{ c.status }}</VChip></td>
                  <td class="text-caption">{{ c.period_start }} – {{ c.period_end }}</td>
                  <td>
                    <VChip v-if="c.has_pack" color="success" size="x-small" variant="tonal">
                      <i class="fas fa-file-check me-1"></i>{{ t('attendance_discipline.pack_ready') }}
                    </VChip>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                  </td>
                  <td class="text-center">
                    <VBtn :href="`/attendance/discipline/cases/${c.id}`" icon size="small" variant="text" color="primary" title="View Case">
                      <i class="fas fa-eye"></i>
                    </VBtn>
                    <VBtn icon size="small" variant="text" color="info" title="AI Recommendation" :loading="recLoading === c.id" @click="generateRecommendation(c.id)">
                      <i class="fas fa-robot"></i>
                    </VBtn>
                    <VBtn icon size="small" variant="text" color="warning" title="Generate Evidence Pack" :loading="packLoading === c.id" @click="generatePack(c.id)">
                      <i class="fas fa-file-alt"></i>
                    </VBtn>
                    <VBtn icon size="small" variant="text" color="success" title="Resolve Case" @click="openResolve(c)">
                      <i class="fas fa-check-double"></i>
                    </VBtn>
                  </td>
                </tr>
                <!-- Violations + AI recommendation -->
                <tr v-if="c.violations?.length || c.ai_recommendation">
                  <td colspan="8" class="py-2 px-6" style="background:rgba(0,0,0,0.02)">
                    <div v-if="c.violations?.length" class="d-flex flex-wrap gap-1 mb-2">
                      <VChip v-for="v in c.violations" :key="v" size="x-small" color="error" variant="outlined">{{ v }}</VChip>
                    </div>
                    <VAlert v-if="c.ai_recommendation" type="info" variant="tonal" density="compact" style="font-size:0.82rem">
                      <i class="fas fa-robot me-2"></i><strong>{{ t('attendance_discipline.ai_recommendation') }}</strong> {{ c.ai_recommendation }}
                    </VAlert>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="cases.last_page > 1" class="d-flex justify-center py-4">
            <VPagination
              :model-value="cases.current_page"
              :length="cases.last_page"
              @update:model-value="page => router.get('/attendance/discipline', { page }, { preserveState: true })"
            />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Scan Dialog -->
    <VDialog v-model="scanDialog" max-width="440">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_discipline.scan_dialog_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4" style="font-size:0.82rem">
            {{ t('attendance_discipline.scan_dialog_info') }}
          </VAlert>
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="scanForm.period_start" :label="t('attendance_discipline.field_period_start')" type="date" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="scanForm.period_end" :label="t('attendance_discipline.field_period_end')" type="date" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="scanDialog = false">{{ t('attendance_discipline.cancel') }}</VBtn>
          <VBtn color="primary" :loading="scanForm.processing" @click="runScan">
            <i class="fas fa-search me-2"></i>{{ t('attendance_discipline.run_scan') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Resolve Dialog -->
    <VDialog v-model="resolveDialog" max-width="440">
      <VCard v-if="resolveTarget">
        <VCardTitle class="py-4 px-5">{{ t('attendance_discipline.resolve_dialog_title', { case_number: resolveTarget.case_number }) }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VSelect
            v-model="resolveForm.status"
            :items="resolveStatusOptions"
            :label="t('attendance_discipline.filter_status')"
            density="compact"
            class="mb-4"
          />
          <VTextarea v-model="resolveForm.resolution_notes" :label="t('attendance_discipline.field_resolution_notes')" rows="3" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('attendance_discipline.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="submitResolve">{{ t('attendance_discipline.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
