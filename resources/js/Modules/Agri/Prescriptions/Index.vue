<script setup lang="ts">
/**
 * Variable-rate prescriptions: the list (what waits for approval first) and a new one — a field,
 * the operation and product, its unit, and where the zones come from: the field's management
 * zones, or the latest NDVI capture classified into classes, with a base rate and a step per class.
 */
import { computed, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import { money, num, statusColor } from '../Components/agri'

type FieldOpt = { id: string; code: string; name: string; hectares: number | null; zones: number; ndvi_on: string | null; mapped: boolean }
const props = defineProps<{
  filters: { farm: string | null; status: string | null }
  prescriptions: Array<Record<string, any>>
  farms: Array<{ id: string; name: string; fields: FieldOpt[] }>
  stock_items: Array<{ id: number; name: string; uom: string; cost: number }>
  options: { operations: string[]; units: string[]; methods: string[] }
  can: { plan: boolean; approve: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.prescriptions.${key}`, params ?? {})
const STATUSES = ['draft', 'submitted', 'approved', 'sent', 'applied', 'cancelled']
const filter = (o: Record<string, any>) => router.get(route('agri.prescriptions.index'), { ...props.filters, ...o }, { preserveState: true, preserveScroll: true })

const open = ref(false)
const form = useForm({ farm_id: props.filters.farm ?? props.farms[0]?.id ?? null, field_id: null as string | null, name: '', operation: 'fertiliser', stock_item_id: null as number | null,
  unit: 'kg_ha', per_stock_unit: 1, source: 'zones', classes: 3, method: 'equal_interval', flip: false, base_rate: 200, step_pct: 15, flat_rate: null as number | null, planned_on: null as string | null })
const farm = computed(() => props.farms.find(f => f.id === form.farm_id))
const field = computed(() => farm.value?.fields.find(f => f.id === form.field_id))
const item = computed(() => props.stock_items.find(i => i.id === form.stock_item_id))
watch(() => form.operation, op => { form.unit = op === 'seeding' ? 'seeds_ha' : op === 'crop_protection' ? 'l_ha' : 'kg_ha' })
watch(field, f => { if (f && !f.zones && f.ndvi_on) form.source = 'ndvi'; if (f && f.zones && !f.ndvi_on) form.source = 'zones' })
const preview = computed(() => {
  const n = form.source === 'zones' ? 3 : Number(form.classes) || 3
  return Array.from({ length: n }, (_, i) => {
    const c = i + 1
    const r = Number(form.base_rate) * (1 + (form.flip ? -1 : 1) * (c - (n + 1) / 2) * Number(form.step_pct) / 100)
    return { c, rate: Math.max(0, Math.round(r * 100) / 100) }
  })
})
const save = () => form.post(route('agri.prescriptions.store'), { onSuccess: () => { open.value = false } })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.hub.production')">{{ k('breadcrumb') }}</Link></div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <VBtn v-if="can.plan" color="primary" prepend-icon="tabler-plus" @click="open = true">{{ k('new') }}</VBtn>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <VSelect :model-value="filters.farm" :items="[{ value: null, title: k('all_farms') }, ...farms.map(f => ({ value: f.id, title: f.name }))]" density="compact" hide-details style="max-width: 240px" @update:model-value="v => filter({ farm: v })" />
      <VChip :variant="!filters.status ? 'flat' : 'tonal'" :color="!filters.status ? 'primary' : undefined" @click="filter({ status: null })">{{ k('all') }}</VChip>
      <VChip v-for="s in STATUSES" :key="s" :variant="filters.status === s ? 'flat' : 'tonal'" :color="filters.status === s ? 'primary' : undefined" @click="filter({ status: s })">{{ k(`status.${s}`) }}</VChip>
    </div>

    <VCard><VTable density="compact">
      <thead><tr><th>{{ k('code') }}</th><th>{{ k('field') }}</th><th>{{ k('product') }}</th><th class="text-end">{{ k('area') }}</th><th class="text-end">{{ k('quantity') }}</th><th class="text-end">{{ k('cost') }}</th><th>{{ k('status_label') }}</th><th>{{ k('people') }}</th></tr></thead>
      <tbody>
        <tr v-for="p in prescriptions" :key="p.id">
          <td><Link :href="route('agri.prescriptions.show', p.id)">{{ p.code }}</Link> <span class="text-caption">v{{ p.version }}</span><div class="text-caption">{{ p.name }}</div></td>
          <td>{{ p.field }}<div class="text-caption">{{ p.farm }}</div></td>
          <td>{{ p.product }}<div class="text-caption">{{ k(`operation.${p.operation}`) }} · {{ k(`source.${p.source}`) }}</div></td>
          <td class="text-end">{{ num(p.area_ha, 2) }} ha</td>
          <td class="text-end">{{ num(p.total_quantity, 1) }} {{ k(`base_unit.${p.unit}`) }}</td>
          <td class="text-end">{{ money(p.total_cost) }}</td>
          <td><VChip size="x-small" :color="statusColor(p.status)">{{ k(`status.${p.status}`) }}</VChip></td>
          <td class="text-caption">{{ p.drafted_by }}<template v-if="p.approved_by"> → {{ p.approved_by }}</template></td>
        </tr>
        <tr v-if="!prescriptions.length"><td colspan="8" class="text-center text-medium-emphasis py-4">{{ k('none') }}</td></tr>
      </tbody>
    </VTable></VCard>

    <VDialog v-model="open" max-width="720">
      <VCard :title="k('new')" :subtitle="k('new_intro')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="form.farm_id" :items="farms.map(f => ({ value: f.id, title: f.name }))" :label="k('f.farm')" :error-messages="form.errors.farm_id" density="compact" @update:model-value="form.field_id = null" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="form.field_id" :items="(farm?.fields ?? []).map(f => ({ value: f.id, title: `${f.code} ${f.name}`, props: { subtitle: `${k('f.zones_n', { n: f.zones })} · ${f.ndvi_on ? k('f.ndvi_on', { d: f.ndvi_on }) : k('f.no_ndvi')}` } }))" item-props :label="k('f.field')" :error-messages="form.errors.field_id" density="compact" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="form.operation" :items="options.operations.map(o => ({ value: o, title: k(`operation.${o}`) }))" :label="k('f.operation')" density="compact" /></VCol>
            <VCol cols="12" md="6"><VAutocomplete v-model="form.stock_item_id" :items="stock_items.map(i => ({ value: i.id, title: i.name }))" :label="k('f.product')" :error-messages="form.errors.stock_item_id" density="compact" /></VCol>
            <VCol cols="6" md="4"><VSelect v-model="form.unit" :items="options.units.map(u => ({ value: u, title: k(`unit.${u}`) }))" :label="k('f.unit')" density="compact" /></VCol>
            <VCol cols="6" md="4"><VTextField v-model.number="form.per_stock_unit" type="number" :label="k('f.per_stock_unit', { uom: item?.uom ?? '' })" :hint="k('f.per_stock_unit_hint')" persistent-hint density="compact" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.name" :label="k('f.name')" density="compact" /></VCol>
            <VCol cols="12">
              <VRadioGroup v-model="form.source" inline hide-details :label="k('f.source')">
                <VRadio value="zones" :label="k('source.zones')" :disabled="!!field && !field.zones" />
                <VRadio value="ndvi" :label="k('source.ndvi')" :disabled="!!field && !field.ndvi_on" />
              </VRadioGroup>
              <div v-if="form.errors.source" class="text-caption text-error">{{ form.errors.source }}</div>
            </VCol>
            <template v-if="form.source === 'ndvi'">
              <VCol cols="6" md="4"><VSelect v-model="form.classes" :items="[2, 3, 4, 5, 6, 7]" :label="k('f.classes')" density="compact" /></VCol>
              <VCol cols="6" md="4"><VSelect v-model="form.method" :items="options.methods.map(m => ({ value: m, title: k(`method.${m}`) }))" :label="k('f.method')" density="compact" /></VCol>
            </template>
            <VCol cols="6" md="4"><VTextField v-model.number="form.base_rate" type="number" :label="k('f.base_rate')" :suffix="k(`unit.${form.unit}`)" :error-messages="form.errors.base_rate" density="compact" /></VCol>
            <VCol cols="6" md="4"><VTextField v-model.number="form.step_pct" type="number" :label="k('f.step_pct')" suffix="%" density="compact" /></VCol>
            <VCol cols="6" md="4"><VTextField v-model.number="form.flat_rate" type="number" :label="k('f.flat_rate')" :hint="k('f.flat_rate_hint')" persistent-hint density="compact" /></VCol>
            <VCol cols="12" md="8"><VSwitch v-model="form.flip" :label="form.flip ? k('f.flip_on') : k('f.flip_off')" color="primary" density="compact" hide-details inset /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.planned_on" type="date" :label="k('f.planned_on')" density="compact" /></VCol>
          </VRow>
          <div class="text-caption mt-2">{{ k('f.preview') }}
            <VChip v-for="c in preview" :key="c.c" size="x-small" class="ms-1">{{ k('class_n', { n: c.c }) }}: {{ num(c.rate, 2) }}</VChip>
            <span class="ms-1">{{ k('f.preview_note') }}</span>
          </div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="open = false">{{ k('cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="form.processing" :disabled="!form.field_id || !form.stock_item_id" @click="save">{{ k('create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
