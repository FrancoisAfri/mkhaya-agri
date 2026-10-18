<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Emp { employee_id: number; name: string; id_number: string | null; date_joined: string | null; date_left: string | null; remuneration: number; uif_employee: number; uif_employer: number; uif_total: number; status: string; reason_code: string }
interface Err { entity: string; id: number | null; field: string; message: string }
interface Ui19 {
  period: { month: string; from: string; to: string; label: string }
  header: { company_name: string; uif_reference: string | null; paye_reference: string | null }
  employees: Emp[]
  totals: { employee_count: number; new_count: number; terminated_count: number; total_remuneration: number; uif_employee: number; uif_employer: number; uif_total: number }
  validation: { validated: number; errors: Err[] }
}
interface Recent { id: number; reference: string; period: string; status: string; version: number; employee_count: number | null; generated_at: string | null }

const props = defineProps<{ ui19: Ui19; month: string; recent: Recent[] }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const month = ref(props.month)
const errors = computed(() => props.ui19.validation.errors)
const clean = computed(() => errors.value.length === 0)
const statusColor: Record<string, string> = { draft: 'grey', generated: 'info', validated: 'primary', submitted: 'warning', accepted: 'success', rejected: 'error' }
const rowStatus: Record<string, { color: string; label: string }> = {
  new: { color: 'info', label: 'engaged' }, terminated: { color: 'error', label: 'terminated' }, active: { color: 'grey', label: 'active' },
}

function reload() {
  if (!/^\d{4}-\d{2}$/.test(month.value)) return
  router.get(route('payroll.ui19'), { month: month.value }, { preserveState: true, preserveScroll: true, replace: true })
}

const genForm = useForm<{ month: string; notes: string }>({ month: props.month, notes: '' })
function generate() {
  genForm.month = month.value
  genForm.post(route('payroll.ui19.generate'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width: 1150px; margin: 0 auto">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('sars.finance_sars') }}</div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sars.ui19_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sars.ui19_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('payroll.emp201')"><VBtn variant="text" prepend-icon="tabler-file-percent">EMP201</VBtn></Link>
        <Link :href="route('finance.accounting.sars.submissions')"><VBtn variant="tonal" prepend-icon="tabler-file-certificate">{{ t('sars.submissions') }}</VBtn></Link>
      </div>
    </div>

    <!-- Month selector + summary -->
    <VCard border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VTextField v-model="month" type="month" :label="t('sars.tax_month')" density="compact" variant="outlined" hide-details style="max-width: 200px" @update:model-value="reload" />
        <VChip size="small" variant="tonal" color="primary">{{ ui19.period.label }}</VChip>
        <VSpacer />
        <div class="d-flex gap-2">
          <VChip size="small" variant="tonal">{{ ui19.totals.employee_count }} {{ t('sars.employees_label') }}</VChip>
          <VChip size="small" variant="tonal" color="info">{{ ui19.totals.new_count }} {{ t('sars.engaged') }}</VChip>
          <VChip size="small" variant="tonal" color="error">{{ ui19.totals.terminated_count }} {{ t('sars.terminated') }}</VChip>
        </div>
      </VCardText>
    </VCard>

    <VRow>
      <!-- UIF summary -->
      <VCol cols="12" md="4">
        <VCard border rounded="lg" class="mb-4">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('sars.uif_total') }}</div>
            <div class="text-h5 font-weight-bold text-error mb-2" style="font-variant-numeric: tabular-nums">R {{ money(ui19.totals.uif_total) }}</div>
            <div class="text-caption text-medium-emphasis" style="font-variant-numeric: tabular-nums">
              {{ t('sars.uif_employee') }}: {{ money(ui19.totals.uif_employee) }}<br>
              {{ t('sars.uif_employer') }}: {{ money(ui19.totals.uif_employer) }}<br>
              {{ t('sars.uif_remuneration') }}: {{ money(ui19.totals.total_remuneration) }}
            </div>
          </VCardText>
        </VCard>

        <VCard border rounded="lg" class="mb-4">
          <VCardText>
            <div class="d-flex align-center gap-2 mb-2">
              <VIcon :color="clean ? 'success' : 'warning'">{{ clean ? 'tabler-circle-check' : 'tabler-alert-triangle' }}</VIcon>
              <span class="text-subtitle-1 font-weight-medium">{{ t('sars.validation') }}</span>
            </div>
            <p class="text-body-2 mb-2">
              <strong>{{ ui19.validation.validated }}</strong> {{ t('sars.n_checks') }} ·
              <span :class="clean ? 'text-success' : 'text-error'">{{ clean ? t('sars.all_valid') : t('sars.n_errors', { n: errors.length }) }}</span>
            </p>
            <VList v-if="errors.length" density="compact" class="py-0" style="max-height: 160px; overflow-y: auto">
              <VListItem v-for="(e, i) in errors" :key="i" class="px-0">
                <template #prepend><VIcon size="16" color="error" class="me-2">tabler-point-filled</VIcon></template>
                <VListItemTitle class="text-caption text-wrap">{{ e.message }}</VListItemTitle>
              </VListItem>
            </VList>
            <div v-else class="text-caption text-success">{{ t('sars.ui19_ready') }}</div>
          </VCardText>
        </VCard>

        <VCard border rounded="lg">
          <VCardText>
            <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('sars.generate') }}</div>
            <p class="text-caption text-medium-emphasis mb-3">{{ t('sars.ui19_generate_help') }}</p>
            <VTextarea v-model="genForm.notes" :label="t('sars.notes')" variant="outlined" density="compact" rows="2" hide-details class="mb-3" />
            <VBtn color="primary" block prepend-icon="tabler-file-download" :loading="genForm.processing" :disabled="!ui19.totals.employee_count" @click="generate">{{ t('sars.generate_ui19') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Employee declaration -->
      <VCol cols="12" md="8">
        <VCard border rounded="lg">
          <VCardText class="pb-0"><div class="text-subtitle-1 font-weight-medium">{{ t('sars.employees_label') }}</div></VCardText>
          <VTable density="comfortable" style="font-variant-numeric: tabular-nums">
            <thead>
              <tr>
                <th>{{ t('sars.employee') }}</th><th>{{ t('sars.id_number') }}</th><th>{{ t('sars.type') }}</th>
                <th class="text-right">{{ t('sars.uif_remuneration') }}</th><th class="text-right">{{ t('sars.uif_total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!ui19.employees.length"><td colspan="5" class="text-center text-medium-emphasis py-6">{{ t('sars.ui19_no_payroll') }}</td></tr>
              <tr v-for="e in ui19.employees" :key="e.employee_id">
                <td class="font-weight-medium">{{ e.name }}</td>
                <td><span v-if="e.id_number">{{ e.id_number }}</span><VChip v-else size="x-small" color="warning" variant="tonal">{{ t('sars.id_missing') }}</VChip></td>
                <td><VChip size="x-small" :color="rowStatus[e.status].color" variant="tonal">{{ t('sars.' + rowStatus[e.status].label) }}</VChip></td>
                <td class="text-right">{{ money(e.remuneration) }}</td>
                <td class="text-right">{{ money(e.uif_total) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent UI-19s -->
    <VCard v-if="recent.length" border rounded="lg" class="mt-4">
      <VCardText class="pb-0"><div class="text-subtitle-1 font-weight-medium">{{ t('sars.recent_ui19') }}</div></VCardText>
      <VTable>
        <thead><tr><th>{{ t('sars.reference') }}</th><th>{{ t('sars.period') }}</th><th class="text-right">{{ t('sars.version') }}</th><th>{{ t('sars.status') }}</th><th class="text-right">{{ t('sars.employees_label') }}</th><th>{{ t('sars.generated') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in recent" :key="s.id" style="cursor:pointer" @click="router.get(route('finance.accounting.sars.submissions.show', s.id))">
            <td class="font-weight-medium">{{ s.reference }}</td>
            <td>{{ s.period }}</td>
            <td class="text-right">v{{ s.version }}</td>
            <td><VChip size="small" :color="statusColor[s.status]" variant="tonal">{{ t('sars.st_' + s.status) }}</VChip></td>
            <td class="text-right">{{ s.employee_count ?? '—' }}</td>
            <td class="text-caption">{{ s.generated_at ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
