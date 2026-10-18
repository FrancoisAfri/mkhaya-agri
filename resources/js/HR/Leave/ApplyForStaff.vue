<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import TeamLeaveCalendar, { type TeamLeave } from './TeamLeaveCalendar.vue'

const route = ziggyRoute
const { t } = useI18n()

type LeaveType = { id: number; name: string; statutory_type?: string | null; notes_required?: boolean; doc_required?: number; requires_medical_certificate?: boolean }
type Employee = { id: number; first_name: string; surname: string; employee_number: string | null; user_id: number | null }

const props = defineProps<{
  leaveTypes: LeaveType[]
  employees: Employee[]
  policy: string
  canSetPolicy: boolean
}>()

const form = useForm<{
  employee_id: number | null; leave_type_id: number | null
  start_date: string; end_date: string; request_mode: string; notes: string
  document: File | null; document_type: string
}>({
  employee_id:   null,
  leave_type_id: null,
  start_date:    '',
  end_date:      '',
  request_mode:  'daily',
  notes:         '',
  document:      null,
  document_type: 'medical_certificate',
})

const employeeItems = props.employees.map(e => ({
  title: `${e.first_name} ${e.surname}${e.employee_number ? ' · ' + e.employee_number : ''}${e.user_id ? '' : ' · no login'}`,
  value: e.id,
}))

const selectedType = computed(() => props.leaveTypes.find(t => t.id === Number(form.leave_type_id)))
const isSick       = computed(() => selectedType.value?.statutory_type === 'sick')
const showMedCert  = computed(() => selectedType.value?.requires_medical_certificate || isSick.value || selectedType.value?.doc_required === 1)

// ── SA sick-note rule (client hint; the server enforces authoritatively) ──────
const weekday = (d: string) => new Date(d + 'T00:00:00').getDay()
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
  return s === 1 || s === 5 || e === 1 || e === 5
})
const sickNoteMissing = computed(() => sickNoteRequired.value && !form.document)

// ── Team-leave calendar for the SELECTED employee (fetched on change) ─────────
const team = ref<{ department: string | null; leave: TeamLeave[] }>({ department: null, leave: [] })
const teamLoading = ref(false)
watch(() => form.employee_id, async (id) => {
  team.value = { department: null, leave: [] }
  if (!id) return
  teamLoading.value = true
  try {
    const res = await fetch(route('hr.leave.teammate-leave') + '?employee_id=' + id, { headers: { Accept: 'application/json' } })
    team.value = await res.json()
  } catch { team.value = { department: null, leave: [] } } finally { teamLoading.value = false }
})
const teamClashes = computed<TeamLeave[]>(() => {
  if (!form.start_date || !form.end_date) return []
  return team.value.leave.filter(l => l.start <= form.end_date && l.end >= form.start_date)
})

function submit() {
  form.post(route('hr.leave.apply-for-staff.store'), { preserveScroll: true, forceFormData: true })
}

const policyOptions = [
  { title: 'Supervisor applies → HR approves (default)', value: 'hr' },
  { title: "Supervisor applies → Supervisor's manager", value: 'supervisor_manager' },
  { title: 'Supervisor applies → HR → Payroll (final)', value: 'hr_then_final' },
  { title: 'Auto-approve (no approval step)', value: 'auto' },
]
const policyForm = useForm({ policy: props.policy })
function savePolicy() { policyForm.post(route('hr.leave.on-behalf-policy'), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4" style="max-width: 1180px; margin: 0 auto">
    <div class="d-flex align-center mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('hr.leave.index'))" />
      <h1 class="text-h5 font-weight-bold ms-2">Apply Leave for Staff</h1>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Lodge a leave request on behalf of an employee — including non-login staff (e.g. cleaners).
      It's routed for approval per your company policy below.
    </p>

    <VRow>
      <VCol cols="12" md="7" lg="8">
        <VCard variant="outlined">
          <VCardText>
            <VRow dense>
              <VCol cols="12">
                <VAutocomplete v-model="form.employee_id" :items="employeeItems" item-title="title" item-value="value" label="Employee *" prepend-inner-icon="tabler-user" variant="outlined" density="compact" :error-messages="form.errors.employee_id" />
              </VCol>
              <VCol cols="12" md="6">
                <VSelect v-model="form.leave_type_id" :items="props.leaveTypes.map(t => ({ title: t.name, value: t.id }))" label="Leave type *" prepend-inner-icon="tabler-calendar" variant="outlined" density="compact" :error-messages="form.errors.leave_type_id" />
              </VCol>
              <VCol cols="12" md="6">
                <VSelect v-model="form.request_mode" :items="[{ title: 'Full day(s)', value: 'daily' }, { title: 'Half day', value: 'half_day' }]" label="Mode" variant="outlined" density="compact" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="form.start_date" type="date" label="Start date *" variant="outlined" density="compact" :error-messages="form.errors.start_date" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="form.end_date" type="date" label="End date *" :min="form.start_date" variant="outlined" density="compact" :error-messages="form.errors.end_date" />
              </VCol>

              <VCol v-if="teamClashes.length" cols="12">
                <VAlert type="warning" variant="tonal" density="compact" icon="tabler-users">
                  <div class="font-weight-medium">{{ t('apply_page.team_clash_title', { n: teamClashes.length }) }}</div>
                  <div class="text-caption">{{ teamClashes.map(c => c.name).join(', ') }}</div>
                </VAlert>
              </VCol>

              <VCol cols="12">
                <VTextarea v-model="form.notes" label="Notes / reason" rows="2" variant="outlined" density="compact" :error-messages="form.errors.notes" />
              </VCol>

              <!-- Inline sick-note / supporting document -->
              <VCol v-if="showMedCert" cols="12">
                <VAlert v-if="isSick" :type="sickNoteRequired ? 'warning' : 'info'" variant="tonal" density="compact" class="mb-2" icon="tabler-stethoscope">
                  {{ t('apply_page.sick_note_rule') }}
                </VAlert>
                <VFileInput
                  v-model="form.document"
                  :label="isSick ? t('apply_page.upload_sick_note') : t('apply_page.upload_document')"
                  accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                  prepend-icon="tabler-paperclip"
                  variant="outlined"
                  density="compact"
                  show-size
                  :error-messages="form.errors.document"
                  :hint="t('apply_page.doc_formats_hint')"
                  persistent-hint
                />
                <div v-if="sickNoteMissing" class="text-error text-caption mt-1">
                  <VIcon icon="tabler-alert-circle" size="14" /> {{ t('apply_page.sick_note_missing') }}
                </div>
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn color="primary" :loading="form.processing" :disabled="!form.employee_id || !form.leave_type_id || !form.start_date || !form.end_date || sickNoteMissing" @click="submit">
              Submit leave request
            </VBtn>
          </VCardActions>
        </VCard>

        <!-- Approval routing (admins/HR only) -->
        <VCard v-if="props.canSetPolicy" variant="outlined" class="mt-4">
          <VCardItem>
            <VCardTitle class="text-subtitle-1">Approval routing for staff-submitted leave</VCardTitle>
            <VCardSubtitle>Who approves leave a supervisor lodges on an employee's behalf.</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <div class="d-flex align-center gap-3">
              <VSelect v-model="policyForm.policy" :items="policyOptions" item-title="title" item-value="value" variant="outlined" density="compact" hide-details style="max-width: 460px" />
              <VBtn color="primary" variant="tonal" :loading="policyForm.processing" @click="savePolicy">Save</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Team-leave calendar for the selected employee -->
      <VCol cols="12" md="5" lg="4">
        <TeamLeaveCalendar :leave="team.leave" :department="team.department" :start-date="form.start_date" :end-date="form.end_date" :loading="teamLoading" />
      </VCol>
    </VRow>
  </div>
</template>
