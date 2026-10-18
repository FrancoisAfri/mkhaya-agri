<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const r = (key: string, a?: Record<string, unknown>) => t('payroll.remittances.' + key, a ?? {})

interface Due {
  uuid: string; beneficiary: string | null; type: string | null; period: string
  has_file?: boolean
  employees: number; employee_portion: number; employer_portion: number; total: number
  due_on: string | null; overdue: boolean; payable: boolean
}

defineProps<{
  outstanding: Due[]
  paid: { uuid: string; beneficiary: string | null; total: number; paid_at: string | null; reference: string | null }[]
  beneficiaries: { uuid: string; name: string; type: string; line_types: string[]; active: boolean; payable: boolean; pay_by_day: number | null }[]
  types: string[]
  summary: { due_total: number; overdue_total: number; overdue_count: number }
}>()

const rand = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const paying = ref<string | null>(null)
const reference = ref('')

function markPaid(uuid: string) {
  router.post(route('payroll.remittances.paid', uuid), { reference: reference.value },
    { preserveScroll: true, onSuccess: () => { paying.value = null; reference.value = '' } })
}

const dialog = ref(false)
const form = useForm({
  name: '', type: 'medical_aid', reference: '', bank_name: '', branch_code: '',
  account_number: '', account_type: 'current', contact_email: '',
  line_types: [] as string[], pay_by_day: 7, active: true,
})

/** The payslip lines a fund receives. Employer lines are listed separately on purpose. */
const LINE_TYPES = [
  'medical_aid', 'employer_medical', 'pension', 'employer_pension', 'other_deduction',
]

function save() {
  form.post(route('payroll.remittances.beneficiaries.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1200px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ r('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ r('description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ r('add_beneficiary') }}</VBtn>
    </div>

    <VRow class="mb-2">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ r('owed') }}</div>
          <div class="text-h6">{{ rand(summary.due_total) }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ r('overdue') }}</div>
          <div class="text-h6" :class="summary.overdue_total ? 'text-error' : ''">{{ rand(summary.overdue_total) }}</div>
          <div class="text-caption text-medium-emphasis">{{ r('n_late', { n: summary.overdue_count }) }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ r('funds') }}</div>
          <div class="text-h6">{{ beneficiaries.filter(b => b.active).length }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!-- What is owed. This is the whole reason the screen exists. -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ r('outstanding') }}</VCardTitle>
      <div style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ r('fund') }}</th><th>{{ r('period') }}</th>
              <th class="text-end">{{ r('employees') }}</th>
              <th class="text-end">{{ r('employee_portion') }}</th>
              <th class="text-end">{{ r('employer_portion') }}</th>
              <th class="text-end">{{ r('total') }}</th>
              <th>{{ r('due') }}</th><th class="text-end">&nbsp;</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in outstanding" :key="row.uuid">
              <td>
                {{ row.beneficiary }}
                <VChip v-if="!row.payable" size="x-small" color="warning" variant="tonal" class="ms-1">{{ r('no_bank') }}</VChip>
              </td>
              <td class="text-caption">{{ row.period }}</td>
              <td class="text-end">{{ row.employees }}</td>
              <td class="text-end">{{ rand(row.employee_portion) }}</td>
              <td class="text-end">{{ rand(row.employer_portion) }}</td>
              <td class="text-end font-weight-medium">{{ rand(row.total) }}</td>
              <td>
                <span :class="row.overdue ? 'text-error font-weight-medium' : ''">{{ row.due_on || '—' }}</span>
              </td>
              <td class="text-end">
                <VBtn
                  size="small" variant="text" class="row-action"
                  :href="route('payroll.remittances.download', row.uuid)"
                  :aria-label="r('download_for', { fund: row.beneficiary })"
                >{{ r('schedule') }}</VBtn>
                <!--
                  The vendor file, offered only where the fund is set up for one. Separate from the
                  schedule above: that one a person reads by eye, this one a machine reads at the
                  fund, so it refuses rather than approximates when a reference is missing.
                -->
                <VBtn
                  v-if="row.has_file"
                  size="small" variant="text" class="row-action"
                  :href="route('payroll.remittances.deduction-file', row.uuid)"
                  :aria-label="r('deduction_file_for', { fund: row.beneficiary })"
                >{{ r('deduction_file') }}</VBtn>
                <VBtn
                  size="small" variant="text" color="primary" class="row-action"
                  :aria-label="r('mark_paid_for', { fund: row.beneficiary })"
                  @click="paying = paying === row.uuid ? null : row.uuid"
                >{{ r('mark_paid') }}</VBtn>
              </td>
            </tr>
            <tr v-if="!outstanding.length">
              <td colspan="8" class="text-center text-medium-emphasis py-8">{{ r('nothing_owed') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>

      <VCardText v-if="paying" class="d-flex align-center ga-2 pt-0">
        <VTextField
          v-model="reference" :label="r('payment_reference')" density="compact"
          variant="outlined" hide-details :hint="r('reference_hint')"
        />
        <VBtn color="primary" @click="markPaid(paying)">{{ r('record') }}</VBtn>
        <VBtn variant="text" @click="paying = null">{{ r('cancel') }}</VBtn>
      </VCardText>
    </VCard>

    <VCard v-if="paid.length" elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ r('recently_paid') }}</VCardTitle>
      <div style="overflow-x:auto">
        <VTable density="compact">
          <thead><tr><th>{{ r('fund') }}</th><th class="text-end">{{ r('total') }}</th><th>{{ r('paid_on') }}</th><th>{{ r('reference') }}</th></tr></thead>
          <tbody>
            <tr v-for="row in paid" :key="row.uuid">
              <td>{{ row.beneficiary }}</td>
              <td class="text-end">{{ rand(row.total) }}</td>
              <td>{{ row.paid_at }}</td>
              <td class="text-medium-emphasis">{{ row.reference || '—' }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VCard elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ r('funds_title') }}</VCardTitle>
      <VCardSubtitle class="pb-2">{{ r('funds_help') }}</VCardSubtitle>
      <div style="overflow-x:auto">
        <VTable density="compact">
          <thead><tr><th>{{ r('fund') }}</th><th>{{ r('kind') }}</th><th>{{ r('receives') }}</th><th>{{ r('due_day') }}</th><th>{{ r('status') }}</th></tr></thead>
          <tbody>
            <tr v-for="b in beneficiaries" :key="b.uuid">
              <td>{{ b.name }}</td>
              <td>{{ r('types.' + b.type) }}</td>
              <td>
                <VChip v-for="lt in b.line_types" :key="lt" size="x-small" variant="tonal" class="me-1">{{ lt }}</VChip>
              </td>
              <td>{{ b.pay_by_day ? r('day_of_month', { n: b.pay_by_day }) : '—' }}</td>
              <td>
                <VChip v-if="!b.active" size="x-small" variant="tonal">{{ r('inactive') }}</VChip>
                <VChip v-else-if="!b.payable" size="x-small" color="warning" variant="tonal">{{ r('no_bank') }}</VChip>
                <VChip v-else size="x-small" color="success" variant="tonal">{{ r('active') }}</VChip>
              </td>
            </tr>
            <tr v-if="!beneficiaries.length">
              <td colspan="5" class="text-center text-medium-emphasis py-6">{{ r('no_funds') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog v-model="dialog" max-width="720">
      <VCard>
        <VCardTitle>{{ r('add_beneficiary') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <div class="field-row">
            <VTextField v-model="form.name" :label="r('fund_name')" :error-messages="form.errors.name" />
            <VSelect v-model="form.type" :items="types" :label="r('kind')" />
          </div>
          <VSelect
            v-model="form.line_types" :items="LINE_TYPES" :label="r('receives')"
            multiple chips :hint="r('receives_hint')" persistent-hint
          />
          <div class="field-row">
            <VTextField v-model="form.bank_name" :label="r('bank')" />
            <VTextField v-model="form.branch_code" :label="r('branch_code')" />
          </div>
          <div class="field-row">
            <VTextField v-model="form.account_number" :label="r('account_number')" />
            <VTextField v-model.number="form.pay_by_day" type="number" :label="r('due_day')" :hint="r('due_day_hint')" persistent-hint />
          </div>
          <VTextField v-model="form.reference" :label="r('our_reference')" :hint="r('our_reference_hint')" persistent-hint />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ r('cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ r('save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 11rem; min-width: 0; }
</style>
