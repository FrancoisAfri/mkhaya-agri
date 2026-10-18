<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useDisplay } from 'vuetify'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'
import Details from './Details.vue'
import Setup from './Setup.vue'
import AgentCertificate from './AgentCertificate.vue'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Stage = {
  id: string; code: string; label: string
  is_fixed: boolean
  apportionment: string | null; progress: string | null
  stage_fee: string; value_to_date: string; previously_claimed: string; this_account: string
}

type Invoice = {
  id: string; invoice_number: string; account_number: number; status: string; currency: string
  project: string | null; project_code: string | null
  client_name: string | null; client_vat_number: string | null; attention: string | null
  invoice_date: string | null; period_start: string | null; period_end: string | null
  description: string | null
  vat_rate: string; disbursement_fee_rate: string
  fees_now_claimed: string; disbursements_now_claimed: string
  subtotal: string; vat_amount: string; amount_now_due: string
  generated_at: string | null
  stages: Stage[]
}

const props = defineProps<{
  invoice: Invoice
  feeAccount: Record<string, any>
  disbursements: Record<string, any>
  annexures: Array<Record<string, any>>
  catalogue: Array<{ number: number; title: string; line_type: string }>
  documents: Array<{ kind: string; label: string }>
  feeAccountHeader: Record<string, any>
  disbursementRows: Array<Record<string, any>>
  certificate: Record<string, any> | null
  can: { edit: boolean; generate: boolean; cancel: boolean; addCertificate?: boolean }
}>()

function money(amount: string | null | undefined) {
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: props.invoice.currency || 'ZAR' })
      .format(Number(amount))
  } catch {
    return `${props.invoice.currency} ${amount}`
  }
}

function percent(value: string | null) {
  return value === null ? '—' : `${(Number(value) * 100).toFixed(2)}%`
}

const reload = { preserveScroll: true, preserveState: false }
const confirmer = ref()

/**
 * Add the Implementing Agent's payment certificate to this account.
 *
 * Only offered where the practice takes those appointments and this account has none — see
 * InvoicingController::openCertificate.
 */
function addCertificate() {
  router.post(route('project-management.invoicing.certificate.open', props.invoice.id), {}, reload)
}

function recalculate() {
  router.post(route('project-management.invoicing.recalculate', props.invoice.id), {}, reload)
}

function generate() {
  confirmer.value.open({
    title: t('project_management.invoicing.confirm_generate_title'),
    message: t('project_management.invoicing.confirm_generate'),
    confirmText: t('project_management.invoicing.generate'),
    confirmColor: 'primary',
    icon: 'tabler-file-check',
  }, () => router.post(route('project-management.invoicing.generate', props.invoice.id), {}, reload))
}

const cancelForm = useForm({ reason: '' })
const cancelDialog = ref(false)

function submitCancel() {
  cancelForm.post(route('project-management.invoicing.cancel', props.invoice.id), {
    preserveScroll: true,
    onSuccess: () => { cancelDialog.value = false; cancelForm.reset() },
  })
}

/**
 * Save one edited cell on a stage row.
 *
 * Both columns go through here. The apportionment and the progress are the two numbers a person
 * actually changes, and the earlier version made only progress editable — and then only when an
 * apportionment already existed, so a freshly seeded row could not be edited at all.
 */
function saveStageField(stage: Stage, field: 'apportionment' | 'progress', value: string) {
  const proportion = value === '' ? null : Number(value) / 100
  if (proportion === null || Number.isNaN(proportion)) return

  router.patch(
    route('project-management.invoicing.stages.update', [props.invoice.id, stage.id]),
    { [field]: proportion },
    reload,
  )
}

/** The split has to add up to the fee; anything else silently over- or under-claims. */
const apportionmentTotal = computed(() =>
  props.invoice.stages
    .filter(s => !s.is_fixed)
    .reduce((sum, s) => sum + Number(s.apportionment ?? 0), 0),
)

const apportionmentBalanced = computed(() => Math.abs(apportionmentTotal.value - 1) < 0.0001)

/**
 * A plain navigation, not XHR — the browser has to receive it as a download.
 * Same reason the Insights export does it this way.
 */
function download(kind: string, format: 'pdf' | 'xlsx') {
  window.location.href =
    route('project-management.invoicing.download', [props.invoice.id, kind]) + `?format=${format}`
}

const annexureForm = useForm({ number: null as number | null })

// ── Filling the account in ───────────────────────────────────────────────────

function seedStages() {
  router.post(route('project-management.invoicing.stages.seed', props.invoice.id), {}, reload)
}

const stageDialog = ref(false)
const stageForm = useForm({ code: '', label: '', apportionment: '' })

function saveStage() {
  stageForm
    .transform(d => ({
      code: d.code,
      label: d.label,
      apportionment: d.apportionment === '' ? null : Number(d.apportionment) / 100,
    }))
    .post(route('project-management.invoicing.stages.store', props.invoice.id), {
      preserveScroll: true,
      onSuccess: () => { stageDialog.value = false; stageForm.reset() },
    })
}

function removeStage(stage: Stage) {
  confirmer.value.open({
    title: t('project_management.invoicing.confirm_remove_stage_title'),
    message: t('project_management.invoicing.confirm_remove_stage', { code: stage.code }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(
    route('project-management.invoicing.stages.destroy', [props.invoice.id, stage.id]), reload,
  ))
}

// A line on an annexure. Which fields matter depends on the annexure's line type, so the dialog
// shows only those — a travelling sheet has no appointment amount and a geotech sheet has no km.
const lineDialog = ref(false)
const lineAnnexure = ref<Record<string, any> | null>(null)
const lineForm = useForm({
  entry_date: '', party: '', description: '', post_level: '', size: '',
  quantity: '', tariff: '', kilometres: '', less_kilometres: '',
  hours: '', less_hours: '', appointment_amount: '',
})

function openLine(annexure: Record<string, any>) {
  lineAnnexure.value = annexure
  lineForm.reset()
  lineDialog.value = true
}

function saveLine() {
  if (!lineAnnexure.value) return
  lineForm
    .transform(d => Object.fromEntries(Object.entries(d).filter(([, v]) => v !== '')))
    .post(route('project-management.invoicing.annexure_items.store', [props.invoice.id, lineAnnexure.value.id]), {
      preserveScroll: true,
      onSuccess: () => { lineDialog.value = false; lineForm.reset() },
    })
}

function removeLine(itemId: string) {
  router.delete(route('project-management.invoicing.annexure_items.destroy', [props.invoice.id, itemId]), reload)
}

const disbursementDialog = ref(false)
const disbursementForm = useForm({ description: '', reference: '', this_account: '' })

function saveDisbursement() {
  disbursementForm
    .transform(d => ({ ...d, block: 'category', this_account: d.this_account || 0 }))
    .post(route('project-management.invoicing.disbursements.store', props.invoice.id), {
      preserveScroll: true,
      onSuccess: () => { disbursementDialog.value = false; disbursementForm.reset() },
    })
}

function removeDisbursement(rowId: string) {
  router.delete(route('project-management.invoicing.disbursements.destroy', [props.invoice.id, rowId]), reload)
}

/** Which inputs an annexure's line dialog should show. */
const lineFields = computed(() => {
  switch (lineAnnexure.value?.line_type) {
    case 'documentation': return ['quantity', 'size', 'description', 'tariff']
    case 'travelling': return ['entry_date', 'party', 'tariff', 'kilometres', 'less_kilometres', 'description']
    case 'travelling_time': return ['entry_date', 'party', 'post_level', 'tariff', 'kilometres', 'less_hours']
    case 'time_basis': return ['description', 'tariff', 'hours']
    default: return ['entry_date', 'party', 'description', 'appointment_amount']
  }
})

function addAnnexure() {
  if (!annexureForm.number) return
  annexureForm.post(route('project-management.invoicing.annexures.store', props.invoice.id), {
    preserveScroll: true,
    onSuccess: () => annexureForm.reset(),
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ props.invoice.invoice_number }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ props.invoice.project_code }} — {{ props.invoice.project }}
          · {{ t('project_management.invoicing.account_no', { number: props.invoice.account_number }) }}
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-arrow-left" @click="router.get(route('project-management.invoicing.index'))">
          {{ t('project_management.invoicing.back_to_accounts') }}
        </VBtn>
        <VBtn v-if="props.can.edit" variant="tonal" prepend-icon="tabler-refresh" @click="recalculate">
          {{ t('project_management.invoicing.recalculate') }}
        </VBtn>
        <VBtn v-if="props.can.generate" color="primary" prepend-icon="tabler-file-check" @click="generate">
          {{ t('project_management.invoicing.generate') }}
        </VBtn>
        <VMenu>
          <template #activator="{ props: menu }">
            <VBtn
              v-bind="menu"
              variant="tonal"
              prepend-icon="tabler-download"
              append-icon="tabler-chevron-down"
            >
              {{ t('project_management.invoicing.download') }}
            </VBtn>
          </template>
          <VList density="compact">
            <template v-for="doc in props.documents" :key="doc.kind">
              <VListItem :title="doc.label" class="text-body-2">
                <template #append>
                  <VBtn size="x-small" variant="text" icon="tabler-file-type-pdf" @click="download(doc.kind, 'pdf')" />
                  <VBtn size="x-small" variant="text" icon="tabler-file-spreadsheet" @click="download(doc.kind, 'xlsx')" />
                </template>
              </VListItem>
            </template>
          </VList>
        </VMenu>
        <VBtn v-if="props.can.cancel" variant="text" color="error" @click="cancelDialog = true">
          {{ t('project_management.invoicing.cancel_account') }}
        </VBtn>
      </div>
    </div>

    <!-- An issued account is read-only; saying so beats disabled fields nobody can explain. -->
    <VAlert v-if="!props.can.edit" type="info" variant="tonal" density="comfortable" class="mb-4">
      {{ t('project_management.invoicing.frozen_note', { at: props.invoice.generated_at ?? '' }) }}
    </VAlert>

    <!-- The disbursement fee block carrying a different rate from the rest of the account. -->
    <VAlert
      v-if="props.disbursements?.fee_block?.rate_looks_stale"
      type="warning"
      variant="tonal"
      density="comfortable"
      class="mb-4"
    >
      {{ t('project_management.invoicing.rate_mismatch', {
        block: percent(props.invoice.disbursement_fee_rate),
        vat: percent(props.invoice.vat_rate),
      }) }}
    </VAlert>

    <!-- Who it is to, who it is from, where to pay. Open by default until a client is named. -->
    <Details
      :invoice-id="props.invoice.id"
      :invoice="props.invoice"
      :editable="props.can.edit"
      class="mb-4"
    />

    <!-- What the fee is calculated from. Nothing earns anything until this is stated. -->
    <Setup
      :invoice-id="props.invoice.id"
      :fee-account="props.feeAccountHeader"
      :currency="props.invoice.currency"
      :editable="props.can.edit"
      class="mb-4"
    />

    <VRow>
      <!-- ── Amount now due ─────────────────────────────────────────────── -->
      <VCol cols="12" md="4">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.invoicing.amount_now_due') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.invoicing.vat_excl_note') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2">{{ t('project_management.invoicing.sub_total_fees') }}</span>
              <span class="text-body-2">{{ money(props.invoice.fees_now_claimed) }}</span>
            </div>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2">{{ t('project_management.invoicing.sub_total_disbursements') }}</span>
              <span class="text-body-2">{{ money(props.invoice.disbursements_now_claimed) }}</span>
            </div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2">{{ t('project_management.invoicing.total_now_claimed') }}</span>
              <span class="text-body-2">{{ money(props.invoice.subtotal) }}</span>
            </div>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-body-2">{{ t('project_management.invoicing.vat', { rate: percent(props.invoice.vat_rate) }) }}</span>
              <span class="text-body-2">{{ money(props.invoice.vat_amount) }}</span>
            </div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between">
              <span class="text-h6">{{ t('project_management.invoicing.amount_now_due') }}</span>
              <span class="text-h6">{{ money(props.invoice.amount_now_due) }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Form 1 ─────────────────────────────────────────────────────── -->
      <VCol cols="12" md="8">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('project_management.invoicing.form_1') }}</VCardTitle>
            <VCardSubtitle>{{ t('project_management.invoicing.form_1_sub') }}</VCardSubtitle>
            <template #append>
              <div v-if="props.can.edit" class="d-flex gap-2">
                <!-- Eight rows typed by hand on every account is how one ends up missing. -->
                <VBtn
                  v-if="!props.invoice.stages.length"
                  size="small" variant="tonal" prepend-icon="tabler-list-numbers"
                  @click="seedStages"
                >
                  {{ t('project_management.invoicing.use_standard_stages') }}
                </VBtn>
                <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="stageDialog = true">
                  {{ t('project_management.invoicing.add_stage') }}
                </VBtn>
              </div>
            </template>
          </VCardItem>
          <div class="table-scroll">
            <VTable v-if="props.invoice.stages.length" density="compact">
              <thead>
                <tr>
                  <th class="text-no-wrap sticky-col">{{ t('project_management.invoicing.col_stage') }}</th>
                  <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_apportionment') }}</th>
                  <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_progress') }}</th>
                  <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_stage_fee') }}</th>
                  <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_value_to_date') }}</th>
                  <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_previously_claimed') }}</th>
                  <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_this_account') }}</th>
                  <th v-if="props.can.edit" />
                </tr>
              </thead>
              <tbody>
                <tr v-for="stage in props.invoice.stages" :key="stage.id">
                  <td class="text-no-wrap sticky-col">
                    <strong>{{ stage.code }}</strong> {{ stage.label }}
                  </td>
                  <td class="text-end text-no-wrap" style="min-inline-size: 8rem">
                    <VTextField
                      v-if="props.can.edit && !stage.is_fixed"
                      :model-value="stage.apportionment === null ? '' : (Number(stage.apportionment) * 100).toFixed(2)"
                      density="compact" variant="outlined" hide-details
                      inputmode="decimal" suffix="%"
                      @change="(e: any) => saveStageField(stage, 'apportionment', e.target.value)"
                    />
                    <span v-else>{{ percent(stage.apportionment) }}</span>
                  </td>
                  <td class="text-end text-no-wrap" style="min-inline-size: 8rem">
                    <VTextField
                      v-if="props.can.edit && !stage.is_fixed"
                      :model-value="stage.progress === null ? '' : (Number(stage.progress) * 100).toFixed(2)"
                      density="compact" variant="outlined" hide-details
                      inputmode="decimal" suffix="%"
                      @change="(e: any) => saveStageField(stage, 'progress', e.target.value)"
                    />
                    <span v-else>{{ percent(stage.progress) }}</span>
                  </td>
                  <td class="text-end text-no-wrap">{{ money(stage.stage_fee) }}</td>
                  <td class="text-end text-no-wrap">{{ money(stage.value_to_date) }}</td>
                  <td class="text-end text-no-wrap">{{ money(stage.previously_claimed) }}</td>
                  <td class="text-end text-no-wrap"><strong>{{ money(stage.this_account) }}</strong></td>
                  <td v-if="props.can.edit" class="text-end">
                    <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="removeStage(stage)" />
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <th class="sticky-col">{{ t('project_management.invoicing.total_apportionment') }}</th>
                  <th class="text-end text-no-wrap" :class="apportionmentBalanced ? '' : 'text-error'">
                    {{ (apportionmentTotal * 100).toFixed(2) }}%
                  </th>
                  <th colspan="6" class="text-caption font-weight-regular">
                    <span v-if="!apportionmentBalanced" class="text-error">
                      {{ t('project_management.invoicing.apportionment_warning') }}
                    </span>
                  </th>
                </tr>
              </tfoot>
            </VTable>
            <VCardText v-else class="text-center text-medium-emphasis py-8">
              {{ t('project_management.invoicing.no_stages') }}
              <div class="text-caption mt-2">{{ t('project_management.invoicing.no_stages_hint') }}</div>
            </VCardText>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Annexures ──────────────────────────────────────────────────────── -->
    <VCard border class="mt-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.invoicing.annexures') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.invoicing.annexures_sub') }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <div v-if="props.can.edit" class="d-flex gap-3 flex-wrap mb-4">
          <VSelect
            v-model="annexureForm.number"
            :items="props.catalogue.map(a => ({ title: `${a.number} — ${a.title}`, value: a.number }))"
            :label="t('project_management.invoicing.add_annexure')"
            density="compact" variant="outlined" hide-details
            style="max-width: 340px"
          />
          <VBtn variant="tonal" :loading="annexureForm.processing" :disabled="!annexureForm.number" @click="addAnnexure">
            {{ t('project_management.actions.add') }}
          </VBtn>
        </div>

        <VTable v-if="props.annexures.length" density="compact">
          <thead>
            <tr>
              <th class="text-no-wrap">{{ t('project_management.invoicing.col_annexure') }}</th>
              <th>{{ t('project_management.invoicing.col_lines') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_previously_claimed') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_this_invoice') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_total') }}</th>
              <th v-if="props.can.edit" />
            </tr>
          </thead>
          <tbody>
            <template v-for="annexure in props.annexures" :key="annexure.id">
              <tr>
                <td class="text-no-wrap"><strong>{{ annexure.number }}</strong> — {{ annexure.title }}</td>
                <td>{{ annexure.items.length }}</td>
                <td class="text-end text-no-wrap">{{ money(annexure.previously_claimed) }}</td>
                <td class="text-end text-no-wrap">{{ money(annexure.this_invoice) }}</td>
                <td class="text-end text-no-wrap">{{ money(annexure.total) }}</td>
                <td v-if="props.can.edit" class="text-end text-no-wrap">
                  <VBtn size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="openLine(annexure)">
                    {{ t('project_management.invoicing.add_line') }}
                  </VBtn>
                </td>
              </tr>
              <!-- The lines themselves, indented under their annexure. -->
              <tr v-for="item in annexure.items" :key="item.id" class="text-medium-emphasis">
                <td class="pl-8 text-caption">
                  {{ item.entry_date ?? '' }} {{ item.party ?? item.description ?? '' }}
                </td>
                <td class="text-caption" colspan="2">{{ item.description ?? '' }}</td>
                <td class="text-end text-caption text-no-wrap">{{ money(item.this_invoice) }}</td>
                <td class="text-end text-caption text-no-wrap">{{ money(item.total) }}</td>
                <td v-if="props.can.edit" class="text-end">
                  <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="removeLine(item.id)" />
                </td>
              </tr>
            </template>
          </tbody>
        </VTable>
        <div v-else class="text-center text-medium-emphasis py-8">
          {{ t('project_management.invoicing.no_annexures') }}
        </div>
      </VCardText>
    </VCard>

    <!-- ── Disbursements typed by hand ────────────────────────────────────── -->
    <VCard border class="mt-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.invoicing.disbursements') }}</VCardTitle>
        <VCardSubtitle>{{ t('project_management.invoicing.disbursements_sub') }}</VCardSubtitle>
        <template #append>
          <VBtn
            v-if="props.can.edit"
            size="small" variant="tonal" prepend-icon="tabler-plus"
            @click="disbursementDialog = true"
          >
            {{ t('project_management.invoicing.add_disbursement') }}
          </VBtn>
        </template>
      </VCardItem>
      <VTable v-if="props.disbursementRows.length" density="compact">
        <thead>
          <tr>
            <th>{{ t('project_management.invoicing.col_description') }}</th>
            <th>{{ t('project_management.invoicing.col_annexure') }}</th>
            <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_previously_claimed') }}</th>
            <th class="text-end text-no-wrap">{{ t('project_management.invoicing.col_this_account') }}</th>
            <th v-if="props.can.edit" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.disbursementRows" :key="row.id">
            <td>{{ row.description }}</td>
            <td class="text-medium-emphasis">{{ row.reference ?? '—' }}</td>
            <td class="text-end text-no-wrap">{{ money(row.previously_claimed) }}</td>
            <td class="text-end text-no-wrap">{{ money(row.this_account) }}</td>
            <td v-if="props.can.edit" class="text-end">
              <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="removeDisbursement(row.id)" />
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.invoicing.no_disbursements') }}
      </VCardText>
    </VCard>

    <!-- ── The Implementing Agent's payment certificate ───────────────────── -->
    <AgentCertificate
      v-if="props.certificate"
      :invoice-id="props.invoice.id"
      :certificate="props.certificate"
      :can-edit="props.can.edit"
      :currency="props.invoice.currency"
    />

    <VCard v-else-if="props.can.addCertificate" border class="mt-6">
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3">
        <div>
          <div class="text-body-1">{{ t('project_management.invoicing.certificate_title') }}</div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('project_management.invoicing.certificate_add_hint') }}
          </div>
        </div>
        <VBtn variant="tonal" prepend-icon="tabler-certificate" @click="addCertificate">
          {{ t('project_management.invoicing.certificate_add') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- ── Add a stage ────────────────────────────────────────────────────── -->
    <VDialog v-model="stageDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem><VCardTitle>{{ t('project_management.invoicing.add_stage') }}</VCardTitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="4">
              <VTextField
                v-model="stageForm.code" :label="t('project_management.invoicing.col_stage')"
                :error-messages="stageForm.errors.code" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="8">
              <VTextField
                v-model="stageForm.label" :label="t('project_management.invoicing.field_description')"
                :error-messages="stageForm.errors.label" variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="stageForm.apportionment" inputmode="decimal" suffix="%"
                :label="t('project_management.invoicing.col_apportionment')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="stageDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="stageForm.processing" @click="saveStage">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Add an annexure line ───────────────────────────────────────────── -->
    <VDialog v-model="lineDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.invoicing.add_line') }}</VCardTitle>
          <VCardSubtitle v-if="lineAnnexure">{{ lineAnnexure.number }} — {{ lineAnnexure.title }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol v-for="field in lineFields" :key="field" cols="12" md="6">
              <VTextField
                v-model="(lineForm as any)[field]"
                :type="field === 'entry_date' ? 'date' : 'text'"
                :inputmode="['tariff','quantity','kilometres','less_kilometres','hours','less_hours','appointment_amount'].includes(field) ? 'decimal' : undefined"
                :label="t(`project_management.invoicing.line_${field}`)"
                :error-messages="(lineForm.errors as any)[field]"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="lineDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="lineForm.processing" @click="saveLine">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Add a disbursement ─────────────────────────────────────────────── -->
    <VDialog v-model="disbursementDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.invoicing.add_disbursement') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.invoicing.add_disbursement_hint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="disbursementForm.description"
                :label="t('project_management.invoicing.col_description')"
                :error-messages="disbursementForm.errors.description"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="disbursementForm.this_account" inputmode="decimal"
                :label="t('project_management.invoicing.col_this_account')" :prefix="props.invoice.currency"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="disbursementDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="disbursementForm.processing" @click="saveDisbursement">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Cancel ─────────────────────────────────────────────────────────── -->
    <VDialog v-model="cancelDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.invoicing.cancel_account') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.invoicing.cancel_hint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextarea
            v-model="cancelForm.reason"
            rows="3"
            :label="t('project_management.invoicing.cancel_reason')"
            :error-messages="cancelForm.errors.reason"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="cancelDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="error" :loading="cancelForm.processing" @click="submitCancel">
            {{ t('project_management.invoicing.cancel_account') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>

<style scoped>
/* Wide financial tables scroll inside their card rather than pushing the page sideways. */
.table-scroll {
  overflow-x: auto;
  max-block-size: 60vh;
  overflow-y: auto;
}

/*
 * Which stage a row is stays visible while the money columns scroll.
 * Without this the first thing to disappear is the only column that identifies the row.
 */
.sticky-col {
  position: sticky;
  inset-inline-start: 0;
  z-index: 1;
  background: rgb(var(--v-theme-surface));
}
</style>
