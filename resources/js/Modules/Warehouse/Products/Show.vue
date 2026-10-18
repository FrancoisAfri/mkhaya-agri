<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Unit = { id: number; code: string; title: string; dimension: string }
type Pack = { uuid: string; level: string; name: string | null; uom_id: number; uom: string | null; qty_in_base: number; gtin: string | null; length_cm: number | null; width_cm: number | null; height_cm: number | null; gross_weight_kg: number | null; is_active: boolean }
type Barcode = { uuid: string; barcode: string; symbology: string; source: string; is_primary: boolean; is_active: boolean; duplicate_reason: string | null; packaging: string | null; supplier_id: number | null; shared_with: string[] }

const props = defineProps<{
  item: { id: string; code: string; name: string; uom: string; base_uom_id: number; status: string; status_reason: string | null; status_changed_at: string | null; cost_method: string; standard_cost: number; reorder_level: number; qty_on_hand: number; has_movements: boolean; owner_party_id: number | null; is_valued: boolean }
  parties: Array<{ id: number; name: string }>
  profile: Record<string, any>
  physical: Record<string, any>
  packaging: Pack[]
  barcodes: Barcode[]
  identifiers: Array<{ uuid: string; kind: string; code: string; supplier_id: number | null; party_name: string | null; description: string | null }>
  bundle: Array<{ component_id: string; code: string; name: string; qty: number }>
  substitutes: Array<{ id: string; uuid?: string; code?: string; name?: string; ratio: number; requires_approval: boolean; restriction: string | null }>
  history: Array<{ action: string; changes: Record<string, [unknown, unknown]> | null; reason: string | null; actor: string | null; at: string | null }>
  units: Unit[]
  categories: Array<{ id: number; name: string }>
  suppliers: Array<{ id: number; name: string }>
  statuses: string[]
  packaging_levels: string[]
  symbologies: string[]
  identifier_kinds: string[]
  can: { manage: boolean; status: boolean; approve_duplicate: boolean }
}>()

const tab = ref('overview')
const statusColor = (s: string) => ({ ACTIVE: 'success', DISCONTINUED: 'secondary', BLOCKED: 'error', RECALLED: 'error', QUARANTINED: 'warning', OBSOLETE: 'secondary' } as Record<string, string>)[s]
const unitCode = (id: number | null) => props.units.find(u => u.id === id)?.code ?? '—'
const supplierName = (id: number | null) => props.suppliers.find(s => s.id === id)?.name
const opts = { preserveScroll: true }

// ── Overview ────────────────────────────────────────────────────────────────
const form = useForm({
  name: props.item.name, uom: props.item.uom, reorder_level: props.item.reorder_level, owner_party_id: props.item.owner_party_id,
  ...props.profile, ...props.physical,
  dimensions: { length: props.physical.dimensions?.length ?? null, width: props.physical.dimensions?.width ?? null, height: props.physical.dimensions?.height ?? null },
  is_variable_weight: !!props.profile.is_variable_weight, requires_inspection: !!props.profile.requires_inspection,
  is_lot_tracked: !!props.physical.is_lot_tracked, is_serial_tracked: !!props.physical.is_serial_tracked,
})
function saveOverview() {
  form.transform(({ kind, ...d }: any) => d).patch(route('warehouse.products.update', props.item.id), opts)
}

// ── Status ──────────────────────────────────────────────────────────────────
const statusDialog = ref(false)
const statusForm = useForm({ status: props.item.status, reason: '', quarantine_stock: true })
const holdStatuses = ['RECALLED', 'QUARANTINED']
function saveStatus() {
  statusForm.post(route('warehouse.products.status', props.item.id), { ...opts, onSuccess: () => { statusDialog.value = false } })
}

// ── Packaging ───────────────────────────────────────────────────────────────
const packDialog = ref(false)
const editingPack = ref<Pack | null>(null)
const packForm = useForm({ level: 'CARTON', name: '', uom_id: null as number | null, qty_in_base: null as number | null, gtin: '', length_cm: null as number | null, width_cm: null as number | null, height_cm: null as number | null, gross_weight_kg: null as number | null, is_active: true })
function openPack(p: Pack | null) {
  editingPack.value = p
  packForm.reset()
  if (p) Object.assign(packForm, { level: p.level, name: p.name ?? '', uom_id: p.uom_id, qty_in_base: p.qty_in_base, gtin: p.gtin ?? '', length_cm: p.length_cm, width_cm: p.width_cm, height_cm: p.height_cm, gross_weight_kg: p.gross_weight_kg, is_active: p.is_active })
  packForm.clearErrors()
  packDialog.value = true
}
function savePack() {
  const done = { ...opts, onSuccess: () => { packDialog.value = false } }
  if (editingPack.value) packForm.patch(route('warehouse.products.packaging.update', [props.item.id, editingPack.value.uuid]), done)
  else packForm.post(route('warehouse.products.packaging.store', props.item.id), done)
}
function removePack(p: Pack) {
  router.delete(route('warehouse.products.packaging.destroy', [props.item.id, p.uuid]), opts)
}

// ── Barcodes ────────────────────────────────────────────────────────────────
const barcodeDialog = ref(false)
const barcodeForm = useForm({ barcode: '', symbology: null as string | null, source: 'SUPPLIER', packaging_id: null as string | null, supplier_id: null as number | null, is_primary: false, duplicate_reason: '' })
const needsReason = computed(() => /already registered/.test((barcodeForm.errors as any).refused ?? ''))
function saveBarcode() {
  barcodeForm.post(route('warehouse.products.barcodes.store', props.item.id), { ...opts, onSuccess: () => { barcodeDialog.value = false; barcodeForm.reset() } })
}
function generateBarcode() {
  router.post(route('warehouse.products.barcodes.generate', props.item.id), {}, opts)
}
function deactivateBarcode(b: Barcode) {
  router.delete(route('warehouse.products.barcodes.destroy', [props.item.id, b.uuid]), opts)
}

// ── Codes others use ────────────────────────────────────────────────────────
const idForm = useForm({ kind: 'SUPPLIER', code: '', supplier_id: null as number | null, party_name: '', description: '' })
function addIdentifier() {
  idForm.post(route('warehouse.products.identifiers.store', props.item.id), { ...opts, onSuccess: () => idForm.reset() })
}
function removeIdentifier(uuid: string) {
  router.delete(route('warehouse.products.identifiers.destroy', [props.item.id, uuid]), opts)
}

// ── Bundles and substitutes ─────────────────────────────────────────────────
const itemOptions = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) {
  const { data } = await axios.get(route('warehouse.products.search'), { params: { q } })
  itemOptions.value = data.filter((o: { id: string }) => o.id !== props.item.id)
}
const components = ref(props.bundle.map(b => ({ id: b.component_id, title: `${b.code} — ${b.name}`, qty: b.qty })))
const newComponent = ref<string | null>(null)
function addComponent() {
  const o = itemOptions.value.find(x => x.id === newComponent.value)
  if (o && !components.value.some(c => c.id === o.id)) components.value.push({ id: o.id, title: o.title, qty: 1 })
  newComponent.value = null
}
function saveBundle() {
  router.put(route('warehouse.products.bundle', props.item.id), { components: components.value.map(c => ({ id: c.id, qty: c.qty })) }, opts)
}
const subForm = useForm({ substitute_id: null as string | null, ratio: 1, requires_approval: true, restriction: '' })
function addSubstitute() {
  subForm.post(route('warehouse.products.substitutes.store', props.item.id), { ...opts, onSuccess: () => subForm.reset() })
}
function removeSubstitute(id: string) {
  router.delete(route('warehouse.products.substitutes.destroy', [props.item.id, id]), opts)
}

const show = (v: unknown) => v === null || v === undefined || v === '' ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.products.index')">{{ t('warehouse_management.products.title') }}</a></div>
        <h4 class="text-h4">{{ item.code }} — {{ item.name }}</h4>
        <div class="d-flex align-center gap-2 mt-1 flex-wrap">
          <VChip size="small" label :color="statusColor(item.status)" variant="tonal">{{ t(`warehouse_management.item_status.${item.status}`) }}</VChip>
          <span v-if="item.status_reason" class="text-body-2 text-medium-emphasis">{{ item.status_reason }}</span>
          <span class="text-body-2">· {{ t('warehouse_management.products.on_hand') }}: {{ item.qty_on_hand }} {{ item.uom }}</span>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-printer" :href="route('warehouse.products.labels', { items: [item.id] })">{{ t('warehouse_management.products.labels') }}</VBtn>
        <VBtn v-if="can.status" variant="tonal" color="warning" prepend-icon="tabler-flag" @click="statusDialog = true">{{ t('warehouse_management.products.change_status') }}</VBtn>
      </div>
    </div>

    <RefusalAlert />

    <VTabs v-model="tab" class="mb-4">
      <VTab value="overview">{{ t('warehouse_management.products.tab.overview') }}</VTab>
      <VTab value="units">{{ t('warehouse_management.products.tab.units') }}</VTab>
      <VTab value="barcodes">{{ t('warehouse_management.products.tab.barcodes') }}</VTab>
      <VTab value="codes">{{ t('warehouse_management.products.tab.codes') }}</VTab>
      <VTab value="related">{{ t('warehouse_management.products.tab.related') }}</VTab>
      <VTab value="history">{{ t('warehouse_management.products.tab.history') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- Overview -->
      <VWindowItem value="overview">
        <VCard border>
          <VCardText>
            <VRow dense>
              <VCol cols="12" md="6"><VTextField v-model="form.name" :disabled="!can.manage" :label="t('warehouse_management.common.name')" :error-messages="form.errors.name" /></VCol>
              <VCol cols="12" md="3"><VTextField v-model="form.brand" :disabled="!can.manage" :label="t('warehouse_management.products.brand')" /></VCol>
              <VCol cols="12" md="3">
                <VSelect v-model="form.uom" :items="units" item-title="title" item-value="code" :disabled="!can.manage || item.has_movements"
                         :label="t('warehouse_management.products.base_unit')" :hint="item.has_movements ? t('warehouse_management.products.base_unit_locked') : ''" persistent-hint />
              </VCol>
              <VCol cols="12"><VTextarea v-model="form.description" rows="2" auto-grow :disabled="!can.manage" :label="t('warehouse_management.products.description')" /></VCol>
              <VCol cols="12" md="6">
                <VAutocomplete v-model="form.owner_party_id" :items="parties" item-title="name" item-value="id" clearable :disabled="!can.manage || item.has_movements"
                               :label="t('warehouse_management.products.owner')" :hint="item.has_movements ? t('warehouse_management.products.owner_locked') : t('warehouse_management.products.owner_hint')" persistent-hint />
              </VCol>
              <VCol cols="12" md="6" class="d-flex align-center">
                <VChip :color="item.is_valued ? 'primary' : 'secondary'" variant="tonal">{{ item.is_valued ? t('warehouse_management.products.valued') : t('warehouse_management.products.not_valued') }}</VChip>
              </VCol>
              <VCol cols="12" md="4">
                <VSelect v-model="form.category_id" :items="categories" item-title="name" item-value="id" clearable :disabled="!can.manage" :label="t('warehouse_management.products.category')" />
              </VCol>
              <VCol cols="6" md="4"><VSwitch v-model="form.is_lot_tracked" color="primary" :disabled="!can.manage" :label="t('warehouse_management.products.lot_tracked')" /></VCol>
              <VCol cols="6" md="4"><VSwitch v-model="form.is_serial_tracked" color="primary" :disabled="!can.manage" :label="t('warehouse_management.products.serial_tracked')" /></VCol>

              <VCol cols="12"><div class="text-subtitle-2">{{ t('warehouse_management.products.section.physical') }}</div></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.weight_kg" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.weight_kg')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.dimensions.length" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.locations.length_cm')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.dimensions.width" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.locations.width_cm')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.dimensions.height" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.locations.height_cm')" /></VCol>
              <VCol cols="12" md="4"><VSwitch v-model="form.is_variable_weight" color="primary" :disabled="!can.manage" :label="t('warehouse_management.products.variable_weight')" /></VCol>

              <VCol cols="12"><div class="text-subtitle-2">{{ t('warehouse_management.products.section.units') }}</div></VCol>
              <VCol v-for="k in ['purchase_uom_id', 'storage_uom_id', 'issue_uom_id']" :key="k" cols="12" md="4">
                <VSelect v-model="(form as any)[k]" :items="units" item-title="title" item-value="id" clearable :disabled="!can.manage" :label="t(`warehouse_management.products.${k}`)" />
              </VCol>

              <VCol cols="12"><div class="text-subtitle-2">{{ t('warehouse_management.products.section.replenishment') }}</div></VCol>
              <VCol cols="6" md="2"><VTextField v-model.number="form.reorder_level" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.reorder_level')" /></VCol>
              <VCol cols="6" md="2"><VTextField v-model.number="form.safety_stock" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.safety_stock')" /></VCol>
              <VCol cols="6" md="2"><VTextField v-model.number="form.max_stock" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.max_stock')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.reorder_qty" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.reorder_qty')" /></VCol>
              <VCol cols="12" md="3"><VTextField v-model.number="form.lead_time_days" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.lead_time_days')" /></VCol>
              <VCol cols="12" class="text-caption text-medium-emphasis">{{ t('warehouse_management.products.costing', { method: item.cost_method, cost: item.standard_cost }) }}</VCol>

              <VCol cols="12"><div class="text-subtitle-2">{{ t('warehouse_management.products.section.storage') }}</div></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.shelf_life_days" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.shelf_life_days')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.min_remaining_shelf_life_days" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.min_remaining_shelf_life_days')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.expiry_warning_days" type="number" min="0" :disabled="!can.manage" :label="t('warehouse_management.products.expiry_warning_days')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model="form.hazard_class" :disabled="!can.manage" :label="t('warehouse_management.products.hazard_class')" /></VCol>
              <VCol cols="6" md="3"><VSelect v-model="form.loss_risk" :disabled="!can.manage" clearable :items="['LOW', 'MEDIUM', 'HIGH'].map(r => ({ value: r, title: t(`warehouse_management.loss_risk.${r}`) }))"
                :label="t('warehouse_management.products.loss_risk')" :hint="props.profile.abc_class ? t('warehouse_management.products.abc', { c: props.profile.abc_class }) : undefined" persistent-hint /></VCol>
              <VCol cols="6" md="3"><VSelect v-model="form.rotation" :disabled="!can.manage" clearable :items="['FEFO', 'FIFO', 'LIFO', 'NONE'].map(r => ({ value: r, title: t(`warehouse_management.rotation.${r}`) }))"
                :label="t('warehouse_management.products.rotation')" :hint="t('warehouse_management.products.rotation_hint')" persistent-hint /></VCol>
              <VCol cols="12" md="6"><VCombobox v-model="form.expiry_alert_days" :disabled="!can.manage" multiple chips closable-chips :label="t('warehouse_management.products.expiry_alerts')"
                :hint="t('warehouse_management.products.expiry_alerts_hint')" persistent-hint /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.temperature_min_c" type="number" :disabled="!can.manage" :label="t('warehouse_management.locations.temp_min')" /></VCol>
              <VCol cols="6" md="3"><VTextField v-model.number="form.temperature_max_c" type="number" :disabled="!can.manage" :label="t('warehouse_management.locations.temp_max')" :error-messages="form.errors.temperature_max_c" /></VCol>
              <VCol cols="12" md="6"><VSwitch v-model="form.requires_inspection" color="primary" :disabled="!can.manage" :label="t('warehouse_management.products.requires_inspection')" /></VCol>
              <VCol cols="12" md="6"><VTextarea v-model="form.storage_conditions" rows="2" auto-grow :disabled="!can.manage" :label="t('warehouse_management.products.storage_conditions')" /></VCol>
              <VCol cols="12" md="6"><VTextarea v-model="form.handling_instructions" rows="2" auto-grow :disabled="!can.manage" :label="t('warehouse_management.products.handling_instructions')" /></VCol>
            </VRow>
          </VCardText>
          <VCardActions v-if="can.manage">
            <VSpacer />
            <VBtn color="primary" :loading="form.processing" @click="saveOverview">{{ t('warehouse_management.common.save') }}</VBtn>
          </VCardActions>
        </VCard>
      </VWindowItem>

      <!-- Units and packaging -->
      <VWindowItem value="units">
        <VCard border>
          <VCardTitle class="d-flex justify-space-between align-center">
            {{ t('warehouse_management.products.packaging_title') }}
            <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="openPack(null)">{{ t('warehouse_management.common.add') }}</VBtn>
          </VCardTitle>
          <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.products.packaging_hint', { base: item.uom }) }}</VCardText>
          <VCardText v-if="!packaging.length" class="text-medium-emphasis">{{ t('warehouse_management.products.no_packaging') }}</VCardText>
          <VTable v-else density="compact">
            <thead>
              <tr>
                <th>{{ t('warehouse_management.products.level') }}</th><th>{{ t('warehouse_management.products.unit') }}</th>
                <th class="text-end">{{ t('warehouse_management.products.holds', { base: item.uom }) }}</th><th>GTIN</th>
                <th>{{ t('warehouse_management.products.size') }}</th><th />
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in packaging" :key="p.uuid" :class="{ 'opacity-60': !p.is_active }">
                <td>{{ t(`warehouse_management.packaging_level.${p.level}`) }}<span v-if="p.name" class="text-medium-emphasis"> · {{ p.name }}</span></td>
                <td>{{ p.uom }}</td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">{{ p.qty_in_base }}</td>
                <td style="font-family: ui-monospace, monospace">{{ p.gtin ?? '—' }}</td>
                <td class="text-caption">
                  <template v-if="p.length_cm">{{ p.length_cm }}×{{ p.width_cm }}×{{ p.height_cm }} cm</template>
                  <template v-if="p.gross_weight_kg"> · {{ p.gross_weight_kg }} kg</template>
                </td>
                <td class="text-end text-no-wrap">
                  <template v-if="can.manage">
                    <VBtn icon="tabler-pencil" size="small" variant="text" :aria-label="t('warehouse_management.common.edit')" @click="openPack(p)" />
                    <VBtn icon="tabler-trash" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="removePack(p)" />
                  </template>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- Barcodes -->
      <VWindowItem value="barcodes">
        <VCard border>
          <VCardTitle class="d-flex justify-space-between align-center flex-wrap gap-2">
            {{ t('warehouse_management.products.tab.barcodes') }}
            <div v-if="can.manage" class="d-flex gap-2">
              <VBtn size="small" variant="tonal" prepend-icon="tabler-barcode" @click="generateBarcode">{{ t('warehouse_management.products.generate_barcode') }}</VBtn>
              <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="barcodeDialog = true">{{ t('warehouse_management.products.add_barcode') }}</VBtn>
            </div>
          </VCardTitle>
          <VCardText v-if="!barcodes.length" class="text-medium-emphasis">{{ t('warehouse_management.products.no_barcodes') }}</VCardText>
          <VTable v-else density="compact">
            <thead>
              <tr><th>{{ t('warehouse_management.products.barcode') }}</th><th>{{ t('warehouse_management.products.symbology') }}</th><th>{{ t('warehouse_management.products.source') }}</th><th>{{ t('warehouse_management.products.level') }}</th><th /><th /></tr>
            </thead>
            <tbody>
              <tr v-for="b in barcodes" :key="b.uuid" :class="{ 'opacity-60': !b.is_active }">
                <td style="font-family: ui-monospace, monospace">
                  {{ b.barcode }}
                  <VChip v-if="b.is_primary" size="x-small" color="primary" variant="tonal" class="ms-1">{{ t('warehouse_management.products.primary') }}</VChip>
                </td>
                <td>{{ b.symbology }}</td>
                <td>{{ t(`warehouse_management.barcode_source.${b.source}`) }}<span v-if="supplierName(b.supplier_id)" class="text-caption"> · {{ supplierName(b.supplier_id) }}</span></td>
                <td>{{ b.packaging ? t(`warehouse_management.packaging_level.${b.packaging}`) : t('warehouse_management.products.base_unit') }}</td>
                <td>
                  <VTooltip v-if="b.shared_with.length" location="top">
                    <template #activator="{ props: tip }"><VChip v-bind="tip" size="x-small" color="warning" variant="tonal">{{ t('warehouse_management.products.shared_with', { codes: b.shared_with.join(', ') }) }}</VChip></template>
                    {{ b.duplicate_reason }}
                  </VTooltip>
                </td>
                <td class="text-end">
                  <VBtn v-if="can.manage && b.is_active" size="small" variant="text" @click="deactivateBarcode(b)">{{ t('warehouse_management.products.deactivate') }}</VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- Codes others use -->
      <VWindowItem value="codes">
        <VCard border>
          <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.products.codes_hint') }}</VCardText>
          <VTable v-if="identifiers.length" density="compact">
            <tbody>
              <tr v-for="i in identifiers" :key="i.uuid">
                <td>{{ t(`warehouse_management.identifier_kind.${i.kind}`) }}</td>
                <td style="font-family: ui-monospace, monospace">{{ i.code }}</td>
                <td>{{ supplierName(i.supplier_id) ?? i.party_name ?? '' }}</td>
                <td>{{ i.description }}</td>
                <td class="text-end"><VBtn v-if="can.manage" icon="tabler-trash" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="removeIdentifier(i.uuid)" /></td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="can.manage">
            <VRow dense>
              <VCol cols="12" md="2"><VSelect v-model="idForm.kind" :items="identifier_kinds.map(k => ({ value: k, title: t(`warehouse_management.identifier_kind.${k}`) }))" :label="t('warehouse_management.products.code_kind')" /></VCol>
              <VCol cols="12" md="3"><VTextField v-model="idForm.code" :label="t('warehouse_management.common.code')" :error-messages="idForm.errors.code" /></VCol>
              <VCol v-if="idForm.kind === 'SUPPLIER'" cols="12" md="3"><VAutocomplete v-model="idForm.supplier_id" :items="suppliers" item-title="name" item-value="id" clearable :label="t('warehouse_management.products.supplier')" /></VCol>
              <VCol v-else cols="12" md="3"><VTextField v-model="idForm.party_name" :label="t('warehouse_management.products.party')" /></VCol>
              <VCol cols="12" md="3"><VTextField v-model="idForm.description" :label="t('warehouse_management.products.description')" /></VCol>
              <VCol cols="12" md="1"><VBtn color="primary" block :loading="idForm.processing" @click="addIdentifier">{{ t('warehouse_management.common.add') }}</VBtn></VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Bundles and substitutes -->
      <VWindowItem value="related">
        <VRow>
          <VCol cols="12" md="6">
            <VCard border>
              <VCardTitle>{{ t('warehouse_management.products.bundle_title') }}</VCardTitle>
              <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.products.bundle_hint') }}</VCardText>
              <VCardText>
                <div v-for="(c, i) in components" :key="c.id" class="d-flex align-center gap-2 mb-2">
                  <div class="flex-grow-1">{{ c.title }}</div>
                  <VTextField v-model.number="c.qty" type="number" min="0" density="compact" hide-details style="max-width: 110px" :disabled="!can.manage" />
                  <VBtn v-if="can.manage" icon="tabler-x" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="components.splice(i, 1)" />
                </div>
                <div v-if="can.manage" class="d-flex gap-2 align-center">
                  <VAutocomplete v-model="newComponent" :items="itemOptions" item-title="title" item-value="id" density="compact" hide-details
                                 :label="t('warehouse_management.products.add_component')" @update:search="searchItems" @update:model-value="addComponent" />
                  <VBtn color="primary" @click="saveBundle">{{ t('warehouse_management.common.save') }}</VBtn>
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="12" md="6">
            <VCard border>
              <VCardTitle>{{ t('warehouse_management.products.substitutes_title') }}</VCardTitle>
              <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.products.substitutes_hint') }}</VCardText>
              <VList v-if="substitutes.length" density="compact">
                <VListItem v-for="s in substitutes" :key="s.id" :title="`${s.code} — ${s.name}`"
                           :subtitle="[t('warehouse_management.products.ratio', { r: s.ratio }), s.requires_approval ? t('warehouse_management.products.needs_approval') : null, s.restriction].filter(Boolean).join(' · ')">
                  <template v-if="can.manage" #append><VBtn icon="tabler-trash" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="removeSubstitute(s.id)" /></template>
                </VListItem>
              </VList>
              <VCardText v-if="can.manage">
                <VAutocomplete v-model="subForm.substitute_id" :items="itemOptions" item-title="title" item-value="id" density="compact"
                               :label="t('warehouse_management.products.substitute')" :error-messages="subForm.errors.substitute_id" @update:search="searchItems" />
                <div class="d-flex gap-2">
                  <VTextField v-model.number="subForm.ratio" type="number" min="0" density="compact" :label="t('warehouse_management.products.ratio_label')" style="max-width: 140px" />
                  <VSwitch v-model="subForm.requires_approval" color="primary" density="compact" :label="t('warehouse_management.products.needs_approval')" />
                </div>
                <VTextField v-model="subForm.restriction" density="compact" :label="t('warehouse_management.products.restriction')" />
                <VBtn color="primary" :loading="subForm.processing" @click="addSubstitute">{{ t('warehouse_management.common.add') }}</VBtn>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- History -->
      <VWindowItem value="history">
        <VCard border>
          <VCardText v-if="!history.length" class="text-medium-emphasis">{{ t('warehouse_management.history.none') }}</VCardText>
          <VTable v-else density="compact">
            <tbody>
              <tr v-for="(h, i) in history" :key="i">
                <td class="text-no-wrap">{{ h.at ? new Date(h.at).toLocaleString() : '' }}</td>
                <td><VChip size="small" label variant="tonal">{{ t(`warehouse_management.item_action.${h.action}`) }}</VChip></td>
                <td>
                  <div v-for="(pair, field) in (h.changes ?? {})" :key="field" class="text-caption">
                    <span class="font-weight-medium">{{ field }}</span>: {{ show(pair[0]) }} → {{ show(pair[1]) }}
                  </div>
                </td>
                <td>{{ h.reason }}</td>
                <td>{{ h.actor ?? t('warehouse_management.history.system') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Status -->
    <VDialog v-model="statusDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.products.change_status') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="statusForm.status" :items="statuses.map(s => ({ value: s, title: t(`warehouse_management.item_status.${s}`) }))" :label="t('warehouse_management.products.status')" />
          <p class="text-body-2 text-medium-emphasis">{{ t(`warehouse_management.item_status_rule.${statusForm.status}`) }}</p>
          <VTextarea v-model="statusForm.reason" rows="2" auto-grow :label="t('warehouse_management.common.reason')" :error-messages="statusForm.errors.reason" />
          <VSwitch v-if="holdStatuses.includes(statusForm.status)" v-model="statusForm.quarantine_stock" color="warning" :label="t('warehouse_management.products.quarantine_stock')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="statusDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="warning" :loading="statusForm.processing" @click="saveStatus">{{ t('warehouse_management.common.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Packaging -->
    <VDialog v-model="packDialog" max-width="620">
      <VCard>
        <VCardTitle>{{ editingPack ? t('warehouse_management.common.edit') : t('warehouse_management.common.add') }} — {{ t('warehouse_management.products.packaging_title') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VSelect v-model="packForm.level" :items="packaging_levels.map(l => ({ value: l, title: t(`warehouse_management.packaging_level.${l}`) }))" :label="t('warehouse_management.products.level')" /></VCol>
            <VCol cols="6"><VTextField v-model="packForm.name" :label="t('warehouse_management.common.name')" /></VCol>
            <VCol cols="6"><VSelect v-model="packForm.uom_id" :items="units" item-title="title" item-value="id" :label="t('warehouse_management.products.unit')" :error-messages="packForm.errors.uom_id" /></VCol>
            <VCol cols="6"><VTextField v-model.number="packForm.qty_in_base" type="number" min="0" :label="t('warehouse_management.products.holds', { base: item.uom })" :error-messages="packForm.errors.qty_in_base" /></VCol>
            <VCol cols="12"><VTextField v-model="packForm.gtin" label="GTIN" :error-messages="packForm.errors.gtin" /></VCol>
            <VCol cols="3"><VTextField v-model.number="packForm.length_cm" type="number" min="0" :label="t('warehouse_management.locations.length_cm')" /></VCol>
            <VCol cols="3"><VTextField v-model.number="packForm.width_cm" type="number" min="0" :label="t('warehouse_management.locations.width_cm')" /></VCol>
            <VCol cols="3"><VTextField v-model.number="packForm.height_cm" type="number" min="0" :label="t('warehouse_management.locations.height_cm')" /></VCol>
            <VCol cols="3"><VTextField v-model.number="packForm.gross_weight_kg" type="number" min="0" :label="t('warehouse_management.products.weight_kg')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="packDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="packForm.processing" @click="savePack">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Barcode -->
    <VDialog v-model="barcodeDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.products.add_barcode') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="barcodeForm.barcode" autofocus :label="t('warehouse_management.products.barcode')" :hint="t('warehouse_management.products.barcode_scan_hint')" persistent-hint :error-messages="barcodeForm.errors.barcode" />
          <VRow dense class="mt-2">
            <VCol cols="6"><VSelect v-model="barcodeForm.symbology" :items="symbologies" clearable :label="t('warehouse_management.products.symbology')" :hint="t('warehouse_management.products.symbology_hint')" persistent-hint /></VCol>
            <VCol cols="6"><VSelect v-model="barcodeForm.source" :items="['SUPPLIER', 'CUSTOMER', 'MANUFACTURER', 'INTERNAL'].map(s => ({ value: s, title: t(`warehouse_management.barcode_source.${s}`) }))" :label="t('warehouse_management.products.source')" /></VCol>
            <VCol cols="6"><VSelect v-model="barcodeForm.packaging_id" :items="packaging.map(p => ({ value: p.uuid, title: `${t(`warehouse_management.packaging_level.${p.level}`)} (${p.qty_in_base})` }))" clearable :label="t('warehouse_management.products.level')" /></VCol>
            <VCol cols="6"><VAutocomplete v-model="barcodeForm.supplier_id" :items="suppliers" item-title="name" item-value="id" clearable :label="t('warehouse_management.products.supplier')" /></VCol>
            <VCol cols="12"><VSwitch v-model="barcodeForm.is_primary" color="primary" density="compact" :label="t('warehouse_management.products.primary')" /></VCol>
          </VRow>
          <VAlert v-if="(barcodeForm.errors as any).refused" type="error" variant="tonal" class="mb-2">{{ (barcodeForm.errors as any).refused }}</VAlert>
          <VTextarea v-if="needsReason && can.approve_duplicate" v-model="barcodeForm.duplicate_reason" rows="2" auto-grow
                     :label="t('warehouse_management.products.duplicate_reason')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="barcodeDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="barcodeForm.processing" @click="saveBarcode">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
