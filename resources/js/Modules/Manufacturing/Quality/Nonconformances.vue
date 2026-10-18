<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { ncrStatusColor, severityColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; number: string; source: string; status: string; severity: string; defect_code: string | null
  description: string; item_code: string | null; item_name: string | null; lot: string | null; owner: string | null
  due_date: string | null; is_overdue: boolean; created_at: string | null
}

const props = defineProps<{
  ncrs: { data: Row[]; current_page: number; last_page: number }
  filters: { status?: string; severity?: string; source?: string; search?: string }
  statuses: string[]
  severities: string[]
  sources: string[]
  repeat_defects: Array<{ code: string; count: number }>
  options: null | {
    materials: Array<{ id: number; code: string; name: string }>
    suppliers: Array<{ id: number; name: string }>
    people: Array<{ id: number; name: string }>
  }
  can: { create: boolean }
}>()

const search = ref(props.filters.search ?? '')
const status = ref<string | null>(props.filters.status ?? 'OPEN_ALL')
const severity = ref<string | null>(props.filters.severity ?? null)
const source = ref<string | null>(props.filters.source ?? null)
let timer: ReturnType<typeof setTimeout> | undefined
function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.quality.ncrs.index'), {
    search: search.value || undefined, status: status.value || undefined, severity: severity.value || undefined,
    source: source.value || undefined, ...extra,
  }, { preserveState: true, preserveScroll: true, replace: true })
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch([status, severity, source], () => apply())

const statusItems = [
  { title: t('manufacturing.quality.open_ncrs'), value: 'OPEN_ALL' },
  ...props.statuses.map(s => ({ title: t(`manufacturing.quality.ncr_status.${s}`), value: s })),
]

const dialog = ref(false)
const form = useForm({
  source: 'INTERNAL', description: '', defect_code: '', severity: 'MINOR', stock_item_id: null as number | null,
  supplier_id: null as number | null, qty_affected: null as number | null, owner_id: null as number | null, due_date: '',
})
function save() {
  form.transform(d => ({ ...d, due_date: d.due_date || null, defect_code: d.defect_code || null }))
    .post(route('manufacturing.quality.ncrs.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}

const headers = [
  { title: t('manufacturing.quality.number'), key: 'number' },
  { title: t('manufacturing.quality.severity_label'), key: 'severity' },
  { title: t('manufacturing.quality.description'), key: 'description', sortable: false },
  { title: t('manufacturing.quality.owner'), key: 'owner' },
  { title: t('manufacturing.orders.due'), key: 'due_date' },
  { title: t('manufacturing.bom.status'), key: 'status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.quality.ncrs_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.quality.ncrs_subtitle') }}</p>
      </div>
      <VBtn v-if="can.create" color="error" prepend-icon="tabler-alert-triangle" @click="dialog = true">{{ t('manufacturing.quality.raise_ncr') }}</VBtn>
    </div>

    <VCard v-if="repeat_defects.length" border class="mb-4">
      <VCardItem><VCardTitle class="text-body-1">{{ t('manufacturing.quality.repeat_defects') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.quality.repeat_defects_hint') }}</VCardSubtitle></VCardItem>
      <VCardText class="d-flex gap-2 flex-wrap">
        <VChip v-for="r in repeat_defects" :key="r.code" color="warning" variant="tonal" label @click="search = r.code">{{ r.code }} × {{ r.count }}</VChip>
      </VCardText>
    </VCard>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px" prepend-inner-icon="tabler-search" :label="t('manufacturing.quality.search_ncrs')" />
        <VSelect v-model="status" density="compact" hide-details clearable style="max-width: 220px" :items="statusItems" :label="t('manufacturing.bom.status')" />
        <VSelect v-model="severity" density="compact" hide-details clearable style="max-width: 180px"
                 :items="severities.map(s => ({ title: t(`manufacturing.quality.severity.${s}`), value: s }))" :label="t('manufacturing.quality.severity_label')" />
        <VSelect v-model="source" density="compact" hide-details clearable style="max-width: 200px"
                 :items="sources.map(s => ({ title: t(`manufacturing.quality.source.${s}`), value: s }))" :label="t('manufacturing.quality.source_label')" />
      </VCardText>
      <VDataTable :headers="headers" :items="ncrs.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.quality.ncrs.show', item.id))">
        <template #item.number="{ item }">
          <span class="font-weight-medium">{{ item.number }}</span>
          <div class="text-caption text-medium-emphasis">{{ t(`manufacturing.quality.source.${item.source}`) }}</div>
        </template>
        <template #item.severity="{ item }"><VChip size="small" label variant="tonal" :color="severityColor(item.severity)">{{ t(`manufacturing.quality.severity.${item.severity}`) }}</VChip></template>
        <template #item.description="{ item }">
          <div class="text-truncate" style="max-width: 380px">{{ item.description }}</div>
          <div class="text-caption text-medium-emphasis">
            <span v-if="item.defect_code">{{ item.defect_code }} · </span>{{ item.item_code ?? '' }}<span v-if="item.lot"> · {{ item.lot }}</span>
          </div>
        </template>
        <template #item.owner="{ item }">{{ item.owner ?? '—' }}</template>
        <template #item.due_date="{ item }">
          <span :class="{ 'text-error font-weight-medium': item.is_overdue }">{{ item.due_date ?? '—' }}</span>
        </template>
        <template #item.status="{ item }"><VChip size="small" label variant="tonal" :color="ncrStatusColor(item.status)">{{ t(`manufacturing.quality.ncr_status.${item.status}`) }}</VChip></template>
        <template #no-data><div class="py-8 text-medium-emphasis">{{ t('manufacturing.quality.no_ncrs') }}</div></template>
      </VDataTable>
      <VCardText v-if="ncrs.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="ncrs.current_page" :length="ncrs.last_page" total-visible="7" @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-if="options" v-model="dialog" max-width="640">
      <VCard :title="t('manufacturing.quality.raise_ncr')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" sm="6"><VSelect v-model="form.source" :items="sources.map(s => ({ title: t(`manufacturing.quality.source.${s}`), value: s }))" :label="t('manufacturing.quality.source_label')" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="form.severity" :items="severities.map(s => ({ title: t(`manufacturing.quality.severity.${s}`), value: s }))" :label="t('manufacturing.quality.severity_label')" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" rows="3" auto-grow :error-messages="form.errors.description" :label="t('manufacturing.quality.description')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.defect_code" :label="t('manufacturing.quality.defect_code')" :hint="t('manufacturing.quality.defect_code_hint')" persistent-hint /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model.number="form.qty_affected" type="number" min="0" step="any" :label="t('manufacturing.quality.qty_affected')" /></VCol>
            <VCol cols="12" sm="6"><VAutocomplete v-model="form.stock_item_id" :items="options.materials.map(m => ({ title: `${m.code} — ${m.name}`, value: m.id }))" clearable :label="t('manufacturing.orders.material')" /></VCol>
            <VCol cols="12" sm="6"><VAutocomplete v-model="form.supplier_id" :items="options.suppliers" item-value="id" item-title="name" clearable :label="t('manufacturing.quality.supplier')" /></VCol>
            <VCol cols="12" sm="6"><VAutocomplete v-model="form.owner_id" :items="options.people" item-value="id" item-title="name" clearable :error-messages="form.errors.owner_id" :label="t('manufacturing.quality.owner')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.due_date" type="date" :label="t('manufacturing.orders.due')" :hint="t('manufacturing.quality.due_hint')" persistent-hint /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="error" :loading="form.processing" :disabled="!form.description.trim()" @click="save">{{ t('manufacturing.quality.raise_ncr') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
