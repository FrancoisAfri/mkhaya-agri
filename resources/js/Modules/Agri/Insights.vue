<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FarmFilter from './Components/FarmFilter.vue'
import { human, money, num } from './Components/agri'

/** Phase 2 §13: a dashboard per role; every figure labelled with what it is and linked to its records. */
const props = defineProps<{
  roles: Array<{ key: string; title: string }>
  dashboard: { role: string; title: string; period: [string, string]; sections: any[]; alerts: any[]; legend: Record<string, string> }
  filters: { farm: string | null; from: string; to: string }
  farms: Array<{ id: string; name: string }>
}>()
const NATURE: Record<string, { label: string; color: string }> = {
  real_time: { label: 'Now', color: 'info' }, actual: { label: 'Actual', color: 'success' }, estimate: { label: 'Estimate', color: 'warning' }, forecast: { label: 'Forecast', color: 'secondary' },
}
const period = ref({ from: props.filters.from, to: props.filters.to })
const go = (role: string) => router.get(route('agri.insights', role), { farm: props.filters.farm || undefined, ...period.value }, { preserveState: false })
const exportUrl = (section: string, format: string) => route('agri.insights.export', { role: props.dashboard.role, section, farm: props.filters.farm || undefined, ...period.value, format })
const show = (m: any) => m.value === null || m.value === undefined ? '—' : m.unit === 'ZAR' ? money(m.value) : `${num(m.value)}${m.unit && m.unit !== 'ZAR' ? ' ' + m.unit : ''}`
const cell = (v: any) => (v === null || v === undefined ? '—' : typeof v === 'number' ? num(v) : v)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ dashboard.title }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ dashboard.period[0] }} to {{ dashboard.period[1] }} · figures from approved and posted records unless marked otherwise.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VTextField v-model="period.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 160px" />
        <VTextField v-model="period.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 160px" />
        <VBtn variant="tonal" @click="go(dashboard.role)">Apply</VBtn>
        <FarmFilter :farms="farms" :model-value="filters.farm" />
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <VChip v-for="r in roles" :key="r.key" :color="dashboard.role === r.key ? 'primary' : undefined" :variant="dashboard.role === r.key ? 'flat' : 'tonal'" @click="go(r.key)">{{ r.title }}</VChip>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5 text-caption">
      <span v-for="(text, k) in dashboard.legend" :key="k"><VChip size="x-small" :color="NATURE[k]?.color">{{ NATURE[k]?.label }}</VChip> {{ text }}</span>
    </div>

    <VCard v-if="dashboard.alerts.length" class="mb-5">
      <VCardItem><VCardTitle>Needs attention</VCardTitle>
        <template #append><VBtn size="small" variant="text" :href="exportUrl('alerts', 'csv')">CSV</VBtn><VBtn size="small" variant="text" :href="exportUrl('alerts', 'xlsx')">Excel</VBtn></template></VCardItem>
      <VList density="compact" lines="two">
        <VListItem v-for="(a, i) in dashboard.alerts" :key="i" :title="a.title" :subtitle="a.detail" :href="a.link ?? undefined" prepend-icon="tabler-alert-triangle">
          <template #append><VChip size="x-small" :color="NATURE[a.nature]?.color">{{ NATURE[a.nature]?.label }}</VChip></template>
        </VListItem>
      </VList>
    </VCard>

    <VCard v-for="s in dashboard.sections" :key="s.key" class="mb-5">
      <VCardItem><VCardTitle>{{ s.title }}</VCardTitle>
        <template #append><VBtn size="small" variant="text" :href="exportUrl(s.key, 'csv')">CSV</VBtn><VBtn size="small" variant="text" :href="exportUrl(s.key, 'xlsx')">Excel</VBtn></template></VCardItem>
      <VCardText>
        <VRow>
          <VCol v-for="m in s.metrics" :key="m.label" cols="6" md="3">
            <component :is="m.link ? 'a' : 'div'" :href="m.link ?? undefined" class="d-block text-decoration-none">
              <div class="text-caption text-medium-emphasis">{{ m.label }}</div>
              <div class="text-h5">{{ show(m) }}</div>
              <VChip size="x-small" :color="NATURE[m.nature]?.color">{{ NATURE[m.nature]?.label }}</VChip>
              <div class="text-caption text-medium-emphasis">{{ m.source }}</div>
            </component>
          </VCol>
        </VRow>
        <VTable v-if="s.rows?.length" density="compact" class="mt-3">
          <thead><tr><th v-for="c in Object.keys(s.rows[0]).filter(k => k !== 'link')" :key="c">{{ human(c) }}</th></tr></thead>
          <tbody><tr v-for="(r, j) in s.rows" :key="j"><td v-for="(c, k) in Object.keys(s.rows[0]).filter(k => k !== 'link')" :key="c">
            <a v-if="k === 0 && r.link" :href="r.link">{{ cell(r[c]) }}</a><template v-else>{{ typeof r[c] === 'object' && r[c] !== null ? JSON.stringify(r[c]) : cell(r[c]) }}</template></td></tr></tbody>
        </VTable>
      </VCardText>
    </VCard>
  </div>
</template>
