<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The Implementing Agent's payment certificate — the workbook's `AI` sheet.
 *
 * Every figure on the right of an input is calculated server-side and read back, so the page can
 * never disagree with the PDF. Edits save on blur and reload only this section: the schedule is
 * seven columns wide and losing your place after every cell is what makes a screen like this
 * unusable.
 */
const props = defineProps<{
  invoiceId: string
  certificate: Record<string, any>
  canEdit: boolean
  currency: string
}>()

function money(amount: string | number | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: props.currency || 'ZAR' })
      .format(Number(amount))
  } catch {
    return String(amount)
  }
}

const calc = computed(() => props.certificate.calculated)

/** Reload just this block, keeping scroll and every other input where it was. */
const quiet = { preserveState: true, preserveScroll: true, only: ['certificate'] }

// ── The editable fields, held locally so typing is not fighting a round trip ──
const heading = ref({ ...props.certificate.heading })
const inputs = ref({ ...props.certificate.inputs })
const signatories = ref({ ...props.certificate.signatories })

watch(() => props.certificate, (fresh) => {
  heading.value = { ...fresh.heading }
  inputs.value = { ...fresh.inputs }
  signatories.value = { ...fresh.signatories }
})

function save(field: string, value: unknown) {
  if (!props.canEdit) return
  router.put(route('project-management.invoicing.certificate.update', props.invoiceId), { [field]: value }, quiet)
}

const headingFields = [
  'implementing_agent', 'project_funder', 'tender_number', 'project_name',
  'certificate_number', 'completion_certificate',
] as const

const valueFields = ['total_approved_value', 'total_construction_cost'] as const

const constructionFields = [
  'work_done_to_date', 'nominated_subcontractors', 'escalation', 'penalties_recovered',
  'materials_on_site', 'previously_certified',
] as const

const signatoryFields = [
  'agent_name', 'project_manager_name', 'pmu_manager_name',
  'technical_manager_name', 'cfo_name', 'municipal_manager_name',
] as const

const ANSWERS = ['YES', 'NO', 'N/A']

function answerKpi(id: string, answer: string | null) {
  router.patch(route('project-management.invoicing.certificate.kpis.update', [props.invoiceId, id]), { answer }, quiet)
}

// ── The payment schedule ────────────────────────────────────────────────────
const paymentDialog = ref(false)
const paymentForm = useForm({
  paid_on: '', service_provider: '', to_consultant: '', to_contractor: '', cession: '',
})

function addPayment() {
  paymentForm.post(route('project-management.invoicing.certificate.payments.store', props.invoiceId), {
    preserveScroll: true,
    onSuccess: () => { paymentDialog.value = false; paymentForm.reset() },
  })
}

function savePaymentCell(id: string, field: string, value: string) {
  router.patch(
    route('project-management.invoicing.certificate.payments.update', [props.invoiceId, id]),
    { [field]: value },
    quiet,
  )
}

function removePayment(id: string) {
  router.delete(
    route('project-management.invoicing.certificate.payments.destroy', [props.invoiceId, id]),
    { preserveScroll: true },
  )
}
</script>

<template>
  <VCard border class="mt-6">
    <VCardItem>
      <VCardTitle class="text-body-1">
        {{ t('project_management.invoicing.certificate_title') }}
      </VCardTitle>
      <VCardSubtitle>{{ t('project_management.invoicing.certificate_sub') }}</VCardSubtitle>
    </VCardItem>

    <!-- The sheet's own warning, and the reason the ceiling is on the form at all. -->
    <VCardText v-if="calc.management_fee.exceeds_maximum" class="pt-0">
      <VAlert type="error" variant="tonal" density="compact" icon="tabler-alert-triangle">
        {{ calc.management_fee.warning }}
      </VAlert>
    </VCardText>

    <VCardText v-if="calc.anomalies.includes('subtotal_includes_middle_rows')" class="pt-0">
      <VAlert type="info" variant="tonal" density="compact" icon="tabler-info-circle">
        {{ t('project_management.invoicing.certificate_subtotal_note') }}
      </VAlert>
    </VCardText>

    <VCardText>
      <!-- ── Heading ──────────────────────────────────────────────────────── -->
      <VRow dense>
        <VCol v-for="field in headingFields" :key="field" cols="12" md="4">
          <VTextField
            v-model="heading[field]" :readonly="!props.canEdit"
            :label="t('project_management.invoicing.cert_' + field)"
            variant="outlined" density="compact"
            @blur="save(field, heading[field])"
          />
        </VCol>
        <VCol v-for="field in valueFields" :key="field" cols="12" md="4">
          <VTextField
            v-model="heading[field]" :readonly="!props.canEdit" inputmode="decimal"
            :label="t('project_management.invoicing.cert_' + field)"
            variant="outlined" density="compact"
            @blur="save(field, heading[field])"
          />
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <!-- ── KPIs ───────────────────────────────────────────────────────────── -->
    <VCardText>
      <div class="text-body-2 font-weight-medium mb-3">
        {{ t('project_management.invoicing.cert_kpis') }}
      </div>
      <VRow dense>
        <VCol v-for="kpi in props.certificate.kpis" :key="kpi.id" cols="12" md="6">
          <div class="d-flex align-center gap-3">
            <div class="text-body-2 flex-grow-1">{{ kpi.question }}</div>
            <VSelect
              :model-value="kpi.answer" :items="ANSWERS" :disabled="!props.canEdit"
              :label="t('project_management.invoicing.cert_yes_or_no')"
              density="compact" variant="outlined" hide-details clearable
              style="max-width: 130px"
              @update:model-value="answerKpi(kpi.id, $event)"
            />
          </div>
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <!-- ── Management fee ─────────────────────────────────────────────────── -->
    <VCardText>
      <div class="text-body-2 font-weight-medium mb-3">
        {{ t('project_management.invoicing.cert_fee_claim') }}
      </div>
      <VRow dense>
        <VCol cols="12" md="4">
          <VTextField
            v-model="inputs.max_fee_base" :readonly="!props.canEdit" inputmode="decimal"
            :label="t('project_management.invoicing.cert_max_fee_base')"
            :hint="t('project_management.invoicing.cert_max_fee_base_hint')" persistent-hint
            variant="outlined" density="compact"
            @blur="save('max_fee_base', inputs.max_fee_base)"
          />
        </VCol>
        <VCol cols="12" md="8">
          <VTable density="compact">
            <tbody>
              <tr>
                <td>{{ t('project_management.invoicing.cert_max_incl_vat') }}</td>
                <td class="text-end figure">{{ money(calc.management_fee.max_incl_vat) }}</td>
              </tr>
              <tr>
                <td>{{ t('project_management.invoicing.cert_total_claimed') }}</td>
                <td class="text-end figure">{{ money(calc.management_fee.total_claimed) }}</td>
              </tr>
              <tr>
                <td>{{ t('project_management.invoicing.cert_not_claimed_yet') }}</td>
                <td class="text-end figure">{{ money(calc.management_fee.not_claimed_yet) }}</td>
              </tr>
              <tr>
                <td>{{ t('project_management.invoicing.cert_claimed_previously') }}</td>
                <td class="text-end figure">{{ money(calc.management_fee.claimed_previously) }}</td>
              </tr>
              <tr class="font-weight-medium">
                <td>{{ t('project_management.invoicing.cert_fee_this_claim') }}</td>
                <td class="text-end figure">{{ money(calc.management_fee.this_claim) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <!-- ── Construction ───────────────────────────────────────────────────── -->
    <VCardText>
      <div class="text-body-2 font-weight-medium mb-3">
        {{ t('project_management.invoicing.cert_construction') }}
      </div>
      <VRow dense>
        <VCol v-for="field in constructionFields" :key="field" cols="12" md="4">
          <VTextField
            v-model="inputs[field]" :readonly="!props.canEdit" inputmode="decimal"
            :label="t('project_management.invoicing.cert_' + field)"
            variant="outlined" density="compact"
            @blur="save(field, inputs[field])"
          />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField
            :model-value="String(Number(inputs.retention_rate ?? 0) * 100)"
            :readonly="!props.canEdit" inputmode="decimal" suffix="%"
            :label="t('project_management.invoicing.cert_retention_rate')"
            variant="outlined" density="compact"
            @blur="save('retention_rate', Number(($event.target as HTMLInputElement).value) / 100)"
          />
        </VCol>
      </VRow>

      <VTable density="compact" class="mt-3">
        <tbody>
          <tr>
            <td>{{ t('project_management.invoicing.cert_subtotal') }}</td>
            <td class="text-end figure">{{ money(calc.construction.subtotal_with_materials) }}</td>
          </tr>
          <tr>
            <td>{{ t('project_management.invoicing.cert_vat') }}</td>
            <td class="text-end figure">{{ money(calc.construction.vat) }}</td>
          </tr>
          <tr>
            <td>{{ t('project_management.invoicing.cert_work_done_incl_vat') }}</td>
            <td class="text-end figure">{{ money(calc.construction.work_done_incl_vat) }}</td>
          </tr>
          <tr>
            <td>{{ t('project_management.invoicing.cert_total_for_construction') }}</td>
            <td class="text-end figure">{{ money(calc.construction.total_for_construction) }}</td>
          </tr>
          <tr>
            <td>{{ t('project_management.invoicing.cert_retention') }}</td>
            <td class="text-end figure">{{ money(calc.construction.retention) }}</td>
          </tr>
          <tr class="font-weight-medium">
            <td>{{ t('project_management.invoicing.cert_due_for_construction') }}</td>
            <td class="text-end figure">{{ money(calc.construction.due_for_construction) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCardText>

    <VDivider />

    <!-- ── Retention account and the payment summary ──────────────────────── -->
    <VCardText>
      <VRow dense>
        <VCol cols="12" md="6">
          <div class="text-body-2 font-weight-medium mb-3">
            {{ t('project_management.invoicing.cert_retention_account') }}
          </div>
          <VTextField
            v-model="inputs.previous_retention_held" :readonly="!props.canEdit" inputmode="decimal"
            :label="t('project_management.invoicing.cert_previous_retention_held')"
            variant="outlined" density="compact" class="mb-2"
            @blur="save('previous_retention_held', inputs.previous_retention_held)"
          />
          <VTable density="compact">
            <tbody>
              <tr>
                <td>{{ t('project_management.invoicing.cert_retention_this') }}</td>
                <td class="text-end figure">{{ money(calc.retention_account.this_certificate) }}</td>
              </tr>
              <tr class="font-weight-medium">
                <td>{{ t('project_management.invoicing.cert_retention_total') }}</td>
                <td class="text-end figure">{{ money(calc.retention_account.total) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCol>

        <VCol cols="12" md="6">
          <div class="text-body-2 font-weight-medium mb-3">
            {{ t('project_management.invoicing.cert_payment_summary') }}
          </div>
          <VTextField
            v-model="inputs.cession_payments" :readonly="!props.canEdit" inputmode="decimal"
            :label="t('project_management.invoicing.cert_cession_payments')"
            variant="outlined" density="compact" class="mb-2"
            @blur="save('cession_payments', inputs.cession_payments)"
          />
          <VTable density="compact">
            <tbody>
              <tr>
                <td>{{ t('project_management.invoicing.cert_management_fee') }}</td>
                <td class="text-end figure">{{ money(calc.payment_summary.management_fee) }}</td>
              </tr>
              <tr>
                <td>{{ t('project_management.invoicing.cert_payment_to_contractor') }}</td>
                <td class="text-end figure">{{ money(calc.payment_summary.payment_to_contractor) }}</td>
              </tr>
              <tr>
                <td>{{ t('project_management.invoicing.cert_cession_payments') }}</td>
                <td class="text-end figure">{{ money(calc.payment_summary.cession_payments) }}</td>
              </tr>
              <!-- Held, not paid — on the block and outside the total, as on the form. -->
              <tr class="text-medium-emphasis">
                <td>{{ t('project_management.invoicing.cert_retention_held_note') }}</td>
                <td class="text-end figure">{{ money(calc.payment_summary.retention) }}</td>
              </tr>
              <tr class="font-weight-bold">
                <td>{{ t('project_management.invoicing.cert_certificate_total') }}</td>
                <td class="text-end figure">{{ money(calc.certificate_total) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />

    <!-- ── Payments made to date ──────────────────────────────────────────── -->
    <VCardText>
      <div class="d-flex align-center justify-space-between mb-3">
        <div class="text-body-2 font-weight-medium">
          {{ t('project_management.invoicing.cert_schedule') }}
        </div>
        <VBtn
          v-if="props.canEdit" size="small" variant="tonal" prepend-icon="tabler-plus"
          @click="paymentDialog = true"
        >
          {{ t('project_management.invoicing.cert_add_payment') }}
        </VBtn>
      </div>

      <div class="table-scroll">
        <VTable density="compact">
          <thead>
            <tr>
              <th>#</th>
              <th class="text-no-wrap">{{ t('project_management.invoicing.cert_paid_on') }}</th>
              <th>{{ t('project_management.invoicing.cert_service_provider') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.cert_to_consultant') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.cert_to_contractor') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.cert_cession') }}</th>
              <th class="text-end text-no-wrap">{{ t('project_management.invoicing.cert_total') }}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in props.certificate.schedule" :key="row.id">
              <td>{{ row.number }}</td>
              <td class="text-no-wrap">{{ row.paid_on ?? '—' }}</td>
              <td>{{ row.service_provider ?? '—' }}</td>
              <td class="text-end">
                <!-- The claim row mirrors the invoice, so it reads rather than edits. -->
                <span v-if="row.is_current_claim" class="figure">{{ money(row.to_consultant) }}</span>
                <VTextField
                  v-else :model-value="row.to_consultant" :readonly="!props.canEdit"
                  inputmode="decimal" density="compact" variant="plain" hide-details
                  class="cell-input"
                  @blur="savePaymentCell(row.id, 'to_consultant', ($event.target as HTMLInputElement).value)"
                />
              </td>
              <td class="text-end">
                <span v-if="row.is_current_claim" class="figure">{{ money(row.to_contractor) }}</span>
                <VTextField
                  v-else :model-value="row.to_contractor" :readonly="!props.canEdit"
                  inputmode="decimal" density="compact" variant="plain" hide-details
                  class="cell-input"
                  @blur="savePaymentCell(row.id, 'to_contractor', ($event.target as HTMLInputElement).value)"
                />
              </td>
              <td class="text-end">
                <span v-if="row.is_current_claim" class="figure">{{ money(row.cession) }}</span>
                <VTextField
                  v-else :model-value="row.cession" :readonly="!props.canEdit"
                  inputmode="decimal" density="compact" variant="plain" hide-details
                  class="cell-input"
                  @blur="savePaymentCell(row.id, 'cession', ($event.target as HTMLInputElement).value)"
                />
              </td>
              <td class="text-end figure">{{ money(row.total) }}</td>
              <td class="text-end">
                <VChip v-if="row.is_current_claim" size="x-small" color="primary" variant="tonal">
                  {{ t('project_management.invoicing.cert_this_claim') }}
                </VChip>
                <VBtn
                  v-else-if="props.canEdit" icon="tabler-trash" size="x-small" variant="text"
                  @click="removePayment(row.id)"
                />
              </td>
            </tr>
            <tr class="font-weight-medium">
              <td colspan="3">{{ t('project_management.invoicing.cert_claimed_previously') }}</td>
              <td class="text-end figure">{{ money(calc.schedule.totals.to_consultant) }}</td>
              <td class="text-end figure">{{ money(calc.schedule.totals.to_contractor) }}</td>
              <td class="text-end figure">{{ money(calc.schedule.totals.cession) }}</td>
              <td class="text-end figure">{{ money(calc.schedule.totals.total) }}</td>
              <td />
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCardText>

    <VDivider />

    <!-- ── Who signs ──────────────────────────────────────────────────────── -->
    <VCardText>
      <div class="text-body-2 font-weight-medium mb-3">
        {{ t('project_management.invoicing.cert_signatories') }}
      </div>
      <VRow dense>
        <VCol v-for="field in signatoryFields" :key="field" cols="12" md="4">
          <VTextField
            v-model="signatories[field]" :readonly="!props.canEdit"
            :label="t('project_management.invoicing.cert_' + field)"
            variant="outlined" density="compact"
            @blur="save(field, signatories[field])"
          />
        </VCol>
        <VCol cols="12">
          <VTextarea
            v-model="inputs.notes" :readonly="!props.canEdit" rows="2" auto-grow
            :label="t('project_management.invoicing.cert_notes')"
            variant="outlined" density="compact"
            @blur="save('notes', inputs.notes)"
          />
        </VCol>
      </VRow>
    </VCardText>

    <!-- ── Add a payment ──────────────────────────────────────────────────── -->
    <VDialog v-model="paymentDialog" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.invoicing.cert_add_payment') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.invoicing.cert_add_payment_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField
                v-model="paymentForm.paid_on" type="date"
                :label="t('project_management.invoicing.cert_paid_on')"
                :error-messages="paymentForm.errors.paid_on"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="paymentForm.service_provider"
                :label="t('project_management.invoicing.cert_service_provider')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="paymentForm.to_consultant" inputmode="decimal"
                :label="t('project_management.invoicing.cert_to_consultant')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="paymentForm.to_contractor" inputmode="decimal"
                :label="t('project_management.invoicing.cert_to_contractor')"
                variant="outlined" density="compact"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField
                v-model="paymentForm.cession" inputmode="decimal"
                :label="t('project_management.invoicing.cert_cession')"
                variant="outlined" density="compact"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="paymentDialog = false">
            {{ t('project_management.actions.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="paymentForm.processing" @click="addPayment">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>

<style scoped>
.figure { font-variant-numeric: tabular-nums; }
.table-scroll { overflow-x: auto; }
.cell-input :deep(input) { text-align: end; font-variant-numeric: tabular-nums; }
</style>
