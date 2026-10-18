<script setup lang="ts">
import { reactive, ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

interface Employee  { id: number; name: string }
interface LeaveType { id: number; name: string }
interface Payout {
  id: number
  employee_name: string
  employee_id: number
  leave_type: string
  leave_type_id: number
  hours_paid_out: number
  days_paid_out: number
  amount: number
  payout_date: string
  payroll_reference: string
  status: 'pending' | 'processed' | 'reversed'
  notes: string
}

const props = defineProps<{
  payouts:    Payout[]
  employees:  Employee[]
  leaveTypes: LeaveType[]
}>()

const snack = reactive({ show: false, text: '', color: 'success' })
const toast = (text: string, color = 'success') => { snack.text = text; snack.color = color; snack.show = true }

// ── Create form ───────────────────────────────────────────────────────────────

const form = useForm({
  employee_id:       null as number | null,
  leave_type_id:     null as number | null,
  days:              '' as string | number,
  amount:            '' as string | number,
  payout_date:       new Date().toISOString().slice(0, 10),
  payroll_reference: '',
  notes:             '',
})

const currentBalance = ref<number | null>(null)
const balanceLoading = ref(false)

async function fetchBalance() {
  if (!form.employee_id || !form.leave_type_id) {
    currentBalance.value = null
    return
  }
  balanceLoading.value = true
  try {
    const res = await fetch(
      `/leave-management/manual-allocations/${form.employee_id}/balances`,
      { headers: { Accept: 'application/json' } }
    )
    if (!res.ok) throw new Error()
    const data: { leave_type_id: number; balance_hours: number }[] = await res.json()
    const match = data.find(b => b.leave_type_id === form.leave_type_id)
    currentBalance.value = match ? Math.round((match.balance_hours / 8) * 10) / 10 : 0
  } catch {
    currentBalance.value = null
  } finally {
    balanceLoading.value = false
  }
}

watch([() => form.employee_id, () => form.leave_type_id], fetchBalance)

const balanceColor = computed(() => {
  if (currentBalance.value === null) return 'default'
  const after = currentBalance.value - Number(form.days || 0)
  return after < 0 ? 'error' : after < 3 ? 'warning' : 'success'
})

const submit = () => {
  form.post('/leave-management/payouts', {
    onSuccess: () => {
      toast('Leave payout recorded and balance deducted.')
      form.reset()
      form.payout_date = new Date().toISOString().slice(0, 10)
      currentBalance.value = null
    },
    onError: () => toast('Could not record payout. Check the form for errors.', 'error'),
  })
}

// ── Status update ─────────────────────────────────────────────────────────────

const statusDialog = ref(false)
const statusTarget = ref<Payout | null>(null)
const newStatus    = ref<string>('')
const statusLoading = ref(false)

const openStatusDialog = (p: Payout) => {
  statusTarget.value = p
  newStatus.value    = p.status
  statusDialog.value = true
}

const saveStatus = () => {
  if (!statusTarget.value) return
  statusLoading.value = true
  router.patch(
    `/leave-management/payouts/${statusTarget.value.id}/status`,
    { status: newStatus.value },
    {
      onSuccess: () => {
        statusDialog.value  = false
        statusLoading.value = false
        toast(`Payout status updated to ${newStatus.value}.`)
      },
      onError: () => {
        statusLoading.value = false
        toast('Failed to update status.', 'error')
      },
    }
  )
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const statusColor  = (s: string) => ({ pending: 'warning', processed: 'success', reversed: 'error' }[s] ?? 'default')

function fmtDate(val: string | null) {
  if (!val) return '—'
  return new Date(val + 'T00:00:00').toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const { t } = useI18n()

const tableHeaders = computed(() => [
  { title: t('leave.employee_label'), key: 'employee_name',   sortable: true  },
  { title: t('leave.leave_type'),     key: 'leave_type',      sortable: true  },
  { title: t('leave.days'),           key: 'days_paid_out',   sortable: true  },
  { title: t('leave.amount'),         key: 'amount',          sortable: true  },
  { title: t('leave.payout_date'),    key: 'payout_date',     sortable: true  },
  { title: t('leave.ref'),            key: 'payroll_reference', sortable: false },
  { title: t('leave.status'),         key: 'status',          sortable: true  },
  { title: t('leave.actions'),        key: 'actions',         sortable: false },
])

const statusOptions = [
  { title: 'Pending',   value: 'pending'   },
  { title: 'Processed', value: 'processed' },
  { title: 'Reversed',  value: 'reversed'  },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="11">

        <!-- Header -->
        <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
          <div>
            <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
            <h4 class="text-h4">{{ t('leave.leave_payouts') }}</h4>
            <p class="text-medium-emphasis mb-0">Record leave days paid out in lieu of employees taking leave. The balance is deducted automatically.</p>
          </div>
          <VBtn variant="tonal" prepend-icon="tabler-arrow-left" href="/leave-management/reports">
            Reports
          </VBtn>
        </div>

        <VRow>

          <!-- ── Create payout form ──────────────────────────────────────── -->
          <VCol cols="12" lg="4">
            <VCard>
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">
                <VIcon icon="tabler-cash-plus" class="me-2" color="success" />{{ t('leave.new_payout') }}
              </VCardTitle>
              <VDivider />
              <VCardText class="pa-4">
                <VForm @submit.prevent="submit">

                  <VAutocomplete
                    v-model="form.employee_id"
                    :items="employees"
                    item-title="name"
                    item-value="id"
                    :label="t('leave.employee_label')"
                    variant="outlined"
                    density="comfortable"
                    prepend-inner-icon="tabler-account-search"
                    :error-messages="form.errors.employee_id"
                    clearable
                    class="mb-4"
                  />

                  <VSelect
                    v-model="form.leave_type_id"
                    :items="leaveTypes"
                    item-title="name"
                    item-value="id"
                    :label="t('leave.leave_type')"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.leave_type_id"
                    class="mb-3"
                  />

                  <!-- Current balance indicator -->
                  <div v-if="form.employee_id && form.leave_type_id" class="mb-4">
                    <VProgressLinear v-if="balanceLoading" indeterminate color="primary" />
                    <VChip
                      v-else-if="currentBalance !== null"
                      :color="balanceColor"
                      variant="tonal"
                      size="small"
                      prepend-icon="tabler-scale-balance"
                    >
                      Current balance: {{ currentBalance }} d
                      <template v-if="form.days">
                        &nbsp;→ after payout: {{ Math.round((currentBalance - Number(form.days)) * 10) / 10 }} d
                      </template>
                    </VChip>
                  </div>

                  <VTextField
                    v-model="form.days"
                    :label="t('leave.days_to_pay_out')"
                    type="number"
                    min="0.5"
                    step="0.5"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.days"
                    hint="Each day = 8 hours"
                    persistent-hint
                    class="mb-4"
                  />

                  <VTextField
                    v-model="form.amount"
                    label="Amount (R)"
                    type="number"
                    min="0"
                    step="0.01"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="form.errors.amount"
                    hint="Rand value — optional if not yet calculated"
                    persistent-hint
                    class="mb-4"
                  />

                  <div class="native-date-field mb-4">
                    <span class="native-date-field__label">Payout Date</span>
                    <input v-model="form.payout_date" type="date" class="native-date-field__input" />
                    <div v-if="form.errors.payout_date" class="text-error text-caption mt-1">{{ form.errors.payout_date }}</div>
                  </div>

                  <VTextField
                    v-model="form.payroll_reference"
                    :label="t('leave.payroll_reference')"
                    variant="outlined"
                    density="comfortable"
                    placeholder="e.g. PAY-2026-05"
                    :error-messages="form.errors.payroll_reference"
                    class="mb-4"
                  />

                  <VTextarea
                    v-model="form.notes"
                    :label="t('leave.notes')"
                    variant="outlined"
                    density="comfortable"
                    rows="2"
                    :error-messages="form.errors.notes"
                    class="mb-4"
                  />

                  <VBtn
                    type="submit"
                    color="success"
                    block
                    :loading="form.processing"
                    prepend-icon="tabler-cash-check"
                  >
                    {{ t('leave.record_payout') }}
                  </VBtn>

                </VForm>
              </VCardText>
            </VCard>
          </VCol>

          <!-- ── Payout records table ────────────────────────────────────── -->
          <VCol cols="12" lg="8">
            <VCard>
              <VCardTitle class="pa-4 pb-2 text-subtitle-1">
                <VIcon icon="tabler-cash-multiple" class="me-2" />{{ t('leave.payout_records') }}
                <VChip size="x-small" class="ms-2">{{ payouts.length }}</VChip>
              </VCardTitle>
              <VDivider />
              <VDataTable
                :headers="tableHeaders"
                :items="payouts"
                :items-per-page="15"
                hover
              >
                <template #item.payout_date="{ item }">
                  {{ fmtDate(item.payout_date) }}
                </template>

                <template #item.amount="{ item }">
                  <span v-if="item.amount">R {{ item.amount.toLocaleString('en-ZA', { minimumFractionDigits: 2 }) }}</span>
                  <span v-else class="text-medium-emphasis">—</span>
                </template>

                <template #item.days_paid_out="{ item }">
                  {{ item.days_paid_out }} d
                </template>

                <template #item.status="{ item }">
                  <VChip :color="statusColor(item.status)" size="small" label>
                    {{ item.status }}
                  </VChip>
                </template>

                <template #item.payroll_reference="{ item }">
                  <span v-if="item.payroll_reference" class="text-caption">{{ item.payroll_reference }}</span>
                  <span v-else class="text-medium-emphasis">—</span>
                </template>

                <template #item.actions="{ item }">
                  <VBtn
                    size="small"
                    variant="tonal"
                    icon="tabler-dots-vertical"
                    @click="openStatusDialog(item)"
                  />
                </template>

                <template #no-data>
                  <div class="text-center py-10 text-medium-emphasis">
                    <VIcon icon="tabler-cash-off" size="40" class="mb-2 d-block mx-auto" />
                    No payout records yet. Use the form to record a payout.
                  </div>
                </template>
              </VDataTable>
            </VCard>
          </VCol>

        </VRow>
      </VCol>
    </VRow>
  </VContainer>

  <!-- Status update dialog -->
  <VDialog v-model="statusDialog" max-width="360">
    <VCard v-if="statusTarget">
      <VCardTitle class="pa-4">{{ t('leave.update_payout_status') }}</VCardTitle>
      <VCardText class="pa-4 pt-0">
        <p class="text-body-2 text-medium-emphasis mb-4">
          <strong>{{ statusTarget.employee_name }}</strong> — {{ statusTarget.leave_type }},
          {{ statusTarget.days_paid_out }} d on {{ fmtDate(statusTarget.payout_date) }}
        </p>
        <VSelect
          v-model="newStatus"
          :items="statusOptions"
          :label="t('leave.status')"
          variant="outlined"
          density="comfortable"
        />
      </VCardText>
      <VCardActions class="pa-4 pt-0 justify-end gap-2">
        <VBtn variant="tonal" @click="statusDialog = false">{{ t('leave.cancel') }}</VBtn>
        <VBtn color="primary" :loading="statusLoading" @click="saveStatus">{{ t('leave.save') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" location="top right" timeout="4000">
    {{ snack.text }}
  </VSnackbar>
</template>

<style scoped>
.native-date-field {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px;
  padding: 8px 12px;
  min-height: 48px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.native-date-field__label {
  display: block;
  font-size: 0.75rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 3px;
}
.native-date-field__input {
  border: none;
  outline: none;
  background: transparent;
  color: rgb(var(--v-theme-on-surface));
  font-size: 0.875rem;
  width: 100%;
  font-family: inherit;
}
</style>
