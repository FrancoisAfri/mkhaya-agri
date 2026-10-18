<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Report = { key: string; title: string; filters: string[]; sensitive: boolean }
type Run = { id: string; title: string; format: string; status: string; rows: number | null; parameters: Record<string, string>; sensitive: boolean; requested_at: string; generated_at: string | null; failure: string | null; downloads: number }
const props = defineProps<{ catalogue: Report[]; runs: Run[]; warehouses: Array<{ value: number; title: string }>; suppliers: Array<{ value: number; title: string }>; sync_rows: number }>()

const initial = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '').get('report')
const f = reactive({ report: props.catalogue.find(r => r.key === initial)?.key ?? props.catalogue[0]?.key ?? '', format: 'xlsx', from: '', to: '', warehouse_id: null as number | null,
  item: '', supplier_id: null as number | null, status: '', background: false })
const chosen = computed(() => props.catalogue.find(r => r.key === f.report))
const uses = (k: string) => chosen.value?.filters.includes(k) ?? false
const token = ref('')
onMounted(() => { token.value = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' })
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString() : '—')
const color = (s: string) => ({ COMPLETE: 'success', FAILED: 'error', RUNNING: 'info', QUEUED: undefined } as Record<string, string | undefined>)[s]
// A file download cannot ride an Inertia visit: the form posts natively, and the list refreshes after a background request.
const afterSubmit = () => { if (f.background) setTimeout(() => router.reload({ only: ['runs'] }), 800) }
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.reports.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.reports.subtitle', { n: sync_rows }) }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardText>
        <form method="post" :action="route('warehouse.reports.run')" @submit="afterSubmit">
          <input type="hidden" name="_token" :value="token">
          <VRow dense>
            <VCol cols="12" md="5"><VSelect v-model="f.report" :items="catalogue.map(r => ({ value: r.key, title: r.title }))" :label="t('warehouse_management.reports.report')" /></VCol>
            <VCol cols="6" md="3"><VSelect v-model="f.format" :items="[{ value: 'xlsx', title: 'Excel' }, { value: 'csv', title: 'CSV' }, { value: 'pdf', title: 'PDF' }]" :label="t('warehouse_management.reports.format')" /></VCol>
            <VCol cols="6" md="4" class="d-flex align-center"><VCheckbox v-model="f.background" :label="t('warehouse_management.reports.background')" hide-details density="compact" /></VCol>
            <VCol v-if="uses('from')" cols="6" md="3"><VTextField v-model="f.from" type="date" :label="t('warehouse_management.standards.from')" /></VCol>
            <VCol v-if="uses('to')" cols="6" md="3"><VTextField v-model="f.to" type="date" :label="t('warehouse_management.standards.to')" /></VCol>
            <VCol v-if="uses('warehouse_id')" cols="6" md="3"><VSelect v-model="f.warehouse_id" :items="warehouses" clearable :label="t('warehouse_management.insights.warehouse')" /></VCol>
            <VCol v-if="uses('item')" cols="6" md="3"><VTextField v-model="f.item" :label="t('warehouse_management.insights.item')" /></VCol>
            <VCol v-if="uses('supplier_id')" cols="6" md="3"><VAutocomplete v-model="f.supplier_id" :items="suppliers" clearable :label="t('warehouse_management.insights.supplier')" /></VCol>
            <VCol v-if="uses('status')" cols="6" md="3"><VTextField v-model="f.status" :label="t('warehouse_management.reports.status')" /></VCol>
          </VRow>
          <input type="hidden" name="format" :value="f.format">
          <input type="hidden" name="report" :value="f.report">
          <input v-if="uses('from') && f.from" type="hidden" name="from" :value="f.from">
          <input v-if="uses('to') && f.to" type="hidden" name="to" :value="f.to">
          <input v-if="uses('item') && f.item" type="hidden" name="item" :value="f.item">
          <input v-if="uses('status') && f.status" type="hidden" name="status" :value="f.status">
          <input v-if="uses('warehouse_id') && f.warehouse_id" type="hidden" name="warehouse_id" :value="f.warehouse_id">
          <input v-if="uses('supplier_id') && f.supplier_id" type="hidden" name="supplier_id" :value="f.supplier_id">
          <input type="hidden" name="background" :value="f.background ? 1 : 0">
          <VAlert v-if="chosen?.sensitive" type="warning" variant="tonal" density="compact" class="mb-3">{{ t('warehouse_management.reports.sensitive') }}</VAlert>
          <VBtn type="submit" color="primary" :disabled="!f.report">{{ f.background ? t('warehouse_management.reports.queue') : t('warehouse_management.reports.run') }}</VBtn>
        </form>
      </VCardText>
    </VCard>

    <VCard border :title="t('warehouse_management.reports.history')">
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.reports.report') }}</th><th>{{ t('warehouse_management.reports.parameters') }}</th><th>{{ t('warehouse_management.reports.generated') }}</th><th class="text-end">{{ t('warehouse_management.reports.rows') }}</th><th>{{ t('warehouse_management.events.state') }}</th><th /></tr></thead>
          <tbody>
            <tr v-if="!runs.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('warehouse_management.reports.none') }}</td></tr>
            <tr v-for="r in runs" :key="r.id">
              <td>{{ r.title }} <VChip size="x-small">{{ r.format }}</VChip> <VChip v-if="r.sensitive" size="x-small" color="warning">{{ t('warehouse_management.reports.confidential') }}</VChip></td>
              <td class="text-caption">{{ Object.entries(r.parameters).map(([k, v]) => `${k}: ${v}`).join(' · ') }}</td>
              <td class="text-caption">{{ when(r.generated_at ?? r.requested_at) }}</td>
              <td class="text-end">{{ r.rows ?? '—' }}</td>
              <td><VChip size="x-small" :color="color(r.status)">{{ t(`warehouse_management.reports.state_${r.status}`) }}</VChip><div v-if="r.failure" class="text-caption text-error">{{ r.failure }}</div></td>
              <td><VBtn v-if="r.status === 'COMPLETE'" size="x-small" variant="text" :href="route('warehouse.reports.download', r.id)">{{ t('warehouse_management.reports.download') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>
