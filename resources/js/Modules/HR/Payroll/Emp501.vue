<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Code { code: string; label: string; amount: number }
interface Cert { employee_id: number; name: string; id_number: string | null; tax_number: string | null; type: string; periods: number; income: number; paye: number; uif: number; pension: number; codes: Code[] }
interface Month { month: string; label: string; paye: number; uif_employee: number; uif_total: number; sdl: number }
interface Err { entity: string; id: number | null; field: string; message: string }
interface Emp501 {
  period: { tax_year: number; from: string; to: string; label: string }
  header: { company_name: string; paye_reference: string | null; uif_reference: string | null; sdl_reference: string | null; sdl_liable: boolean }
  certificates: Cert[]
  monthly: Month[]
  totals: { certificate_count: number; irp5_count: number; it3a_count: number; total_income: number; total_paye: number; total_uif: number; declared_sdl: number }
  reconciliation: { declared_paye: number; certificate_paye: number; paye_difference: number; declared_uif: number; certificate_uif: number; uif_difference: number; declared_sdl: number; balanced: boolean }
  validation: { validated: number; errors: Err[] }
}
interface Recent { id: number; reference: string; period: string; status: string; version: number; certificates: number | null; reconciled: boolean | null; generated_at: string | null }

const props = defineProps<{ emp501: Emp501; taxYear: number; years: number[]; recent: Recent[] }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const taxYear = ref(props.taxYear)
const errors = computed(() => props.emp501.validation.errors)
const clean = computed(() => errors.value.length === 0)
const rec = computed(() => props.emp501.reconciliation)
const showMonthly = ref(false)
const statusColor: Record<string, string> = { draft: 'grey', generated: 'info', validated: 'primary', submitted: 'warning', accepted: 'success', rejected: 'error' }

function reload() {
  router.get(route('payroll.emp501'), { tax_year: taxYear.value }, { preserveState: true, preserveScroll: true, replace: true })
}

const genForm = useForm<{ tax_year: number; notes: string }>({ tax_year: props.taxYear, notes: '' })
function generate() {
  genForm.tax_year = taxYear.value
  genForm.post(route('payroll.emp501.generate'), { preserveScroll: true })
}
function certUrl(c: Cert) {
  return route('payroll.emp501.certificate', c.employee_id) + '?tax_year=' + taxYear.value
}
</script>

<template>
  <div class="pa-4" style="max-width: 1150px; margin: 0 auto">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('sars.finance_sars') }}</div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sars.emp501_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sars.emp501_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('payroll.emp201')"><VBtn variant="text" prepend-icon="tabler-file-percent">EMP201</VBtn></Link>
        <Link :href="route('finance.accounting.sars.submissions')"><VBtn variant="tonal" prepend-icon="tabler-file-certificate">{{ t('sars.submissions') }}</VBtn></Link>
      </div>
    </div>

    <!-- Tax-year selector -->
    <VCard border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VSelect v-model="taxYear" :items="years" :label="t('sars.tax_year')" density="compact" variant="outlined" hide-details style="max-width: 160px" @update:model-value="reload" />
        <VChip size="small" variant="tonal" color="primary">{{ emp501.period.label }}</VChip>
        <VSpacer />
        <span class="text-caption text-medium-emphasis">{{ emp501.totals.certificate_count }} {{ t('sars.certificates_label') }} · {{ emp501.totals.irp5_count }} IRP5 · {{ emp501.totals.it3a_count }} IT3(a)</span>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Reconciliation -->
      <VCol cols="12" md="7">
        <VCard border rounded="lg" class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-subtitle-1 font-weight-medium">{{ t('sars.reconciliation') }}</span>
              <VChip size="small" :color="rec.balanced ? 'success' : 'error'" variant="tonal" :prepend-icon="rec.balanced ? 'tabler-circle-check' : 'tabler-alert-triangle'">{{ rec.balanced ? t('sars.balanced') : t('sars.not_reconciled') }}</VChip>
            </div>
            <VTable density="comfortable">
              <tbody style="font-variant-numeric: tabular-nums">
                <tr><td class="text-medium-emphasis">{{ t('sars.declared_paye') }}</td><td class="text-right">{{ money(rec.declared_paye) }}</td></tr>
                <tr><td class="text-medium-emphasis">{{ t('sars.certificate_paye') }}</td><td class="text-right">{{ money(rec.certificate_paye) }}</td></tr>
                <tr class="font-weight-bold"><td>{{ t('sars.difference') }} (PAYE)</td><td class="text-right" :class="Math.abs(rec.paye_difference) < 0.01 ? 'text-success' : 'text-error'">{{ money(rec.paye_difference) }}</td></tr>
                <tr><td class="text-medium-emphasis pt-3">UIF ({{ t('sars.difference') }})</td><td class="text-right pt-3">{{ money(rec.uif_difference) }}</td></tr>
                <tr><td class="text-medium-emphasis">SDL</td><td class="text-right">{{ money(rec.declared_sdl) }}</td></tr>
              </tbody>
            </VTable>

            <VBtn size="small" variant="text" class="mt-2" :prepend-icon="showMonthly ? 'tabler-chevron-up' : 'tabler-chevron-down'" @click="showMonthly = !showMonthly">{{ t('sars.monthly_declarations') }}</VBtn>
            <VExpandTransition>
              <VTable v-if="showMonthly" density="compact" class="mt-1" style="font-variant-numeric: tabular-nums">
                <thead><tr><th>{{ t('sars.tax_month') }}</th><th class="text-right">PAYE</th><th class="text-right">UIF</th><th class="text-right">SDL</th></tr></thead>
                <tbody>
                  <tr v-for="m in emp501.monthly" :key="m.month">
                    <td class="text-caption">{{ m.label }}</td>
                    <td class="text-right">{{ money(m.paye) }}</td>
                    <td class="text-right">{{ money(m.uif_total) }}</td>
                    <td class="text-right">{{ money(m.sdl) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VExpandTransition>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Validation + generate -->
      <VCol cols="12" md="5">
        <VCard border rounded="lg" class="mb-4">
          <VCardText>
            <div class="d-flex align-center gap-2 mb-2">
              <VIcon :color="clean ? 'success' : 'warning'">{{ clean ? 'tabler-circle-check' : 'tabler-alert-triangle' }}</VIcon>
              <span class="text-subtitle-1 font-weight-medium">{{ t('sars.validation') }}</span>
            </div>
            <p class="text-body-2 mb-2">
              <strong>{{ emp501.validation.validated }}</strong> {{ t('sars.n_checks') }} ·
              <span :class="clean ? 'text-success' : 'text-error'">{{ clean ? t('sars.all_valid') : t('sars.n_errors', { n: errors.length }) }}</span>
            </p>
            <VList v-if="errors.length" density="compact" class="py-0" style="max-height: 180px; overflow-y: auto">
              <VListItem v-for="(e, i) in errors" :key="i" class="px-0">
                <template #prepend><VIcon size="16" color="error" class="me-2">tabler-point-filled</VIcon></template>
                <VListItemTitle class="text-caption text-wrap">{{ e.message }}</VListItemTitle>
              </VListItem>
            </VList>
            <div v-else class="text-caption text-success">{{ t('sars.emp501_ready') }}</div>
          </VCardText>
        </VCard>

        <VCard border rounded="lg">
          <VCardText>
            <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('sars.generate') }}</div>
            <p class="text-caption text-medium-emphasis mb-3">{{ t('sars.emp501_generate_help') }}</p>
            <VTextarea v-model="genForm.notes" :label="t('sars.notes')" variant="outlined" density="compact" rows="2" hide-details class="mb-3" />
            <VBtn color="primary" block prepend-icon="tabler-file-download" :loading="genForm.processing" :disabled="!emp501.totals.certificate_count" @click="generate">{{ t('sars.generate_emp501') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Certificate schedule -->
    <VCard border rounded="lg" class="mt-4">
      <VCardText class="pb-0"><div class="text-subtitle-1 font-weight-medium">{{ t('sars.certificate_schedule') }}</div></VCardText>
      <VTable style="font-variant-numeric: tabular-nums">
        <thead>
          <tr>
            <th>{{ t('sars.employee') }}</th><th>{{ t('sars.tax_no') }}</th><th>{{ t('sars.type') }}</th>
            <th class="text-right">{{ t('sars.income') }}</th><th class="text-right">PAYE</th><th class="text-right">UIF</th><th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!emp501.certificates.length"><td colspan="7" class="text-center text-medium-emphasis py-6">{{ t('sars.emp501_no_payroll') }}</td></tr>
          <tr v-for="c in emp501.certificates" :key="c.employee_id">
            <td class="font-weight-medium">{{ c.name }}</td>
            <td><span v-if="c.tax_number">{{ c.tax_number }}</span><VChip v-else size="x-small" color="warning" variant="tonal">{{ t('sars.tax_no_missing') }}</VChip></td>
            <td><VChip size="x-small" :color="c.type === 'IRP5' ? 'primary' : 'grey'" variant="tonal">{{ c.type }}</VChip></td>
            <td class="text-right">{{ money(c.income) }}</td>
            <td class="text-right">{{ money(c.paye) }}</td>
            <td class="text-right">{{ money(c.uif) }}</td>
            <td class="text-end"><VBtn
              size="small" variant="text" icon="tabler-download" class="row-action" :href="certUrl(c)"
              :title="t('sars.download_irp5')" :aria-label="t('sars.download_irp5')"
            /></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Recent EMP501s -->
    <VCard v-if="recent.length" border rounded="lg" class="mt-4">
      <VCardText class="pb-0"><div class="text-subtitle-1 font-weight-medium">{{ t('sars.recent_emp501') }}</div></VCardText>
      <VTable>
        <thead><tr><th>{{ t('sars.reference') }}</th><th>{{ t('sars.period') }}</th><th class="text-right">{{ t('sars.version') }}</th><th>{{ t('sars.status') }}</th><th class="text-center">{{ t('sars.balanced') }}</th><th>{{ t('sars.generated') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in recent" :key="s.id" style="cursor:pointer" @click="router.get(route('finance.accounting.sars.submissions.show', s.id))">
            <td class="font-weight-medium">{{ s.reference }}</td>
            <td>{{ s.period }}</td>
            <td class="text-right">v{{ s.version }}</td>
            <td><VChip size="small" :color="statusColor[s.status]" variant="tonal">{{ t('sars.st_' + s.status) }}</VChip></td>
            <td class="text-center"><VIcon size="18" :color="s.reconciled ? 'success' : 'error'">{{ s.reconciled ? 'tabler-check' : 'tabler-x' }}</VIcon></td>
            <td class="text-caption">{{ s.generated_at ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
