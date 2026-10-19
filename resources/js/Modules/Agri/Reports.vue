<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import { num } from './Components/agri'

const props = defineProps<{
  reports: Array<{ key: string; title: string; description: string }>
  data: null | { key: string; title: string; columns: Array<{ key: string; title: string }>; rows: any[] }
  filters: { report: string | null; farm: string | null; from: string; to: string }
  farms: Array<{ id: string; name: string }>
}>()
const f = ref({ ...props.filters })
const run = (report?: string) => router.get(route('agri.reports.index'), { ...f.value, report: report ?? f.value.report, farm: f.value.farm || undefined }, { preserveState: false })
const exportUrl = () => route('agri.reports.export', { report: props.data!.key, farm: f.value.farm || undefined, from: f.value.from, to: f.value.to })
const show = (v: any) => (typeof v === 'number' ? num(v, 3) : v ?? '—')
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-6">
      <h4 class="text-h4 mb-1">Farm Reports</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">Standard reports across the farms you can reach, on screen or as CSV.</p>
    </div>
    <VRow class="mb-2">
      <VCol v-for="r in reports" :key="r.key" cols="12" sm="6" lg="4">
        <VCard :color="filters.report === r.key ? 'primary' : undefined" :variant="filters.report === r.key ? 'tonal' : 'elevated'" class="h-100" @click="run(r.key)">
          <VCardItem><VCardTitle class="text-wrap">{{ r.title }}</VCardTitle></VCardItem>
          <VCardText>{{ r.description }}</VCardText>
        </VCard>
      </VCol>
    </VRow>
    <VCard v-if="data">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <h5 class="text-h5 me-4">{{ data.title }}</h5>
        <VSelect v-model="f.farm" :items="[{ title: 'All my farms', value: null }, ...farms.map(x => ({ title: x.name, value: x.id }))]" label="Farm" density="compact" hide-details style="max-width: 240px" />
        <VTextField v-model="f.from" type="date" label="From" density="compact" hide-details style="max-width: 160px" />
        <VTextField v-model="f.to" type="date" label="To" density="compact" hide-details style="max-width: 160px" />
        <VBtn variant="tonal" @click="run()">Run</VBtn>
        <VSpacer />
        <VBtn :href="exportUrl()" prepend-icon="tabler-download" variant="outlined">CSV</VBtn>
      </VCardText>
      <VTable density="compact" class="text-no-wrap">
        <thead><tr><th v-for="c in data.columns" :key="c.key">{{ c.title }}</th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in data.rows" :key="i"><td v-for="c in data.columns" :key="c.key">{{ show(r[c.key]) }}</td></tr>
          <tr v-if="!data.rows.length"><td :colspan="data.columns.length" class="text-center text-medium-emphasis py-6">Nothing in this period.</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
