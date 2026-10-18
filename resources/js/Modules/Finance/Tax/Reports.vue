<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import BusinessFilter from './components/BusinessFilter.vue'

const route = ziggyRoute

const props = defineProps<{
  reports: Array<{ key: string; title: string; description: string; restricted: boolean }>
  current: string
  headers: string[]
  rows: Array<Array<string | number | null>>
  preview: number
  filters: { business: number | null; from: string | null; to: string | null; tax_type: string | null }
  businesses: Array<{ id: number; name: string }>
  business: number | null
}>()

const f = reactive({ from: props.filters.from ?? '', to: props.filters.to ?? '', tax_type: props.filters.tax_type ?? null })
function params(report = props.current) {
  return Object.fromEntries(Object.entries({ report, ...f, business: props.business }).filter(([, v]) => v !== null && v !== ''))
}
function open(report: string) {
  router.get(route('tax.reports.index'), params(report), { preserveState: true, preserveScroll: true })
}
function exportCsv() {
  window.location.href = route('tax.reports.export', params())
}
const isNumber = (v: unknown) => typeof v === 'string' && /^-?\d+\.\d{2}$/.test(v)
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">Tax reports</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Built from the same records as the screens. Exports carry the business, period, generation time and report version, and are protected against spreadsheet formulas.
    </p>

    <VRow>
      <VCol cols="12" md="3">
        <VCard border>
          <VList density="compact" nav>
            <VListItem v-for="r in reports" :key="r.key" :active="r.key === current" :disabled="r.restricted" @click="open(r.key)">
              <VListItemTitle class="text-body-2">{{ r.title }}</VListItemTitle>
              <VListItemSubtitle v-if="r.restricted" class="text-caption">Needs the audit permission</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="9">
        <VCard border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ reports.find(r => r.key === current)?.title }}</VCardTitle>
            <VCardSubtitle>{{ reports.find(r => r.key === current)?.description }}</VCardSubtitle>
            <template #append><VBtn color="primary" variant="tonal" prepend-icon="tabler-download" @click="exportCsv">Export CSV</VBtn></template>
          </VCardItem>
          <VCardText class="d-flex flex-wrap gap-3 pb-0">
            <BusinessFilter :businesses="businesses" :business="business" />
            <VTextField v-model="f.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 170px" @change="open(current)" />
            <VTextField v-model="f.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 170px" @change="open(current)" />
            <VSelect v-model="f.tax_type" :items="['VAT', 'PAYE', 'UIF', 'SDL', 'CIT', 'PROV']" label="Tax" clearable density="compact" hide-details style="max-inline-size: 130px" @update:model-value="open(current)" />
          </VCardText>
          <div style="overflow-x: auto">
            <VTable density="compact" class="mt-2">
              <thead><tr><th v-for="h in headers" :key="h" class="text-no-wrap">{{ h }}</th></tr></thead>
              <tbody>
                <tr v-for="(row, i) in rows" :key="i">
                  <td v-for="(v, j) in row" :key="j" :class="isNumber(v) ? 'text-right text-no-wrap' : ''">{{ v ?? '' }}</td>
                </tr>
                <tr v-if="!rows.length"><td :colspan="headers.length || 1" class="text-center text-medium-emphasis py-6">Nothing for these filters.</td></tr>
              </tbody>
            </VTable>
          </div>
          <VCardText v-if="rows.length >= preview" class="text-caption text-medium-emphasis">Showing the first {{ preview }} rows. The export has them all.</VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
