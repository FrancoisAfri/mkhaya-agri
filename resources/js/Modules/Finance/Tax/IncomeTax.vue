<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

type Comp = { uuid: string; label: string; kind: string; number: number | null; version: number; status: string; entity: string | null
  period: string | null; year_end: string; basis: string | null; taxable_income: string; tax: string; amount_payable: string }

const props = defineProps<{
  computations: Comp[]
  periods: Array<{ uuid: string; label: string; tax_type: string }>
  carryForwards: Array<{ id: number; entity: string | null; kind: string; year_end: string; opening: string; movement: string; closing: string; source: string; note: string | null }>
  can: { prepare: boolean }
  businesses: Array<{ id: number; name: string }>
  business: number | null
}>()

const byYear = computed(() => {
  const g: Record<string, Comp[]> = {}
  for (const c of props.computations) (g[c.year_end] ??= []).push(c)
  return g
})

const dialog = ref(false)
const form = useForm({ period: null as string | null, estimate: '', basis: 'estimate', basic_amount: '' })
const chosen = computed(() => props.periods.find(p => p.uuid === form.period))
function prepare() {
  if (!form.period) return
  form.transform(d => chosen.value?.tax_type === 'PROV'
    ? { estimate: d.estimate, basis: d.basis, basic_amount: d.basic_amount || null }
    : {}).post(route('tax.income.prepare', form.period), { onSuccess: () => { dialog.value = false } })
}

const openingDialog = ref(false)
const opening = useForm({ entity_id: props.business ?? props.businesses[0]?.id ?? null, kind: 'assessed_loss', year_end: '', closing: '', basis: '' })
function saveOpening() {
  opening.post(route('tax.income.opening_balance'), { preserveScroll: true, onSuccess: () => { openingDialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">Income tax</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          The annual computation from accounting profit to taxable income, and provisional (IRP6) estimates. Every figure is a line with its source or reason.
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VBtn variant="tonal" prepend-icon="tabler-tags" @click="router.get(route('tax.income.classifications', business ? { business } : {}))">Classify accounts</VBtn>
        <VBtn v-if="can.prepare" color="primary" prepend-icon="tabler-calculator" @click="dialog = true">Prepare</VBtn>
      </div>
    </div>

    <div v-if="!computations.length" class="text-center text-medium-emphasis py-8">
      No computations yet. Register the business for corporate income tax (CIT) and provisional tax (PROV), confirm them, then prepare here.
    </div>

    <div v-for="(items, year) in byYear" :key="year" class="mb-4">
      <div class="text-overline mb-1">Year ending {{ year }}</div>
      <VCard border>
        <VTable density="comfortable">
          <thead><tr><th>Computation</th><th>Basis</th><th class="text-right">Taxable income</th><th class="text-right">Tax</th><th class="text-right">Payable / (refund)</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="c in items" :key="c.uuid" class="cursor-pointer" @click="router.get(route('tax.income.show', c.uuid))">
              <td class="font-weight-medium">{{ c.label }}<div class="text-caption text-disabled">v{{ c.version }} · {{ c.entity }}</div></td>
              <td class="text-caption">{{ label(c.basis) }}</td>
              <td class="text-right">{{ money(c.taxable_income) }}</td>
              <td class="text-right">{{ money(c.tax) }}</td>
              <td class="text-right">{{ money(c.amount_payable) }}</td>
              <td><VChip size="small" :color="statusColor[c.status]" label>{{ label(c.status) }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </div>

    <VCard border class="mt-6">
      <VCardItem>
        <VCardTitle class="text-body-1">Balances carried forward</VCardTitle>
        <VCardSubtitle>Assessed losses and allowance balances between years. Balances from before the system are marked as opening balances.</VCardSubtitle>
        <template #append><VBtn v-if="can.prepare" size="small" variant="tonal" @click="openingDialog = true">Record opening balance</VBtn></template>
      </VCardItem>
      <VTable density="compact">
        <thead><tr><th>Year end</th><th>Kind</th><th class="text-right">Opening</th><th class="text-right">Movement</th><th class="text-right">Closing</th><th>Source</th></tr></thead>
        <tbody>
          <tr v-for="c in carryForwards" :key="c.id">
            <td>{{ c.year_end }}<div class="text-caption text-disabled">{{ c.entity }}</div></td><td>{{ label(c.kind) }}</td>
            <td class="text-right">{{ money(c.opening) }}</td><td class="text-right">{{ money(c.movement) }}</td><td class="text-right font-weight-medium">{{ money(c.closing) }}</td>
            <td><VChip size="x-small" :color="c.source === 'opening_balance' ? 'warning' : undefined" label>{{ label(c.source) }}</VChip><div v-if="c.note" class="text-caption text-medium-emphasis">{{ c.note }}</div></td>
          </tr>
          <tr v-if="!carryForwards.length"><td colspan="6" class="text-center text-medium-emphasis py-4">None.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard title="Prepare a computation">
        <VCardText>
          <VSelect v-model="form.period" :items="periods" item-title="label" item-value="uuid" label="Period" no-data-text="No open CIT or PROV period." />
          <template v-if="chosen?.tax_type === 'PROV'">
            <VTextField v-model="form.estimate" label="Estimated taxable income for the whole year" :error-messages="form.errors.estimate" />
            <VSelect v-model="form.basis" :items="[{ title: 'Estimate', value: 'estimate' }, { title: 'Year-to-date trial balance', value: 'trial_balance' }, { title: 'Prior year (basic amount)', value: 'prior_year' }]" label="Basis" />
            <VTextField v-model="form.basic_amount" label="Basic amount (last assessed taxable income), for comparison" />
          </template>
          <p v-else-if="chosen" class="text-body-2 text-medium-emphasis">The computation reads the year's income and expenses from the ledger, applies the account classifications and keeps any manual adjustments from the last version.</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.period" @click="prepare">Prepare</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="openingDialog" max-width="560">
      <VCard title="Record an opening balance">
        <VCardText>
          <VSelect v-if="businesses.length > 1" v-model="opening.entity_id" :items="businesses" item-title="name" item-value="id" label="Business" />
          <VSelect v-model="opening.kind" :items="[{ title: 'Assessed loss', value: 'assessed_loss' }, { title: 'Allowance balance', value: 'allowance_balance' }]" label="Kind" />
          <VTextField v-model="opening.year_end" type="date" label="At the year end" :error-messages="opening.errors.year_end" />
          <VTextField v-model="opening.closing" label="Balance" :error-messages="opening.errors.closing" />
          <VTextarea v-model="opening.basis" label="Where it comes from (e.g. ITA34 dated …)" rows="2" auto-grow :error-messages="opening.errors.basis" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="openingDialog = false">Cancel</VBtn><VBtn color="primary" :loading="opening.processing" @click="saveOpening">Record</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
