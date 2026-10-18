<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Line { code: string; label: string; amount: number }
interface Err { entity: string; id: number | null; field: string; message: string }
interface Emp201 {
  period: { month: string; from: string; to: string; label: string }
  header: { company_name: string; paye_reference: string | null; uif_reference: string | null; sdl_reference: string | null; sdl_liable: boolean; contact_person: string | null; contact_email: string | null }
  lines: Line[]
  totals: { paye: number; sdl: number; uif_employee: number; uif_employer: number; uif_total: number; eti: number; total_payable: number; employees: number; gross: number; runs: number }
  validation: { validated: number; errors: Err[] }
}
interface Recent { id: number; reference: string; period: string; status: string; version: number; total_payable: number | null; generated_at: string | null }

interface Filed {
  reference: string; version: number; status: string
  filed_total: number; live_total: number; difference: number; differs: boolean
}

const props = defineProps<{ emp201: Emp201; month: string; recent: Recent[]; filed: Filed | null }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const month = ref(props.month)
const errors = computed(() => props.emp201.validation.errors)
const clean = computed(() => errors.value.length === 0)
const statusColor: Record<string, string> = { draft: 'grey', generated: 'info', validated: 'primary', submitted: 'warning', accepted: 'success', rejected: 'error' }

function reload() {
  if (!/^\d{4}-\d{2}$/.test(month.value)) return
  router.get(route('payroll.emp201'), { month: month.value }, { preserveState: true, preserveScroll: true, replace: true })
}

const genForm = useForm<{ month: string; notes: string }>({ month: props.month, notes: '' })
function generate() {
  genForm.month = month.value
  genForm.post(route('payroll.emp201.generate'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width: 1100px; margin: 0 auto">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('sars.finance_sars') }}</div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sars.emp201_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sars.emp201_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('finance.accounting.sars.config')"><VBtn variant="text" prepend-icon="tabler-shield-check">{{ t('sars.config_title') }}</VBtn></Link>
        <Link :href="route('finance.accounting.sars.submissions')"><VBtn variant="tonal" prepend-icon="tabler-file-certificate">{{ t('sars.submissions') }}</VBtn></Link>
      </div>
    </div>

    <!--
      Filed once, then payroll moved. The figures on this page are rebuilt from live payslips every
      time it loads; the return SARS holds does not move with them. Saying so is the whole point —
      a correction landing in a filed month is exactly the thing nobody notices until SARS does.
    -->
    <VAlert
      v-if="filed?.differs"
      type="warning"
      variant="tonal"
      class="mb-4"
      :title="t('sars.drift_title', { reference: filed.reference, version: filed.version })"
    >
      {{ t('sars.drift_body', { filed: money(filed.filed_total), live: money(filed.live_total), difference: money(Math.abs(filed.difference)) }) }}
    </VAlert>

    <!-- Month selector -->
    <VCard border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VTextField v-model="month" type="month" :label="t('sars.tax_month')" density="compact" variant="outlined" hide-details style="max-width: 200px" @update:model-value="reload" />
        <VChip size="small" variant="tonal" color="primary">{{ emp201.period.label }}</VChip>
        <VSpacer />
        <span class="text-caption text-medium-emphasis">{{ emp201.totals.employees }} · {{ emp201.totals.runs }} {{ t('sars.payroll_runs') }}</span>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Statutory lines -->
      <VCol cols="12" md="7">
        <VCard border rounded="lg" class="h-100">
          <VCardText>
            <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('sars.statutory_lines') }}</div>
            <VTable density="comfortable">
              <thead><tr><th style="width:80px">{{ t('sars.code') }}</th><th>{{ t('sars.description') }}</th><th class="text-right">{{ t('sars.amount_r') }}</th></tr></thead>
              <tbody>
                <tr v-for="l in emp201.lines" :key="l.code">
                  <td><VChip size="x-small" variant="tonal" color="primary">{{ l.code }}</VChip></td>
                  <td>{{ l.label }}</td>
                  <td class="text-right" style="font-variant-numeric: tabular-nums">{{ money(l.amount) }}</td>
                </tr>
              </tbody>
            </VTable>

            <VDivider class="my-3" />
            <div class="d-flex justify-space-between align-center px-2">
              <div>
                <div class="text-caption text-medium-emphasis">{{ t('sars.total_payable') }}</div>
                <div class="text-h5 font-weight-bold text-error" style="font-variant-numeric: tabular-nums">R {{ money(emp201.totals.total_payable) }}</div>
              </div>
              <div class="text-right text-caption text-medium-emphasis" style="font-variant-numeric: tabular-nums">
                <div>UIF: {{ money(emp201.totals.uif_employee) }} + {{ money(emp201.totals.uif_employer) }}</div>
                <div v-if="emp201.header.sdl_liable">SDL @ 1%: {{ money(emp201.totals.gross) }}</div>
              </div>
            </div>
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
              <strong>{{ emp201.validation.validated }}</strong> {{ t('sars.n_checks') }} ·
              <span :class="clean ? 'text-success' : 'text-error'">{{ clean ? t('sars.all_valid') : t('sars.n_errors', { n: errors.length }) }}</span>
            </p>
            <VList v-if="errors.length" density="compact" class="py-0">
              <VListItem v-for="(e, i) in errors" :key="i" class="px-0">
                <template #prepend><VIcon size="16" color="error" class="me-2">tabler-point-filled</VIcon></template>
                <VListItemTitle class="text-caption text-wrap">{{ e.message }}</VListItemTitle>
              </VListItem>
            </VList>
            <div v-else class="text-caption text-success">{{ t('sars.emp201_ready') }}</div>
          </VCardText>
        </VCard>

        <VCard border rounded="lg">
          <VCardText>
            <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('sars.generate') }}</div>
            <p class="text-caption text-medium-emphasis mb-3">{{ t('sars.emp201_generate_help') }}</p>
            <VTextarea v-model="genForm.notes" :label="t('sars.notes')" variant="outlined" density="compact" rows="2" hide-details class="mb-3" />
            <VBtn color="primary" block prepend-icon="tabler-file-download" :loading="genForm.processing" :disabled="!emp201.totals.runs" @click="generate">{{ t('sars.generate_emp201') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent EMP201s -->
    <VCard v-if="recent.length" border rounded="lg" class="mt-4">
      <VCardText class="pb-0"><div class="text-subtitle-1 font-weight-medium">{{ t('sars.recent_emp201') }}</div></VCardText>
      <VTable>
        <thead><tr><th>{{ t('sars.reference') }}</th><th>{{ t('sars.period') }}</th><th class="text-right">{{ t('sars.version') }}</th><th>{{ t('sars.status') }}</th><th class="text-right">{{ t('sars.total_payable') }}</th><th>{{ t('sars.generated') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in recent" :key="s.id" style="cursor:pointer" @click="router.get(route('finance.accounting.sars.submissions.show', s.id))">
            <td class="font-weight-medium">{{ s.reference }}</td>
            <td>{{ s.period }}</td>
            <td class="text-right">v{{ s.version }}</td>
            <td><VChip size="small" :color="statusColor[s.status]" variant="tonal">{{ t('sars.st_' + s.status) }}</VChip></td>
            <td class="text-right" style="font-variant-numeric: tabular-nums">{{ s.total_payable != null ? money(s.total_payable) : '—' }}</td>
            <td class="text-caption">{{ s.generated_at ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
