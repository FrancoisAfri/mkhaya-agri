<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

defineProps<{
  reconciliations: Array<{
    uuid: string; type: string; period: string | null; entity: string | null; version: number; status: string
    source_total: string; target_total: string; difference: string; tolerance: string; matched: number; unmatched: number; exceptions: number
    prepared_by: string | null; signed_off_by: string | null; signed_off_at: string | null; created_at: string | null
  }>
  periods: Array<{ uuid: string; label: string; tax_type: string; processable: boolean }>
  types: Record<string, string>
  can: { run: boolean }
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
}>()

const dialog = ref(false)
const form = useForm({ type: 'vat_register_to_gl', period: null as string | null, tolerance: '' })
// Which periods each reconciliation runs on: VAT ones on open VAT periods, payroll on open PAYE periods,
// liabilities against payments on any period (payments arrive after filing).
const fits = (type: string, p: { tax_type: string; processable: boolean }) =>
  type === 'liabilities_to_payments' ? true
    : type === 'payroll_to_gl' ? p.tax_type === 'PAYE' && p.processable
      : p.tax_type === 'VAT' && p.processable
function run() {
  form.transform(d => ({ type: d.type, period: d.period, tolerance: d.tolerance === '' ? null : d.tolerance }))
    .post(route('tax.reconciliations.run'), { onSuccess: () => { dialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Reconciliations</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Each run is kept. A signed-off reconciliation is never overwritten — running again creates the next version.</p>
      </div>
      <VBtn v-if="can.run" color="primary" prepend-icon="tabler-scale" @click="dialog = true">Run reconciliation</VBtn>
    </div>

    <VCard border>
      <VCardText v-if="businesses.length > 1" class="pb-0"><BusinessFilter :businesses="businesses" :business="business" /></VCardText>
      <VTable density="comfortable">
        <thead>
          <tr><th>Period</th><th>Reconciliation</th><th class="text-right">Register</th><th class="text-right">Ledger</th><th class="text-right">Difference</th><th>Items</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in reconciliations" :key="r.uuid" class="cursor-pointer" @click="router.get(route('tax.reconciliations.show', r.uuid))">
            <td class="font-weight-medium">{{ r.period }}<div class="text-caption text-disabled">{{ r.entity }}</div></td>
            <td>{{ r.type }} <span class="text-caption text-disabled">v{{ r.version }}</span><div class="text-caption text-disabled">{{ r.prepared_by }} · {{ r.created_at }}</div></td>
            <td class="text-right">{{ money(r.source_total) }}</td>
            <td class="text-right">{{ money(r.target_total) }}</td>
            <td class="text-right" :class="Math.abs(Number(r.difference)) > Number(r.tolerance) ? 'text-error' : ''">{{ money(r.difference) }}</td>
            <td class="text-caption">{{ r.matched }} matched · {{ r.unmatched }} unmatched · <span :class="r.exceptions ? 'text-error' : ''">{{ r.exceptions }} exception(s)</span></td>
            <td>
              <VChip size="small" :color="statusColor[r.status]" label>{{ label(r.status) }}</VChip>
              <div v-if="r.signed_off_by" class="text-caption text-disabled">{{ r.signed_off_by }}</div>
            </td>
          </tr>
          <tr v-if="!reconciliations.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No reconciliations yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard title="Run reconciliation">
        <VCardText>
          <VSelect v-model="form.type" :items="Object.entries(types).map(([value, title]) => ({ title, value }))" label="Reconciliation" @update:model-value="form.period = null" />
          <VSelect v-model="form.period" :items="periods.filter(p => fits(form.type, p))" item-title="label" item-value="uuid" label="Period" :error-messages="form.errors.period"
                   no-data-text="No period this reconciliation can run on." />
          <VTextField v-model="form.tolerance" label="Tolerance (R)" placeholder="1.00" hint="Differences above this must be explained before sign-off" persistent-hint />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.period" @click="run">Run</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
