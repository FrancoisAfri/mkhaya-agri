<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import TeamLeaveCalendar from './TeamLeaveCalendar.vue'

type LeaveType = {
  id: number; name: string; statutory_type: string | null
  is_paid: boolean; doc_required: number; notes_required: number
  requires_medical_certificate: boolean
  bcea_minimum_days?: number | null
  require_probation_period_days?: number | null
  accrual_rate_hours_per_month?: number | null
}
type Balance = { leave_type_id: number; leave_type_name: string; balance_hours: number; balance_days: number }
type Employee = { id: number; first_name: string; surname: string } | null
type TeamLeave = { name: string; leave_type: string | null; color: string; start: string; end: string; status: string }
type Team = { department: string | null; leave: TeamLeave[] }

const { t } = useI18n()

const props = defineProps<{ leaveTypes: LeaveType[]; balances: Balance[]; employee: Employee; team?: Team }>()

const form = useForm<{
  employee_id: number | string; leave_type_id: number | string
  request_mode: 'daily' | 'hourly'; start_date: string; end_date: string
  start_time: string; end_time: string; notes: string
  is_overtime: boolean; is_bulk: boolean
  document: File | null; document_type: string
}>({
  employee_id:    props.employee?.id ?? '',
  leave_type_id:  '',
  request_mode:   'daily',
  start_date:     '',
  end_date:       '',
  start_time:     '',
  end_time:       '',
  notes:          '',
  is_overtime:    false,
  is_bulk:        false,
  document:       null,
  document_type:  'medical_certificate',
})

const selectedType    = computed(() => props.leaveTypes.find(t => t.id === Number(form.leave_type_id)))
const selectedBalance = computed(() => props.balances.find(b => b.leave_type_id === Number(form.leave_type_id)))

const isSick       = computed(() => selectedType.value?.statutory_type === 'sick')
const isFrl        = computed(() => selectedType.value?.statutory_type === 'family_responsibility')
const isParental   = computed(() => ['maternity', 'paternity', 'parental'].includes(selectedType.value?.statutory_type ?? ''))
const isHourly     = computed(() => form.request_mode === 'hourly')
const showDocUpload = computed(() => selectedType.value?.doc_required === 1)
const showMedCert   = computed(() => selectedType.value?.requires_medical_certificate || isSick.value)

const lowBalance = computed(() =>
  selectedBalance.value !== undefined && selectedBalance.value.balance_hours < 8
)

// ── SA sick-note rule (client hint; the server enforces authoritatively) ──────
// Required for sick leave that spans 2+ days, or that falls on a Monday or Friday.
const weekday = (d: string) => new Date(d + 'T00:00:00').getDay() // 0=Sun … 6=Sat
const spanDays = computed(() => {
  if (!form.start_date) return 0
  if (!form.end_date) return 1
  const ms = new Date(form.end_date + 'T00:00:00').getTime() - new Date(form.start_date + 'T00:00:00').getTime()
  return Math.floor(ms / 86_400_000) + 1
})
const sickNoteRequired = computed(() => {
  if (!isSick.value || !form.start_date) return false
  if (spanDays.value >= 2) return true
  const s = weekday(form.start_date)
  const e = form.end_date ? weekday(form.end_date) : s
  return s === 1 || s === 5 || e === 1 || e === 5 // Monday or Friday
})
const sickNoteMissing = computed(() => sickNoteRequired.value && !form.document)

const statutoryInfo = computed<{ icon: string; color: string; text: string } | null>(() => {
  const lt = selectedType.value
  if (!lt) return null
  if (lt.statutory_type === 'annual') {
    const minDays = lt.bcea_minimum_days ?? 15
    const accrual = lt.accrual_rate_hours_per_month
      ? `Accrual: ${(lt.accrual_rate_hours_per_month / 8).toFixed(1)} d/month during probation.`
      : `Accrual: 1 day per 26 working days during probation (${lt.require_probation_period_days ?? 182}-day qualifying period).`
    return { icon: 'tabler-information-outline', color: 'info', text: t('apply_page.statutory_annual_info', { minDays, accrual }) }
  }
  if (lt.statutory_type === 'sick') return { icon: 'tabler-stethoscope', color: 'warning', text: t('apply_page.statutory_sick_info') }
  if (lt.statutory_type === 'family_responsibility') return { icon: 'tabler-account-heart-outline', color: 'info', text: t('apply_page.statutory_frl_info', { cap: lt.bcea_minimum_days ?? 3 }) }
  if (lt.statutory_type === 'maternity') return { icon: 'tabler-baby-carriage', color: 'info', text: t('apply_page.statutory_maternity_info') }
  if (lt.statutory_type === 'paternity' || lt.statutory_type === 'parental') return { icon: 'tabler-baby', color: 'info', text: t('apply_page.statutory_parental_info') }
  if (lt.statutory_type === 'study') return { icon: 'tabler-school-outline', color: 'info', text: t('apply_page.statutory_study_info') }
  if (lt.statutory_type === 'unpaid') return { icon: 'tabler-cash-remove', color: 'warning', text: t('apply_page.statutory_unpaid_info') }
  return null
})

const serverErrors = computed(() => form.errors as Record<string, string | string[]>)

const workingDaysPreview  = ref<number | null>(null)
const daysPreviewLoading  = ref(false)

watch([() => form.start_date, () => form.end_date], async ([start, end]) => {
  if (!start || !end || end < start) { workingDaysPreview.value = null; return }
  daysPreviewLoading.value = true
  try {
    const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
    const res  = await fetch(`/leave-management/working-days-preview?start_date=${start}&end_date=${end}`, {
      headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
    })
    const data = await res.json()
    workingDaysPreview.value = data.days ?? null
  } catch { workingDaysPreview.value = null } finally { daysPreviewLoading.value = false }
})

// ── Team-leave calendar (rendered by the shared TeamLeaveCalendar component) ───
const teamLeave = computed<TeamLeave[]>(() => props.team?.leave ?? [])
const teamClashes = computed<TeamLeave[]>(() => {
  if (!form.start_date || !form.end_date) return []
  return teamLeave.value.filter(l => l.start <= form.end_date && l.end >= form.start_date)
})

const submit = () => {
  form.post('/leave-management/apply', {
    forceFormData: true,
    onSuccess: () => router.visit('/leave-management/my-applications'),
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <!-- Application form -->
      <VCol cols="12" md="7" lg="7">
        <div class="d-flex align-center gap-3 mb-6">
          <VBtn icon variant="text" @click="router.visit('/leave-management')"><VIcon icon="tabler-arrow-left" /></VBtn>
          <div>
            <h4 class="text-h4">{{ t('leave.apply_for_leave') }}</h4>
            <p class="text-medium-emphasis mb-0">{{ employee ? `${employee.first_name} ${employee.surname}` : t('apply_page.employee') }}</p>
          </div>
        </div>

        <VForm @submit.prevent="submit">
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('leave.leave_details') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12">
                  <VSelect v-model="form.leave_type_id" :items="leaveTypes" item-title="name" item-value="id" :label="t('leave.leave_type')" :error-messages="form.errors.leave_type_id" variant="outlined" density="comfortable" />
                </VCol>

                <VCol v-if="selectedBalance" cols="12">
                  <VAlert :type="lowBalance ? 'warning' : 'info'" variant="tonal" density="compact" :icon="lowBalance ? 'tabler-alert-outline' : 'tabler-scale-balance'">
                    <strong>{{ t('apply_page.available_balance') }}</strong>
                    {{ selectedBalance.balance_days }} {{ t('apply_page.days') }}
                    <span v-if="lowBalance" class="font-weight-medium">{{ t('apply_page.low_balance') }}</span>
                  </VAlert>
                </VCol>

                <VCol v-if="statutoryInfo" cols="12">
                  <VAlert :type="statutoryInfo.color as any" variant="tonal" density="compact" :icon="statutoryInfo.icon">{{ statutoryInfo.text }}</VAlert>
                </VCol>

                <VCol v-if="isParental" cols="12">
                  <VAlert type="info" variant="tonal" density="compact" icon="tabler-information-outline">{{ t('apply_page.parental_pool_notice') }}</VAlert>
                </VCol>

                <VCol cols="12">
                  <VBtnToggle v-model="form.request_mode" color="primary" density="comfortable" mandatory>
                    <VBtn value="daily" prepend-icon="tabler-calendar">{{ t('leave.daily') }}</VBtn>
                    <VBtn value="hourly" prepend-icon="tabler-clock-outline">{{ t('leave.hourly') }}</VBtn>
                  </VBtnToggle>
                </VCol>

                <VCol cols="12" sm="6">
                  <VTextField v-model="form.start_date" :label="t('leave.start_date')" type="date" :error-messages="form.errors.start_date" variant="outlined" density="comfortable" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.end_date" :label="t('leave.end_date')" type="date" :min="form.start_date" :error-messages="form.errors.end_date" variant="outlined" density="comfortable" />
                </VCol>

                <VCol v-if="workingDaysPreview !== null || daysPreviewLoading" cols="12">
                  <VAlert type="success" variant="tonal" density="compact" icon="tabler-calendar-check">
                    <template v-if="daysPreviewLoading"><VProgressCircular size="14" width="2" indeterminate class="me-2" />{{ t('apply_page.calculating_days') }}</template>
                    <template v-else>{{ t('apply_page.working_days_applied', { count: workingDaysPreview ?? 0 }) }}<span class="text-medium-emphasis text-caption ms-1">{{ t('apply_page.public_holidays_excluded') }}</span></template>
                  </VAlert>
                </VCol>

                <!-- Clash with a teammate on the chosen dates -->
                <VCol v-if="teamClashes.length" cols="12">
                  <VAlert type="warning" variant="tonal" density="compact" icon="tabler-users">
                    <div class="font-weight-medium">{{ t('apply_page.team_clash_title', { n: teamClashes.length }) }}</div>
                    <div class="text-caption">{{ teamClashes.map(c => c.name).join(', ') }}</div>
                  </VAlert>
                </VCol>

                <template v-if="isHourly">
                  <VCol cols="12" sm="6"><VTextField v-model="form.start_time" :label="t('leave.start_time')" type="time" :error-messages="form.errors.start_time" variant="outlined" density="comfortable" /></VCol>
                  <VCol cols="12" sm="6"><VTextField v-model="form.end_time" :label="t('leave.end_time')" type="time" :error-messages="form.errors.end_time" variant="outlined" density="comfortable" /></VCol>
                </template>

                <VCol cols="12"><VSwitch v-model="form.is_overtime" :label="t('apply_page.overtime_application')" color="warning" density="compact" hide-details /></VCol>

                <VCol cols="12">
                  <VTextarea v-model="form.notes" :label="t('leave.notes_reason')" :required="selectedType?.notes_required === 1 || isFrl" :error-messages="form.errors.notes" variant="outlined" rows="3" density="comfortable" :hint="isFrl ? t('apply_page.frl_notes_hint') : ''" :persistent-hint="isFrl" />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <!-- Inline sick-note / supporting document upload -->
          <VCard v-if="showMedCert || showDocUpload" class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1 d-flex align-center gap-2">
              <VIcon icon="tabler-file-certificate" size="20" />
              {{ isSick ? t('apply_page.sick_note') : t('apply_page.supporting_document') }}
              <VChip v-if="sickNoteRequired" size="x-small" color="error" variant="flat" class="ms-1">{{ t('apply_page.required') }}</VChip>
              <VChip v-else size="x-small" color="secondary" variant="tonal" class="ms-1">{{ t('apply_page.optional') }}</VChip>
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert v-if="isSick" :type="sickNoteRequired ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-3" icon="tabler-stethoscope">
                {{ t('apply_page.sick_note_rule') }}
              </VAlert>
              <VFileInput
                v-model="form.document"
                :label="isSick ? t('apply_page.upload_sick_note') : t('apply_page.upload_document')"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                prepend-icon="tabler-paperclip"
                variant="outlined"
                density="comfortable"
                show-size
                :error-messages="form.errors.document"
                :hint="t('apply_page.doc_formats_hint')"
                persistent-hint
              />
              <div v-if="sickNoteMissing" class="text-error text-caption mt-1">
                <VIcon icon="tabler-alert-circle" size="14" /> {{ t('apply_page.sick_note_missing') }}
              </div>
            </VCardText>
          </VCard>

          <VCard v-if="serverErrors.bcea || serverErrors.policy || serverErrors.parental || serverErrors.frl" class="mb-4">
            <VCardText>
              <VAlert type="error" variant="tonal">
                <div v-if="serverErrors.bcea"><strong>{{ t('apply_page.bcea_validation_failed') }}</strong><ul class="mt-1 mb-0 ps-4"><li v-for="(e, i) in (Array.isArray(serverErrors.bcea) ? serverErrors.bcea : [serverErrors.bcea])" :key="i">{{ e }}</li></ul></div>
                <div v-if="serverErrors.policy" class="mt-2"><strong>{{ t('apply_page.policy_violation') }}</strong><ul class="mt-1 mb-0 ps-4"><li v-for="(e, i) in (Array.isArray(serverErrors.policy) ? serverErrors.policy : [serverErrors.policy])" :key="i">{{ e }}</li></ul></div>
                <div v-if="serverErrors.parental" class="mt-2"><strong>{{ t('apply_page.parental_leave_header') }}</strong><ul class="mt-1 mb-0 ps-4"><li v-for="(e, i) in (Array.isArray(serverErrors.parental) ? serverErrors.parental : [serverErrors.parental])" :key="i">{{ e }}</li></ul></div>
                <div v-if="serverErrors.frl" class="mt-2"><strong>{{ t('apply_page.frl_header') }}</strong><ul class="mt-1 mb-0 ps-4"><li v-for="(e, i) in (Array.isArray(serverErrors.frl) ? serverErrors.frl : [serverErrors.frl])" :key="i">{{ e }}</li></ul></div>
              </VAlert>
            </VCardText>
          </VCard>

          <div class="d-flex gap-3 justify-end">
            <VBtn variant="tonal" @click="router.visit('/leave-management')">{{ t('leave.cancel') }}</VBtn>
            <VBtn type="submit" color="primary" :loading="form.processing" :disabled="!form.leave_type_id || !form.start_date || !form.end_date || sickNoteMissing">{{ t('leave.submit_application') }}</VBtn>
          </div>
        </VForm>
      </VCol>

      <!-- Team-leave calendar -->
      <VCol cols="12" md="5" lg="4">
        <TeamLeaveCalendar :leave="teamLeave" :department="team?.department ?? null" :start-date="form.start_date" :end-date="form.end_date" />
      </VCol>
    </VRow>
  </VContainer>
</template>
