<script setup lang="ts">
import { ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.pay.' + key, a ?? {})
type Run = { uuid: string; name: string; status: string; frequency: string | null; period_start: string; period_end: string; employee_count: number; total_net: number }
const props = defineProps<{ company: { uuid: string; name: string; pay_frequency: string; tax_year: number | null }; runs: Run[] }>()

const dialog = ref(false)
const form = useForm({
  name: '', frequency: props.company.pay_frequency, run_type: 'regular',
  period_start: '', period_end: '', pay_date: '', tax_year: props.company.tax_year,
})

// The period a cycle implies, offered rather than imposed — the three date boxes were blank, and a
// weekly run covering five weeks was accepted without comment. The money is annualised from the
// frequency, not the dates, so the payslip came out right while the period it claimed was wrong.
function periodEndFor(frequency: string, start: string): string {
  const from = new Date(start + 'T00:00:00')
  if (Number.isNaN(from.getTime())) return ''

  if (frequency === 'weekly') from.setDate(from.getDate() + 6)
  else if (frequency === 'fortnightly') from.setDate(from.getDate() + 13)
  else if (from.getDate() === 1) { from.setMonth(from.getMonth() + 1); from.setDate(0) }
  else { from.setMonth(from.getMonth() + 1); from.setDate(from.getDate() - 1) }

  return from.toISOString().slice(0, 10)
}

// Only fills what the officer has not already decided, so a deliberate short period is never
// silently overwritten.
watch(() => [form.frequency, form.period_start], () => {
  if (!form.period_start || !form.frequency) return
  const end = periodEndFor(form.frequency, form.period_start)
  if (end) { form.period_end = end; if (!form.pay_date) form.pay_date = end }
})
function create() { form.post(route('payroll.bureau.runs.store', props.company.uuid), { onSuccess: () => dialog.value = false }) }
const color = (s: string) => ({ draft: 'grey', review: 'warning', finalised: 'success' } as any)[s] ?? 'grey'
const rand = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4" style="max-width:1000px;margin:auto">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the client" class="row-action" variant="text" @click="router.get(route('payroll.bureau.companies.show', company.uuid))" />
      <div class="flex-grow-1"><h4 class="text-h4 mb-0">Pay runs — {{ company.name }}</h4></div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ k('new_run') }}</VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ u('run') }}</th><th>{{ u('period') }}</th><th>{{ t('bureau.freq.pay_frequency') }}</th><th>{{ u('status') }}</th><th class="text-end">{{ u('employees') }}</th><th class="text-end">{{ u('net') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in runs" :key="r.uuid" style="cursor:pointer" @click="router.get(route('payroll.bureau.runs.show', [company.uuid, r.uuid]))">
            <td>{{ r.name }}</td><td>{{ r.period_start }} → {{ r.period_end }}</td>
            <td class="text-no-wrap">{{ r.frequency }}</td>
            <td><VChip size="x-small" :color="color(r.status)" variant="tonal">{{ r.status }}</VChip></td>
            <td class="text-end">{{ r.employee_count }}</td><td class="text-end">{{ rand(r.total_net) }}</td>
          </tr>
          <tr v-if="!runs.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ k('no_runs') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="680">
      <VCard>
        <VCardTitle>{{ k('new_run') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model="form.name" :label="k('run_name')" />
          <VSelect v-model="form.run_type" :items="['regular','offcycle','bonus','termination']" :label="k('run_type')" />
          <!-- Which cycle this run pays. It used to be copied from the company and could not be
               chosen, so a client could only ever run one — and anybody on another cycle was
               filtered out of every run that existed and never paid. -->
          <VSelect
            v-model="form.frequency"
            :items="[
              { title: 'Weekly', value: 'weekly' },
              { title: 'Fortnightly', value: 'fortnightly' },
              { title: 'Monthly', value: 'monthly' },
            ]"
            :label="t('bureau.freq.pay_frequency')"
            :error-messages="form.errors.frequency"
          />
          <div class="field-row">
            <VTextField v-model="form.period_start" type="date" :label="k('period_start')" :error-messages="form.errors.period_start" />
            <VTextField v-model="form.period_end" type="date" :label="k('period_end')" :error-messages="form.errors.period_end" />
          </div>
          <div class="field-row">
            <VTextField v-model="form.pay_date" type="date" :label="k('pay_date')" />
            <VTextField v-model.number="form.tax_year" type="number" :label="u('tax_year')" />
          </div>
        </VCardText>
        <VCardActions class="pa-3"><VSpacer /><VBtn variant="text" @click="dialog=false">{{ u('cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="create">{{ k('create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/*
 * Two fields side by side, each holding half the row whatever is written beneath it.
 *
 * They were plain flex children, which size to their content — so the overlap warning ("a monthly
 * run already covers those days…") stretched `period_start` and collapsed `period_end` to the width
 * of the word "Peri…". `flex: 1 1 0` makes the split even, and `min-width: 0` stops a long message
 * from forcing the column open again. They wrap to one per line on a narrow screen.
 */
.field-row {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: flex-start;
}

.field-row > * {
  flex: 1 1 12rem;
  min-width: 0;
}
</style>
