<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, statusColor } from './useTax'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

type Period = {
  uuid: string; label: string; tax_type: string; entity: string | null; start_date: string; end_date: string
  filing_due_date: string | null; payment_due_date: string | null; status: string; reopen_count: number; overdue: boolean
}

const props = defineProps<{
  periods: { data: Period[]; links: Array<{ url: string | null; label: string; active: boolean }>; total: number }
  filters: { tax_type?: string; status?: string; year?: string }
  statuses: string[]
  businesses: Array<{ id: number; name: string }>
  business: number | null
  accountingEnabled: boolean
}>()

const f = reactive({ tax_type: props.filters.tax_type ?? null, status: props.filters.status ?? null, year: props.filters.year ?? null })
function apply() {
  router.get(route('tax.periods.index'), { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), ...(props.business ? { business: props.business } : {}) }, { preserveState: true, preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Tax periods</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Generated from each registration's filing frequency. Separate from accounting periods: closing a month in the ledger does not close a tax period.
    </p>

    <VCard border>
      <VCardText class="d-flex flex-wrap gap-3 pb-0">
        <BusinessFilter :businesses="businesses" :business="business" />
        <VSelect v-model="f.tax_type" :items="['VAT', 'PAYE', 'UIF', 'SDL', 'CIT', 'PROV', 'WHT']" label="Tax" clearable density="compact" style="max-inline-size: 160px" @update:model-value="apply" />
        <VSelect v-model="f.status" :items="statuses.map(s => ({ title: label(s), value: s }))" label="Status" clearable density="compact" style="max-inline-size: 200px" @update:model-value="apply" />
        <VTextField v-model="f.year" label="Year ending" type="number" clearable density="compact" style="max-inline-size: 140px" @change="apply" @click:clear="f.year = null; apply()" />
      </VCardText>
      <VTable density="comfortable">
        <thead>
          <tr><th>Period</th><th>Tax</th><th>Business</th><th>Return due</th><th>Payment due</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="p in periods.data" :key="p.uuid" class="cursor-pointer" @click="router.get(route('tax.periods.show', p.uuid))">
            <td class="font-weight-medium">{{ p.label }}<div class="text-caption text-disabled">{{ p.start_date }} → {{ p.end_date }}</div></td>
            <td>{{ p.tax_type }}</td>
            <td>{{ p.entity ?? '—' }}</td>
            <td :class="p.overdue ? 'text-error' : ''">{{ p.filing_due_date ?? '—' }}<span v-if="p.overdue" class="text-caption"> · overdue</span></td>
            <td>{{ p.payment_due_date ?? '—' }}</td>
            <td>
              <VChip size="small" :color="statusColor[p.status]" label>{{ label(p.status) }}</VChip>
              <span v-if="p.reopen_count" class="text-caption text-warning ms-1">reopened ×{{ p.reopen_count }}</span>
            </td>
          </tr>
          <tr v-if="!periods.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-6">No periods match. Periods are created when a registration is confirmed.</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="periods.links.length > 3" class="d-flex justify-center gap-1">
        <VBtn v-for="(l, i) in periods.links" :key="i" size="small" :variant="l.active ? 'tonal' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
