<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.emp.' + key, a ?? {})
type Emp = {
  uuid: string; name: string; employee_number: string | null; occupation: string | null
  basic_salary: number; start_date: string | null; backfill_months: number; status: string; has_key: boolean
  manager: string | null; manager_name: string | null
  id_number: string | null; passport_number: string | null; tax_number: string | null
  email: string | null; phone: string | null; payment_type: string | null
  bank_name: string | null; branch_code: string | null; account_number: string | null
  pay_frequency: string | null; employment_category: string | null; eti_opt_in?: boolean
  eti_claimed?: number; eti_remaining?: number
  medical_members: number; medical_dependants: number; retirement_contribution: number
}
type BackfillType = { name: string; code: string; days: number; periods: number }
type Backfill = { available: boolean; months: number; from: string | null; to: string | null; total_days: number; types: BackfillType[]; employee?: string; name?: string }
const props = defineProps<{ company: { uuid: string; name: string; pay_frequency: string }; employees: Emp[]; components: any[] }>()
const page = usePage()

const dialog = ref(false)
// The record being edited, or null when adding. The dialog is the same either way — a client
// correcting a bank account should not meet a different form from the one that created it.
const editing = ref<Emp | null>(null)
const form = useForm({
  employee_number: '', first_name: '', last_name: '', id_number: '', passport_number: '',
  tax_number: '', email: '', phone: '', manager: '' as string | null,
  pay_frequency: '' as string | null,
  employment_category: '' as string | null,
  // The date everything about leave hangs off: accrual starts here, and a mid-year joiner is
  // pro-rated from it. Left out of this form until now, so every employee was created without one.
  start_date: '',
  occupation: '', payment_type: 'salary', basic_salary: 0,
  bank_name: '', branch_code: '', account_number: '',
  medical_members: 0, medical_dependants: 0, medical_contribution: 0, retirement_contribution: 0, eti_opt_in: true,
})
// South African banks with their universal branch codes (auto-filled on select).
const SA_BANKS = [
  { title: 'Absa Bank', code: '632005' },
  { title: 'African Bank', code: '430000' },
  { title: 'Bidvest Bank', code: '462005' },
  { title: 'Capitec Bank', code: '470010' },
  { title: 'Discovery Bank', code: '679000' },
  { title: 'First National Bank (FNB)', code: '250655' },
  { title: 'Investec Bank', code: '580105' },
  { title: 'Nedbank', code: '198765' },
  { title: 'Postbank', code: '460005' },
  { title: 'Sasfin Bank', code: '683000' },
  { title: 'Standard Bank', code: '051001' },
  { title: 'TymeBank', code: '678910' },
]
const COMMON_OCCUPATIONS = [
  'Accountant', 'Administrator', 'Artisan', 'Bookkeeper', 'Cashier', 'Cleaner', 'Clerk', 'Driver',
  'Electrician', 'Engineer', 'General Worker', 'Human Resources Officer', 'IT Technician', 'Labourer',
  'Manager', 'Nurse', 'Operator', 'Receptionist', 'Sales Representative', 'Security Officer',
  'Supervisor', 'Teacher', 'Technician', 'Welder',
]
function onBankChange(name: string) {
  const bank = SA_BANKS.find((b) => b.title === name)
  if (bank) form.branch_code = bank.code
}

function openAdd() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(e: Emp) {
  editing.value = e
  form.clearErrors()
  const [first, ...rest] = (e.name ?? '').split(' ')
  form.employee_number = e.employee_number ?? ''
  form.first_name = first ?? ''
  form.last_name = rest.join(' ')
  form.start_date = e.start_date ?? ''
  form.manager = e.manager
  form.id_number = e.id_number ?? ''
  form.passport_number = e.passport_number ?? ''
  form.tax_number = e.tax_number ?? ''
  form.email = e.email ?? ''
  form.phone = e.phone ?? ''
  form.occupation = e.occupation ?? ''
  form.payment_type = e.payment_type ?? 'salary'
  form.pay_frequency = e.pay_frequency
  form.employment_category = e.employment_category
  form.basic_salary = e.basic_salary ?? 0
  form.bank_name = e.bank_name ?? ''
  form.branch_code = e.branch_code ?? ''
  form.account_number = e.account_number ?? ''
  form.medical_members = e.medical_members ?? 0
  form.medical_dependants = e.medical_dependants ?? 0
  form.retirement_contribution = e.retirement_contribution ?? 0
  dialog.value = true
}

function save() {
  const done = { onSuccess: () => { dialog.value = false; editing.value = null; form.reset() } }

  editing.value
    ? form.put(route('payroll.bureau.employees.update', [props.company.uuid, editing.value.uuid]), done)
    : form.post(route('payroll.bureau.employees.store', props.company.uuid), done)
}

// Anyone at this client except the person being edited — nobody approves their own leave.
const managerOptions = computed(() =>
  props.employees
    .filter(e => e.uuid !== editing.value?.uuid)
    .map(e => ({ title: `${e.name}${e.employee_number ? ' · ' + e.employee_number : ''}`, value: e.uuid })))
function genKey(e: Emp) { router.post(route('payroll.bureau.employees.key', [props.company.uuid, e.uuid]), {}, { preserveScroll: true }) }
function applyStandard() { router.post(route('payroll.bureau.components.apply-standard', props.company.uuid), {}, { preserveScroll: true }) }
function portalLink(e: Emp) { router.post(route('payroll.bureau.employees.portal-link', [props.company.uuid, e.uuid]), {}, { preserveScroll: true }) }

// Bulk import
const importDialog = ref(false)
const importForm = useForm({ file: null as File | null })
function downloadTemplate() { window.open(route('payroll.bureau.employees.template', props.company.uuid), '_blank') }
function uploadCsv() {
  importForm.post(route('payroll.bureau.employees.import', props.company.uuid), {
    forceFormData: true, onSuccess: () => { importDialog.value = false; importForm.reset() },
  })
}
const rand = (n: number) => n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })

// ── Catching up a back-dated employee ──────────────────────────────────────
// Somebody entered today with a start date in March earned five months of leave the accrual engine
// will never go back for. It is offered rather than done: the days end up as money on a termination
// payout, and only the agent knows whether this person's history already came across on an opening
// balance — in which case granting it again pays them twice.
const backfillDialog = ref(false)
const backfillOffer = ref<Backfill | null>(null)
const backfillTarget = ref<string | null>(null)
const backfillLoading = ref(false)
const backfillSaving = ref(false)

// Offered automatically the moment a back-dated employee is added, because that is when the agent
// still has the context to answer it.
watch(() => (page.props.flash as any)?.backfill_offer, (offer: Backfill | undefined) => {
  if (offer?.available) {
    backfillOffer.value = offer
    backfillTarget.value = offer.employee ?? null
    backfillDialog.value = true
  }
}, { immediate: true })

async function openBackfill(e: Emp) {
  backfillTarget.value = e.uuid
  backfillOffer.value = null
  backfillLoading.value = true
  backfillDialog.value = true

  try {
    const { data } = await axios.get(
      route('leave.bureau.employees.backfill.preview', [props.company.uuid, e.uuid]),
    )
    backfillOffer.value = { ...data, name: e.name }
  } finally {
    backfillLoading.value = false
  }
}

function confirmBackfill() {
  if (!backfillTarget.value) return
  backfillSaving.value = true
  router.post(route('leave.bureau.employees.backfill', [props.company.uuid, backfillTarget.value]), {}, {
    preserveScroll: true,
    onFinish: () => { backfillSaving.value = false; backfillDialog.value = false; backfillOffer.value = null },
  })
}

const backfillPeriod = computed(() => {
  const o = backfillOffer.value
  if (!o?.from || !o?.to) return ''
  return `${o.from} to ${o.to}`
})
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the client" class="row-action" variant="text" @click="router.get(route('payroll.bureau.companies.show', company.uuid))" />
      <div class="flex-grow-1">
        <h4 class="text-h4 mb-0">Employees — {{ company.name }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ company.pay_frequency }} payroll</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-gift" @click="applyStandard">{{ k('add_benefits') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-sitemap" :href="route('payroll.bureau.managers.index', company.uuid)">{{ u('reporting_lines') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-upload" @click="importDialog = true">{{ k('bulk_import') }}</VBtn>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openAdd">{{ k('add_employee') }}</VBtn>
    </div>

    <VAlert v-if="(page.props.flash as any)?.payslip_key" type="success" variant="tonal" class="mb-4">
      Payslip key (share with the employee, shown once): <code>{{ (page.props.flash as any).payslip_key }}</code>
    </VAlert>
    <VAlert v-if="(page.props.flash as any)?.portal_link" type="success" variant="tonal" class="mb-4">
      Self-service payslip link (share with the employee): <a :href="(page.props.flash as any).portal_link" target="_blank">{{ (page.props.flash as any).portal_link }}</a>
    </VAlert>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ u('employee') }}</th><th>{{ k('number_short') }}</th><th>{{ t('bureau.employees.started') }}</th><th>{{ u('occupation') }}</th><th>{{ t('bureau.eti.category') }}</th><th>{{ t('bureau.freq.pay_frequency') }}</th><th>{{ u('reports_to') }}</th><th class="text-end">{{ k('basic') }}</th><th>{{ u('status') }}</th><th>{{ k('payslip_key') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="e in employees" :key="e.uuid">
            <td>{{ e.name }}</td><td>{{ e.employee_number }}</td>
            <!-- Called out rather than left blank: without a start date this employee accrues nothing. -->
            <td>
              <span v-if="e.start_date">{{ e.start_date }}</span>
              <VChip v-else size="x-small" color="warning" variant="tonal" :title="k('cannot_accrue')">none</VChip>
              <!-- Months worked before the engine ever saw them. Shown on the row because this is
                   where an agent notices it, long after the employee was added. -->
              <VBtn
                v-if="e.backfill_months > 0"
                size="x-small"
                variant="tonal"
                color="warning"
                class="ms-2"
                prepend-icon="tabler-history"
                :aria-label="`Catch up ${e.backfill_months} months of leave for ${e.name}`"
                @click="openBackfill(e)"
              >
                Catch up {{ e.backfill_months }} mo
              </VBtn>
            </td>
            <td>{{ e.occupation }}</td>
            <td class="text-no-wrap">
              <span v-if="e.employment_category">{{ t('bureau.eti.' + e.employment_category) }}</span>
              <span v-else class="text-medium-emphasis">{{ t('bureau.eti.not_set') }}</span>
              <VChip v-if="e.eti_opt_in" size="x-small" color="info" variant="tonal" class="ms-1">
                {{ t('bureau.eti.eti_tracked') }}
              </VChip>
              <!--
                How many of the twenty-four months are gone. The dashboard warns three months out;
                this answers the question at any point before that, and the end of the incentive is
                a rise in what the client owes SARS.
              -->
              <div v-if="e.eti_claimed" class="text-caption text-medium-emphasis mt-1">
                <VChip
                  size="x-small"
                  :color="e.eti_remaining === 0 ? 'error' : (e.eti_remaining ?? 24) <= 3 ? 'warning' : 'success'"
                  variant="tonal"
                >
                  {{ t('bureau.eti.months_left', { left: e.eti_remaining, used: e.eti_claimed }) }}
                </VChip>
              </div>
            </td>
            <!-- Which cycle they are paid on. Shown because a person on a different cycle from
                 their employer is only paid if a run exists for it. -->
            <td class="text-no-wrap">
              <span v-if="e.pay_frequency">{{ e.pay_frequency }}</span>
              <span v-else class="text-medium-emphasis">{{ company.pay_frequency }}</span>
            </td>
            <!-- Named on the register because it decides who approves this person's leave, and an
                 employee with nobody set has requests that sit waiting for no one. -->
            <td>
              <span v-if="e.manager_name">{{ e.manager_name }}</span>
              <VChip v-else size="x-small" color="warning" variant="tonal" :title="k('nobody_approves')">
                unassigned
              </VChip>
            </td>
            <td class="text-end">R {{ rand(e.basic_salary) }}</td>
            <td><VChip size="x-small" :color="e.status === 'active' ? 'success' : 'default'" variant="tonal">{{ e.status }}</VChip></td>
            <td>
              <VChip v-if="e.has_key" size="x-small" color="info" variant="tonal" class="me-1">{{ k('set') }}</VChip>
              <VBtn size="x-small" variant="text" @click="genKey(e)">{{ e.has_key ? 'Rotate' : 'Generate' }}</VBtn>
              <VBtn size="x-small" variant="text" @click="portalLink(e)">{{ k('portal_link') }}</VBtn>
            </td>
            <td class="text-end">
              <VBtn
                icon="tabler-pencil"
                size="x-small"
                variant="text"
                :aria-label="`Edit ${e.name}`"
                @click="openEdit(e)"
              />
            </td>
          </tr>
          <tr v-if="!employees.length"><td colspan="11" class="text-center text-medium-emphasis py-6">{{ k('no_employees') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="760" scrollable>
      <VCard>
        <VCardTitle>{{ editing ? k('edit_employee') : k('add_employee') }}</VCardTitle>
        <VCardSubtitle>
          {{ editing ? k('edit_sub') : k('add_sub') }}
        </VCardSubtitle>
        <VDivider />

        <!-- Scrolls inside the card, so Cancel and Add stay on screen however long the form gets. -->
        <VCardText style="max-height: 62vh">
          <div class="d-flex flex-wrap ga-3 mb-3">
            <VTextField v-model="form.first_name" :label="k('first_name')" :error-messages="form.errors.first_name" style="min-width:14rem;flex:1" />
            <VTextField v-model="form.last_name" :label="k('last_name')" :error-messages="form.errors.last_name" style="min-width:14rem;flex:1" />
          </div>

          <div class="d-flex flex-wrap ga-3 mb-3">
            <VTextField
              v-model="form.start_date"
              type="date"
              :label="k('started_on')"
              :hint="k('started_hint')"
              persistent-hint
              :error-messages="form.errors.start_date"
              style="min-width:14rem;flex:1"
            />
            <VTextField v-model="form.employee_number" :label="k('employee_number')" :hint="k('number_hint')" persistent-hint :error-messages="form.errors.employee_number" style="min-width:14rem;flex:1" />
          </div>

          <div class="d-flex flex-wrap ga-3 mb-3">
            <VAutocomplete
              v-model="form.manager"
              :items="managerOptions"
              :label="u('reports_to')"
              :hint="k('who_approves')"
              persistent-hint
              clearable
              :error-messages="form.errors.manager"
              style="min-width:14rem;flex:1"
            />
            <VCombobox v-model="form.occupation" :items="COMMON_OCCUPATIONS" :label="u('occupation')" :hint="k('pick_or_type')" persistent-hint style="min-width:14rem;flex:1" />
          </div>

          <div class="d-flex flex-wrap ga-3 mb-3">
            <VTextField v-model="form.id_number" :label="k('sa_id')" :hint="k('id_or_passport')" persistent-hint style="min-width:14rem;flex:1" />
            <VTextField v-model="form.passport_number" :label="k('passport')" :hint="k('if_no_id')" persistent-hint style="min-width:14rem;flex:1" />
          </div>

          <div class="d-flex flex-wrap ga-3 mb-3">
            <VTextField v-model="form.tax_number" :label="k('tax_number')" style="min-width:12rem;flex:1" />
            <VTextField v-model="form.email" :label="k('email')" :error-messages="form.errors.email" style="min-width:12rem;flex:1" />
            <VTextField v-model="form.phone" :label="k('phone')" style="min-width:10rem;flex:1" />
          </div>

          <div class="d-flex flex-wrap ga-3 mb-3">
            <VSelect v-model="form.payment_type" :items="['salary','wage']" :label="u('type')" style="min-width:9rem" />
            <!-- Intern and learnership switch the tax incentive on by themselves. Forgetting a
                 separate opt-in box is invisible — the claim simply never happens — so the category
                 the incentive exists for does it. -->
            <VSelect
              v-model="form.employment_category"
              :items="[
                { title: t('bureau.eti.permanent'), value: 'permanent' },
                { title: t('bureau.eti.fixed_term'), value: 'fixed_term' },
                { title: t('bureau.eti.intern'), value: 'intern' },
                { title: t('bureau.eti.learnership'), value: 'learnership' },
                { title: t('bureau.eti.casual'), value: 'casual' },
              ]"
              :label="t('bureau.eti.category')"
              :hint="t('bureau.eti.intern_note')"
              persistent-hint
              clearable
              :error-messages="form.errors.employment_category"
              style="min-width:14rem;flex:1"
            />
            <!-- Blank inherits the client's cycle, which is what almost everybody is on. Stating it
                 is what lets weekly wage staff sit alongside monthly salaried staff. -->
            <VSelect
              v-model="form.pay_frequency"
              :items="[
                { title: `Same as company (${company.pay_frequency})`, value: null },
                { title: 'Weekly', value: 'weekly' },
                { title: 'Fortnightly', value: 'fortnightly' },
                { title: 'Monthly', value: 'monthly' },
              ]"
              :label="t('bureau.freq.pay_frequency')"
              :error-messages="form.errors.pay_frequency"
              style="min-width:13rem"
            />
            <VTextField v-model.number="form.basic_salary" type="number" :label="k('basic_period')" style="min-width:12rem;flex:1" />
          </div>

          <div class="d-flex flex-wrap ga-3 mb-3">
            <VSelect v-model="form.bank_name" :items="SA_BANKS" item-title="title" item-value="title" :label="k('bank')" @update:model-value="onBankChange" style="min-width:14rem;flex:1" />
            <VTextField v-model="form.branch_code" :label="u('branch_code')" :hint="k('auto_filled')" persistent-hint style="min-width:10rem" />
            <VTextField v-model="form.account_number" :label="u('account_number')" style="min-width:12rem;flex:1" />
          </div>

          <div class="text-caption text-medium-emphasis mb-2">
            Medical and retirement — leave these at nought if they do not apply.
          </div>
          <div class="d-flex flex-wrap ga-3">
            <VTextField v-model.number="form.medical_members" type="number" :label="k('medical_members')" :error-messages="form.errors.medical_members" style="min-width:11rem;flex:1" />
            <VTextField v-model.number="form.medical_dependants" type="number" :label="k('dependants')" :error-messages="form.errors.medical_dependants" style="min-width:11rem;flex:1" />
            <VTextField v-model.number="form.retirement_contribution" type="number" :label="k('retirement')" :error-messages="form.errors.retirement_contribution" style="min-width:11rem;flex:1" />
          </div>
        </VCardText>

        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false; editing = null">Cancel</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ editing ? k('save_changes') : u('add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reads as a considered financial decision, not a toast: it names the period, itemises what
         each leave type would gain, totals it, and says plainly what the days become. -->
    <VDialog v-model="backfillDialog" max-width="580" :persistent="backfillSaving">
      <VCard>
        <VCardItem>
          <template #prepend>
            <VAvatar color="warning" variant="tonal" size="40"><VIcon icon="tabler-history" /></VAvatar>
          </template>
          <VCardTitle>{{ t('bureau.backfill.title') }}</VCardTitle>
          <VCardSubtitle v-if="backfillOffer?.name">{{ backfillOffer.name }}</VCardSubtitle>
        </VCardItem>
        <VDivider />

        <VCardText>
          <div v-if="backfillLoading" class="py-6 text-center">
            <VProgressCircular indeterminate color="primary" size="32" />
            <p class="text-body-2 text-medium-emphasis mt-3 mb-0">{{ t('bureau.backfill.working_out') }}</p>
          </div>

          <template v-else-if="backfillOffer?.available">
            <p class="text-body-2 mb-4">
              This employee started before the system knew about them, so
              <strong>{{ backfillOffer.months }} month(s)</strong> of leave were never granted. The
              accrual engine only ever runs the current month and will not go back for these.
            </p>

            <div class="d-flex align-center ga-2 mb-4">
              <VIcon icon="tabler-calendar-repeat" size="18" class="text-medium-emphasis" />
              <span class="text-body-2 text-medium-emphasis">{{ backfillPeriod }}</span>
            </div>

            <VTable density="compact" class="border rounded mb-4">
              <thead>
                <tr>
                  <th>{{ u('leave_type') }}</th>
                  <th class="text-end">{{ k('months') }}</th>
                  <th class="text-end">{{ k('days_to_grant') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in backfillOffer.types" :key="t.code">
                  <td>{{ t.name }}</td>
                  <td class="text-end text-medium-emphasis">{{ t.periods }}</td>
                  <td class="text-end font-weight-medium">{{ t.days }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="2" class="font-weight-medium">Total</td>
                  <td class="text-end font-weight-bold">{{ backfillOffer.total_days }} day(s)</td>
                </tr>
              </tfoot>
            </VTable>

            <VAlert type="warning" variant="tonal" density="compact">
              These become part of the employee's balance and are paid out on termination. If their
              history already came across on an opening balance, skip this — it would pay them twice.
            </VAlert>
          </template>

          <VAlert v-else type="info" variant="tonal" density="compact">
            Nothing to catch up — this employee is already up to date.
          </VAlert>
        </VCardText>

        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="text" :disabled="backfillSaving" @click="backfillDialog = false">{{ u('not_now') }}</VBtn>
          <VBtn
            v-if="backfillOffer?.available"
            color="warning"
            variant="flat"
            prepend-icon="tabler-check"
            :loading="backfillSaving"
            @click="confirmBackfill"
          >
            Recalculate and allocate
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="importDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ k('import_title') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <p class="text-body-2 text-medium-emphasis mb-0">
            1. Download the template, 2. fill in one employee per row, 3. upload it here — all rows are saved under
            <strong>{{ company.name }}</strong>. Rows missing a first/last name are skipped.
          </p>
          <p class="text-body-2 text-medium-emphasis mb-0">
            Keep your own <strong>employee numbers</strong> in the first column — they are matched on re-upload, so
            correcting the file and uploading it again updates people instead of duplicating them. Leave the column
            blank only for someone genuinely new, and one will be issued.
            <strong>Started on</strong> is what leave accrues from; anyone imported without it accrues nothing.
          </p>
          <VBtn variant="tonal" prepend-icon="tabler-download" @click="downloadTemplate">{{ k('download_template') }}</VBtn>
          <VFileInput :label="k('filled_csv')" accept=".csv,text/csv" :error-messages="importForm.errors.file"
            @update:model-value="(f: any) => importForm.file = Array.isArray(f) ? f[0] : f" />
        </VCardText>
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="text" @click="importDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="importForm.processing" :disabled="!importForm.file" @click="uploadCsv">{{ k('upload_save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
