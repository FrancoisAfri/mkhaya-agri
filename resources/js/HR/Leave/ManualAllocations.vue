<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount, onMounted } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  leaveTypes:   { id: number; name: string }[]
  companyUnits: { id: number; label: string; type: string; parent_id: number | null }[]
  cycle:        { id: number; name: string } | null
}>()

const tab      = ref<'individual' | 'bulk'>('individual')
const snackbar = ref({ show: false, text: '', color: 'success' })
const toast = (text: string, color = 'success') => {
  snackbar.value = { show: true, text, color }
}

interface Employee {
  id: number; employee_number: string | null; full_name: string
  email: string; division: string | null; department: string | null; photo_url: string | null
}
interface Balance {
  leave_type_id: number; leave_type_name: string; balance_hours: number; balance_days: number
}

const searchQuery      = ref('')
const searchResults    = ref<Employee[]>([])
const searchLoading    = ref(false)
const selectedEmployee = ref<Employee | null>(null)
const empBalances      = ref<Balance[]>([])
const balanceLoading   = ref(false)

const adjustDialog   = ref(false)
const adjustTarget   = ref<Balance | null>(null)
const adjustAction   = ref<'add' | 'deduct'>('add')
const adjustDays     = ref<number | string>('')
const adjustReason   = ref('')
const adjustLoading  = ref(false)
const adjustErrors   = ref<Record<string, string>>({})

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(searchQuery, (val) => {
  if (searchTimer) clearTimeout(searchTimer)
  if (!val || val.length < 2) { searchResults.value = []; return }
  searchTimer = setTimeout(() => doSearch(val), 300)
})

async function doSearch(q: string) {
  searchLoading.value = true
  try { const res = await axios.get('/leave-management/manual-allocations/search', { params: { q } }); searchResults.value = res.data }
  catch { /* silent */ } finally { searchLoading.value = false }
}

async function selectEmployee(emp: Employee) {
  selectedEmployee.value = emp; searchQuery.value = emp.full_name; searchResults.value = []; empBalances.value = []; balanceLoading.value = true
  try { const res = await axios.get(`/leave-management/manual-allocations/${emp.id}/balances`); empBalances.value = res.data.balances }
  catch { toast(t('leave_manual_allocation.load_balances_failed'), 'error') }
  finally { balanceLoading.value = false }
}

function openAdjustDialog(balance: Balance) {
  adjustTarget.value = balance; adjustAction.value = 'add'; adjustDays.value = ''; adjustReason.value = ''; adjustErrors.value = {}; adjustDialog.value = true
}

async function submitAdjust() {
  if (!selectedEmployee.value || !adjustTarget.value) return
  adjustErrors.value = {}
  if (!adjustDays.value || Number(adjustDays.value) <= 0) { adjustErrors.value.days = t('leave_manual_allocation.days_validation'); return }
  if (!adjustReason.value.trim()) { adjustErrors.value.reason = t('leave_manual_allocation.reason_validation'); return }
  adjustLoading.value = true
  try {
    const res = await axios.post('/leave-management/manual-allocations/adjust', {
      employee_id: selectedEmployee.value.id, leave_type_id: adjustTarget.value.leave_type_id,
      action: adjustAction.value, days: Number(adjustDays.value), reason: adjustReason.value,
    })
    const idx = empBalances.value.findIndex(b => b.leave_type_id === adjustTarget.value!.leave_type_id)
    if (idx >= 0) { empBalances.value[idx].balance_hours = res.data.new_balance; empBalances.value[idx].balance_days = Math.round(res.data.new_balance / 8 * 100) / 100 }
    adjustDialog.value = false; toast(res.data.message)
  } catch (e: any) {
    const errs = e.response?.data?.errors
    if (errs) adjustErrors.value = Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]))
    else toast(e.response?.data?.message ?? t('leave_manual_allocation.adjustment_failed'), 'error')
  } finally { adjustLoading.value = false }
}

const bulkUnit = ref<number | null>(null); const bulkLeaveType = ref<number | null>(null)
const bulkAction = ref<'add' | 'deduct'>('add'); const bulkDays = ref<number | string>('')
const bulkReason = ref(''); const bulkLoading = ref(false); const bulkErrors = ref<Record<string, string>>({})
const previewCount = ref<number | null>(null); const previewUnit = ref('')
const previewLoading = ref(false); const bulkStep = ref(1)

const otpSent = ref(false); const otpLoading = ref(false); const otpResending = ref(false)
const otpCode = ref(''); const otpPhoneMask = ref(''); const otpError = ref(''); const otpSuccess = ref('')
const timerDisplay = ref('15:00'); let timerSecs = 900; let timerInterval: ReturnType<typeof setInterval> | null = null

function startOtpTimer() {
  timerSecs = 900; timerDisplay.value = '15:00'; if (timerInterval) clearInterval(timerInterval)
  timerInterval = setInterval(() => {
    timerSecs--
    const m = Math.floor(timerSecs / 60).toString().padStart(2, '0'); const s = (timerSecs % 60).toString().padStart(2, '0')
    timerDisplay.value = `${m}:${s}`
    if (timerSecs <= 0) { clearInterval(timerInterval!); otpSent.value = false; otpError.value = t('leave_manual_allocation.otp_expired') }
  }, 1000)
}

onBeforeUnmount(() => { if (timerInterval) clearInterval(timerInterval) })

const csrf = () =>
  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''

async function sendBulkOtp() {
  otpLoading.value = true
  otpError.value = ''
  otpSuccess.value = ''

  try {
    const { data } = await axios.post('/leave-management/manual-allocations/send-bulk-otp', {}, {
      headers: { 'X-CSRF-TOKEN': csrf() },
    })
    otpSent.value = true
    otpPhoneMask.value = data.phone ?? data.masked ?? ''
    otpSuccess.value = data.message ?? t('leave_manual_allocation.otp_sent')
    startOtpTimer()
  } catch (e: any) {
    otpError.value = e.response?.data?.error ?? t('leave_manual_allocation.otp_failed')
  } finally {
    otpLoading.value = false
  }
}

async function resendBulkOtp() {
  otpResending.value = true
  otpError.value = ''

  try {
    const { data } = await axios.post('/leave-management/manual-allocations/send-bulk-otp', {}, {
      headers: { 'X-CSRF-TOKEN': csrf() },
    })
    otpSuccess.value = data.message ?? t('leave_manual_allocation.otp_resent')
    otpCode.value = ''
    startOtpTimer()
  } catch (e: any) {
    otpError.value = e.response?.data?.error ?? t('leave_manual_allocation.otp_failed')
  } finally {
    otpResending.value = false
  }
}

/** Count of employees in the chosen unit, refreshed whenever the unit changes. */
watch(bulkUnit, async (val) => {
  previewRows.value = []
  if (!val) { previewCount.value = null; previewUnit.value = ''; return }

  previewLoading.value = true
  try {
    const { data } = await axios.post('/leave-management/manual-allocations/preview-scope',
      { scope_id: val }, { headers: { 'X-CSRF-TOKEN': csrf() } })
    previewCount.value = data.count
    previewUnit.value  = data.unit_name
  } catch {
    previewCount.value = null
  } finally {
    previewLoading.value = false
  }
})

function bulkNextStep() {
  bulkErrors.value = {}
  if (bulkStep.value === 1) {
    if (!bulkUnit.value) { bulkErrors.value.scope = t('leave_manual_allocation.scope_validation'); return }
    if (!bulkLeaveType.value) { bulkErrors.value.leave_type = t('leave_manual_allocation.leave_type_validation'); return }
    bulkStep.value = 2
  } else if (bulkStep.value === 2) {
    if (!bulkDays.value || Number(bulkDays.value) <= 0) { bulkErrors.value.days = t('leave_manual_allocation.days_validation'); return }
    if (!bulkReason.value.trim()) { bulkErrors.value.reason = t('leave_manual_allocation.reason_validation'); return }
    bulkStep.value = 3
    if (!otpSent.value) sendBulkOtp()
  }
}

function bulkBack() {
  bulkStep.value = Math.max(1, bulkStep.value - 1)
  if (bulkStep.value < 3) { otpSent.value = false; otpCode.value = ''; otpError.value = ''; otpSuccess.value = ''; if (timerInterval) clearInterval(timerInterval) }
}

async function submitBulk() {
  bulkLoading.value = true
  bulkErrors.value = {}
  otpError.value = ''

  try {
    const { data } = await axios.post('/leave-management/manual-allocations/bulk-adjust', {
      scope_type:    'company_unit',
      scope_id:      bulkUnit.value,
      leave_type_id: bulkLeaveType.value,
      action:        bulkAction.value,
      days:          Number(bulkDays.value),
      reason:        bulkReason.value,
      otp:           otpCode.value,
    }, { headers: { 'X-CSRF-TOKEN': csrf() } })

    toast(data.message)
    resetBulk()
    loadBatches()
  } catch (e: any) {
    const msg = e.response?.data?.error ?? t('leave_manual_allocation.bulk_failed')
    // A bad OTP belongs next to the OTP field, not in the generic error slot.
    if (e.response?.status === 422 && /otp/i.test(msg)) otpError.value = msg
    else bulkErrors.value.general = msg
    toast(msg, 'error')
  } finally {
    bulkLoading.value = false
  }
}

function resetBulk() {
  bulkStep.value = 1
  bulkDays.value = ''
  bulkReason.value = ''
  otpSent.value = false
  otpCode.value = ''
  otpError.value = ''
  otpSuccess.value = ''
  previewRows.value = []
  if (timerInterval) clearInterval(timerInterval)
}

// ── Per-employee preview (balance before → change → after) ───────────────────

const previewRows    = ref<any[]>([])
const previewTotals  = ref<any | null>(null)
const rowsLoading    = ref(false)

/** Show exactly who is affected and by how much, before anything is written. */
async function loadPreviewRows() {
  if (!bulkUnit.value || !bulkLeaveType.value || !bulkDays.value) return

  rowsLoading.value = true
  try {
    const { data } = await axios.post('/leave-management/manual-allocations/preview', {
      scope_type:    'company_unit',
      scope_id:      bulkUnit.value,
      leave_type_id: bulkLeaveType.value,
      action:        bulkAction.value,
      days:          Number(bulkDays.value),
    }, { headers: { 'X-CSRF-TOKEN': csrf() } })

    previewRows.value   = data.rows
    previewTotals.value = data.totals
  } catch (e: any) {
    toast(e.response?.data?.error ?? t('leave_manual_allocation.preview_failed'), 'error')
  } finally {
    rowsLoading.value = false
  }
}

// ── Past allocations & reversal ──────────────────────────────────────────────

const batches        = ref<any[]>([])
const batchesLoading = ref(false)
const reversing      = ref<number | null>(null)

async function loadBatches() {
  batchesLoading.value = true
  try {
    const { data } = await axios.get('/leave-management/manual-allocations/batches')
    batches.value = data.data ?? data
  } catch {
    batches.value = []
  } finally {
    batchesLoading.value = false
  }
}

async function reverseBatch(batch: any) {
  const reason = window.prompt(
    t('leave_manual_allocation.reverse_prompt', { n: batch.total_employees })
  )
  if (reason === null) return

  reversing.value = batch.id
  try {
    const { data } = await axios.post(
      `/leave-management/manual-allocations/batches/${batch.uuid}/reverse`,
      { reason }, { headers: { 'X-CSRF-TOKEN': csrf() } })
    toast(data.message)
    loadBatches()
  } catch (e: any) {
    toast(e.response?.data?.error ?? t('leave_manual_allocation.reverse_failed'), 'error')
  } finally {
    reversing.value = null
  }
}

onMounted(loadBatches)

const selectedLeaveName = computed(() => props.leaveTypes.find(l => l.id === bulkLeaveType.value)?.name ?? '')
const selectedUnitLabel = computed(() => props.companyUnits.find(u => u.id === bulkUnit.value)?.label ?? '')

function balanceChipColor(days: number): string { if (days <= 0) return 'error'; if (days < 2) return 'warning'; return 'success' }
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('leave.manual_leave_allocation') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">
          {{ cycle ? t('leave_manual_allocation.active_cycle', { name: cycle.name }) : t('leave_manual_allocation.no_active_cycle') }}
        </p>
      </div>
    </div>

    <VTabs v-model="tab" color="primary" class="mb-6">
      <VTab value="individual" prepend-icon="tabler-account-edit-outline">{{ t('leave.individual_tab') }}</VTab>
      <VTab value="bulk" prepend-icon="tabler-account-group-outline">{{ t('leave.bulk_tab') }}</VTab>
    </VTabs>

    <VTabsWindow v-model="tab">
      <VTabsWindowItem value="individual">
        <VRow>
          <VCol cols="12" md="5">
            <VCard variant="outlined" class="pa-4">
              <div class="text-subtitle-2 font-weight-semibold mb-3">
                <VIcon size="18" class="me-1">tabler-magnify</VIcon> {{ t('leave.find_employee') }}
              </div>
              <VTextField v-model="searchQuery" :label="t('leave_manual_allocation.search_by_name')" variant="outlined" density="compact" clearable :loading="searchLoading" prepend-inner-icon="tabler-account-search-outline" hide-details />
              <VList v-if="searchResults.length" density="compact" class="mt-2 rounded border">
                <VListItem v-for="emp in searchResults" :key="emp.id" :title="emp.full_name" :subtitle="[emp.employee_number, emp.division].filter(Boolean).join(' · ')" class="my-1" @click="selectEmployee(emp)">
                  <template #prepend><VAvatar size="36" :color="emp.photo_url ? undefined : 'primary'"><VImg v-if="emp.photo_url" :src="emp.photo_url" cover /><VIcon v-else>tabler-user</VIcon></VAvatar></template>
                </VListItem>
              </VList>
              <VCard v-if="selectedEmployee" variant="tonal" color="primary" class="mt-4 pa-3 rounded-lg">
                <div class="d-flex align-center gap-3">
                  <VAvatar size="40" :color="selectedEmployee.photo_url ? undefined : 'primary'"><VImg v-if="selectedEmployee.photo_url" :src="selectedEmployee.photo_url" cover /><VIcon v-else>tabler-user</VIcon></VAvatar>
                  <div>
                    <div class="font-weight-semibold">{{ selectedEmployee.full_name }}</div>
                    <div class="text-caption text-medium-emphasis">{{ [selectedEmployee.employee_number, selectedEmployee.email].filter(Boolean).join(' · ') }}</div>
                    <div class="text-caption"><span v-if="selectedEmployee.division">{{ selectedEmployee.division }}</span><span v-if="selectedEmployee.department"> · {{ selectedEmployee.department }}</span></div>
                  </div>
                </div>
              </VCard>
            </VCard>
          </VCol>
          <VCol cols="12" md="7">
            <VCard variant="outlined">
              <VCardTitle class="text-subtitle-2 font-weight-semibold pa-4 pb-3">
                <VIcon size="18" class="me-1">tabler-scale-balance</VIcon>{{ t('leave.leave_balances') }}
                <span v-if="selectedEmployee" class="text-medium-emphasis font-weight-regular"> — {{ selectedEmployee.full_name }}</span>
              </VCardTitle>
              <VDivider />
              <div v-if="!selectedEmployee" class="pa-8 text-center text-medium-emphasis">
                <VIcon size="40" class="mb-2 text-disabled">tabler-account-question-outline</VIcon>
                <p class="text-body-2">{{ t('leave_manual_allocation.select_employee_prompt') }}</p>
              </div>
              <VProgressLinear v-else-if="balanceLoading" indeterminate color="primary" />
              <VDataTable v-else
                :headers="[
                  { title: t('leave_manual_allocation.leave_type_col'), key: 'leave_type_name', sortable: true },
                  { title: t('leave_manual_allocation.balance_col'), key: 'balance_days', sortable: true, align: 'end' },
                  { title: t('leave_manual_allocation.actions_col'), key: 'actions', sortable: false, align: 'end' },
                ]"
                :items="empBalances" density="compact" hide-default-footer item-value="leave_type_id"
              >
                <template #item.balance_days="{ item }">
                  <VChip :color="balanceChipColor(item.balance_days)" size="small" variant="tonal">{{ item.balance_days }}{{ t('leave_manual_allocation.days_suffix') }}</VChip>
                </template>
                <template #item.actions="{ item }">
                  <VBtn size="x-small" color="primary" variant="tonal" prepend-icon="tabler-pencil" @click="openAdjustDialog(item)">{{ t('leave.adjust') }}</VBtn>
                </template>
              </VDataTable>
            </VCard>
          </VCol>
        </VRow>
      </VTabsWindowItem>

      <VTabsWindowItem value="bulk">
        <VCard variant="outlined" max-width="760" class="mx-auto">
          <VCardTitle class="pa-5 pb-4"><VIcon class="me-2" color="warning">tabler-account-group-outline</VIcon>{{ t('leave.bulk_balance_adjustment') }}</VCardTitle>
          <VDivider />
          <VStepper v-model="bulkStep" flat class="elevation-0">
            <VStepperHeader class="elevation-0">
              <VStepperItem :value="1" :title="t('leave_manual_allocation.unit_scope')" :subtitle="t('leave_manual_allocation.unit_scope')" :complete="bulkStep > 1" />
              <VDivider />
              <VStepperItem :value="2" :title="t('leave_manual_allocation.adjustment_scope')" :subtitle="t('leave_manual_allocation.adjustment_scope')" :complete="bulkStep > 2" />
              <VDivider />
              <VStepperItem :value="3" :title="t('leave_manual_allocation.otp_scope')" :subtitle="t('leave_manual_allocation.otp_scope')" />
            </VStepperHeader>
            <VStepperWindow>
              <VStepperWindowItem :value="1">
                <div class="pa-5">
                  <VAutocomplete v-model="bulkUnit" :items="companyUnits" item-title="label" item-value="id" label="Company Unit (Department / Division / Section)" variant="outlined" density="compact" clearable prepend-inner-icon="tabler-sitemap-outline" :error-messages="bulkErrors.scope" class="mb-4" />
                  <VAlert v-if="previewLoading" type="info" variant="tonal" density="compact" class="mb-4">{{ t('leave_manual_allocation.loading_employee_count') }}</VAlert>
                  <VAlert v-else-if="previewCount !== null" :type="previewCount === 0 ? 'warning' : 'success'" variant="tonal" density="compact" class="mb-4">
                    <template v-if="previewCount === 0">{{ t('leave_manual_allocation.no_employees_found', { unit: previewUnit }) }}</template>
                    <template v-else>{{ t('leave_manual_allocation.employees_affected', { count: previewCount, unit: previewUnit }) }}</template>
                  </VAlert>
                  <VSelect v-model="bulkLeaveType" :items="leaveTypes" item-title="name" item-value="id" label="Leave Type" variant="outlined" density="compact" prepend-inner-icon="tabler-calendar-blank-outline" :error-messages="bulkErrors.leave_type" />
                  <div class="d-flex justify-end mt-4"><VBtn color="primary" :disabled="!bulkUnit || !bulkLeaveType" @click="bulkNextStep">{{ t('leave_manual_allocation.continue') }} <VIcon end>tabler-arrow-right</VIcon></VBtn></div>
                </div>
              </VStepperWindowItem>
              <VStepperWindowItem :value="2">
                <div class="pa-5">
                  <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('leave_manual_allocation.adjusting_balances', { leaveType: selectedLeaveName, count: previewCount ?? '?', unit: previewUnit }) }}</VAlert>
                  <VBtnToggle v-model="bulkAction" mandatory color="primary" variant="outlined" density="compact" class="mb-4">
                    <VBtn value="add" prepend-icon="tabler-plus-circle-outline">{{ t('leave_manual_allocation.add_days') }}</VBtn>
                    <VBtn value="deduct" prepend-icon="tabler-minus-circle-outline">{{ t('leave_manual_allocation.deduct_days') }}</VBtn>
                  </VBtnToggle>
                  <VTextField v-model="bulkDays" type="number" :label="t('leave_manual_allocation.days_to_action', { action: bulkAction === 'add' ? t('leave_manual_allocation.action_add').toLowerCase() : t('leave_manual_allocation.action_deduct').toLowerCase() })" variant="outlined" density="compact" min="0.5" step="0.5" :error-messages="bulkErrors.days" class="mb-3" />
                  <VTextarea v-model="bulkReason" :label="t('leave_manual_allocation.reason_notes')" variant="outlined" density="compact" rows="3" :error-messages="bulkErrors.reason" />
                  <div class="d-flex justify-space-between mt-4">
                    <VBtn variant="text" @click="bulkBack"><VIcon start>tabler-arrow-left</VIcon> {{ t('leave_manual_allocation.back') }}</VBtn>
                    <VBtn color="primary" @click="bulkNextStep">{{ t('leave_manual_allocation.continue') }} <VIcon end>tabler-arrow-right</VIcon></VBtn>
                  </div>
                </div>
              </VStepperWindowItem>
              <VStepperWindowItem :value="3">
                <div class="pa-5">
                  <VCard variant="tonal" color="warning" class="mb-5 pa-4 rounded-lg">
                    <div class="text-subtitle-2 font-weight-bold mb-2"><VIcon class="me-1">tabler-information-outline</VIcon> {{ t('leave_manual_allocation.review_before_submit') }}</div>
                    <div class="text-body-2"><strong>{{ t('leave_manual_allocation.action_label') }}</strong> {{ bulkAction === 'add' ? t('leave_manual_allocation.action_add') : t('leave_manual_allocation.action_deduct') }} <strong>{{ bulkDays }} {{ Number(bulkDays) !== 1 ? t('leave_manual_allocation.day_plural') : t('leave_manual_allocation.day_singular') }}</strong> {{ t('leave_manual_allocation.of_leave_type', { type: selectedLeaveName }) }}</div>
                    <div class="text-body-2"><strong>{{ t('leave_manual_allocation.scope_label') }}</strong> {{ selectedUnitLabel }}</div>
                    <div class="text-body-2"><strong>{{ t('leave_manual_allocation.employees_affected_label') }}</strong> {{ previewCount ?? '?' }}</div>

                    <!-- Exactly who is affected, and what happens to each balance. -->
                    <div class="mt-4">
                      <VBtn
                        size="small"
                        variant="tonal"
                        color="primary"
                        prepend-icon="tabler-list-search"
                        :loading="rowsLoading"
                        @click="loadPreviewRows"
                      >
                        {{ t('leave_manual_allocation.show_affected') }}
                      </VBtn>

                      <template v-if="previewRows.length">
                        <VAlert
                          v-if="previewTotals?.goes_negative"
                          type="warning"
                          variant="tonal"
                          density="compact"
                          class="mt-3"
                        >
                          {{ t('leave_manual_allocation.negative_warning', { n: previewTotals.goes_negative }) }}
                        </VAlert>

                        <VTable density="compact" class="mt-3" style="max-height: 320px; overflow-y: auto;">
                          <thead>
                            <tr>
                              <th>{{ t('leave_manual_allocation.employee_col') }}</th>
                              <th class="text-end">{{ t('leave_manual_allocation.before_col') }}</th>
                              <th class="text-end">{{ t('leave_manual_allocation.change_col') }}</th>
                              <th class="text-end">{{ t('leave_manual_allocation.after_col') }}</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="row in previewRows" :key="row.employee_id">
                              <td>
                                {{ row.employee_name }}
                                <span class="text-caption text-medium-emphasis">({{ row.employee_number }})</span>
                              </td>
                              <td class="text-end">{{ row.days_before }}</td>
                              <td class="text-end" :class="row.days_change < 0 ? 'text-error' : 'text-success'">
                                {{ row.days_change > 0 ? '+' : '' }}{{ row.days_change }}
                              </td>
                              <td class="text-end" :class="{ 'text-warning font-weight-bold': row.goes_negative }">
                                {{ row.days_after }}
                              </td>
                            </tr>
                          </tbody>
                        </VTable>
                      </template>
                    </div>
                    <div class="text-body-2"><strong>{{ t('leave_manual_allocation.reason_label') }}</strong> {{ bulkReason }}</div>
                  </VCard>
                  <VCard variant="outlined" class="mb-4">
                    <VCardTitle class="pa-4 pb-2 text-subtitle-2"><VIcon size="18" color="indigo" class="me-2">tabler-shield-lock-outline</VIcon>{{ t('leave_manual_allocation.identity_verification') }}</VCardTitle>
                    <VDivider />
                    <VCardText class="pa-4">
                      <div v-if="otpLoading" class="text-center py-4"><VProgressCircular indeterminate color="indigo" class="mb-3" /><p class="text-body-2 text-medium-emphasis">{{ t('leave_manual_allocation.sending_otp') }}</p></div>
                      <div v-else-if="!otpSent">
                        <p class="text-body-2 text-medium-emphasis mb-3">{{ t('leave_manual_allocation.otp_explanation') }}</p>
                        <VAlert v-if="otpError" type="error" variant="tonal" density="compact" class="mb-3">{{ otpError }}</VAlert>
                        <VBtn color="indigo" prepend-icon="tabler-message-text-outline" @click="sendBulkOtp" :loading="otpLoading">{{ t('leave_manual_allocation.send_otp') }}</VBtn>
                      </div>
                      <div v-else>
                        <VAlert type="success" variant="tonal" density="compact" class="mb-3" v-if="otpSuccess"><VIcon size="16" class="me-1">tabler-check-circle-outline</VIcon>{{ otpSuccess }}<span v-if="otpPhoneMask" class="text-medium-emphasis"> · {{ otpPhoneMask }}</span></VAlert>
                        <VAlert v-if="otpError" type="error" variant="tonal" density="compact" class="mb-3">{{ otpError }}</VAlert>
                        <div class="text-body-2 font-weight-medium mb-2">{{ t('leave_manual_allocation.enter_otp') }}</div>
                        <VTextField v-model="otpCode" variant="outlined" density="compact" maxlength="6" inputmode="numeric" placeholder="______" prepend-inner-icon="tabler-lock-outline" :hint="t('leave_manual_allocation.otp_hint')" persistent-hint :error-messages="otpError && otpCode.length > 0 ? otpError : ''" style="letter-spacing:8px;font-size:18px;font-weight:600" class="mb-2" />
                        <div class="d-flex align-center justify-space-between mt-2">
                          <span class="text-caption text-medium-emphasis">{{ t('leave_manual_allocation.expires_in') }} <strong :style="timerSecs <= 60 ? 'color:#dc3545' : 'color:#4f46e5'">{{ timerDisplay }}</strong></span>
                          <VBtn size="small" variant="text" color="indigo" :loading="otpResending" prepend-icon="tabler-refresh" @click="resendBulkOtp">{{ t('leave_manual_allocation.resend_otp') }}</VBtn>
                        </div>
                      </div>
                    </VCardText>
                  </VCard>
                  <div class="d-flex justify-space-between mt-2">
                    <VBtn variant="text" @click="bulkBack"><VIcon start>tabler-arrow-left</VIcon> {{ t('leave_manual_allocation.back') }}</VBtn>
                    <VBtn color="error" :loading="bulkLoading" :disabled="!otpSent || otpCode.length !== 6" prepend-icon="tabler-check-bold" @click="submitBulk">{{ t('leave_manual_allocation.confirm_bulk') }}</VBtn>
                  </div>
                </div>
              </VStepperWindowItem>
            </VStepperWindow>
          </VStepper>
        </VCard>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- ── Past allocations, with reversal ──────────────────────────────────── -->
    <VCard class="mt-8">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span class="text-subtitle-1">{{ t('leave_manual_allocation.past_allocations') }}</span>
        <VBtn size="small" variant="text" prepend-icon="tabler-refresh" :loading="batchesLoading" @click="loadBatches">
          {{ t('leave_manual_allocation.refresh') }}
        </VBtn>
      </VCardTitle>
      <VDivider />
      <VDataTable
        :headers="[
          { title: t('leave_manual_allocation.when_col'), key: 'created_at' },
          { title: t('leave_manual_allocation.scope_col'), key: 'scope_name' },
          { title: t('leave_manual_allocation.leave_type_col'), key: 'leave_type_name' },
          { title: t('leave_manual_allocation.change_col'), key: 'days' },
          { title: t('leave_manual_allocation.employees_col'), key: 'total_employees', align: 'end' },
          { title: t('leave_manual_allocation.by_col'), key: 'performed_by_name' },
          { title: t('leave_manual_allocation.status_col'), key: 'status' },
          { title: '', key: 'actions', sortable: false, align: 'end' },
        ]"
        :items="batches"
        :loading="batchesLoading"
        density="compact"
        :items-per-page="10"
      >
        <template #item.created_at="{ item }">
          {{ new Date(item.created_at).toLocaleDateString() }}
        </template>
        <template #item.days="{ item }">
          <span :class="item.action === 'deduct' ? 'text-error' : 'text-success'">
            {{ item.action === 'deduct' ? '−' : '+' }}{{ item.days }}{{ t('leave_manual_allocation.days_suffix') }}
          </span>
        </template>
        <template #item.status="{ item }">
          <VChip size="x-small" :color="item.status === 'reversed' ? 'default' : 'success'" variant="tonal">
            {{ item.status === 'reversed' ? t('leave_manual_allocation.reversed') : t('leave_manual_allocation.applied') }}
          </VChip>
          <div v-if="item.reversed_by_name" class="text-caption text-medium-emphasis">
            {{ t('leave_manual_allocation.reversed_by', { name: item.reversed_by_name }) }}
          </div>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            v-if="item.status !== 'reversed'"
            size="x-small"
            color="error"
            variant="tonal"
            prepend-icon="tabler-arrow-back-up"
            :loading="reversing === item.id"
            @click="reverseBatch(item)"
          >
            {{ t('leave_manual_allocation.reverse') }}
          </VBtn>
        </template>
        <template #no-data>
          <div class="text-center text-medium-emphasis py-6">
            {{ t('leave_manual_allocation.no_allocations') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Adjust Dialog -->
    <VDialog v-model="adjustDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-4"><VIcon class="me-2" color="primary">tabler-pencil-circle-outline</VIcon>{{ t('leave.adjust_leave_balance') }}</VCardTitle>
        <VDivider />
        <VCardText v-if="adjustTarget" class="pa-5">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('leave_manual_allocation.adjusting_balance_for', { type: adjustTarget.leave_type_name, name: selectedEmployee?.full_name, balance: adjustTarget.balance_days }) }}</VAlert>
          <VBtnToggle v-model="adjustAction" mandatory color="primary" variant="outlined" density="compact" class="mb-4 w-100">
            <VBtn value="add" prepend-icon="tabler-plus-circle-outline" class="flex-1-1">{{ t('leave_manual_allocation.add_days') }}</VBtn>
            <VBtn value="deduct" prepend-icon="tabler-minus-circle-outline" class="flex-1-1">{{ t('leave_manual_allocation.deduct_days') }}</VBtn>
          </VBtnToggle>
          <VTextField v-model="adjustDays" type="number" :label="t('leave_manual_allocation.days_to_action_single', { action: adjustAction === 'add' ? 'add' : 'deduct' })" variant="outlined" density="compact" min="0.5" step="0.5" :error-messages="adjustErrors.days" :hint="adjustDays && Number(adjustDays) > 0 ? t('leave_manual_allocation.new_balance_hint', { balance: (adjustAction === 'add' ? adjustTarget.balance_days + Number(adjustDays) : adjustTarget.balance_days - Number(adjustDays)).toFixed(2) }) : ''" persistent-hint class="mb-3" />
          <VTextarea v-model="adjustReason" :label="t('leave_manual_allocation.reason_required')" variant="outlined" density="compact" rows="3" :error-messages="adjustErrors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VBtn variant="text" @click="adjustDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VSpacer />
          <VBtn color="primary" :loading="adjustLoading" prepend-icon="tabler-check" @click="submitAdjust">{{ t('leave.apply_adjustment') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" location="bottom right">{{ snackbar.text }}</VSnackbar>
  </VContainer>
</template>
