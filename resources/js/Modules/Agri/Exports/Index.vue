<script setup lang="ts">
/**
 * Export document packs: per load, the consignment (consignee, transport, cold chain), its lines
 * per lot, and the documents generated into Document Management; and the farm's exporter
 * registration codes (PUC, PHC, FBO…).
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import { human, num } from '../Components/agri'

const props = defineProps<{
  filters: { farm: string | null }
  farms: Array<{ id: string; name: string }>
  loads: Array<any>
  codes: Array<{ id: string; kind: string; code: string; store: string | null; valid_until: string | null; notes: string | null }>
  options: { incoterms: string[]; modes: string[]; kinds: string[]; code_kinds: string[]; stores: Array<{ value: number; title: string }>; home: string }
  can: { manage: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.exports.${key}`, params ?? {})
const f = (key: string) => t(`agri.exports.fields.${key}`)
const keep = { preserveScroll: true }

const consignmentFields: FormField[] = [
  ...['exporter_name', 'exporter_code', 'consignee_name', 'destination_country'].map(key => ({ key, label: f(key), hint: key === 'destination_country' ? k('country_hint') : undefined })),
  { key: 'exporter_address', label: f('exporter_address'), type: 'textarea', cols: 12 },
  { key: 'consignee_address', label: f('consignee_address'), type: 'textarea', cols: 12 },
  { key: 'notify_party', label: f('notify_party'), type: 'textarea', cols: 12 },
  { key: 'incoterm', label: f('incoterm'), type: 'select', items: props.options.incoterms.map(i => ({ title: i, value: i })), cols: 3 },
  { key: 'incoterm_place', label: f('incoterm_place'), cols: 5 },
  { key: 'currency', label: f('currency'), cols: 4 },
  { key: 'transport_mode', label: f('transport_mode'), type: 'select', items: props.options.modes.map(m => ({ title: k(`modes.${m}`), value: m })), cols: 4 },
  { key: 'carrier', label: f('carrier'), cols: 4 }, { key: 'booking_ref', label: f('booking_ref'), cols: 4 },
  { key: 'container_no', label: f('container_no'), cols: 4, show: (x: any) => x.transport_mode === 'sea' }, { key: 'seal_no', label: f('seal_no'), cols: 4 },
  { key: 'vessel', label: f('vessel'), cols: 4, show: (x: any) => x.transport_mode === 'sea' }, { key: 'voyage', label: f('voyage'), cols: 4, show: (x: any) => x.transport_mode === 'sea' },
  { key: 'awb_no', label: f('awb_no'), cols: 4, show: (x: any) => x.transport_mode === 'air' }, { key: 'flight_no', label: f('flight_no'), cols: 4, show: (x: any) => x.transport_mode === 'air' },
  { key: 'port_of_loading', label: f('port_of_loading'), cols: 4 }, { key: 'port_of_discharge', label: f('port_of_discharge'), cols: 4 }, { key: 'final_destination', label: f('final_destination'), cols: 4 },
  { key: 'departure_on', label: f('departure_on'), type: 'date', cols: 4 }, { key: 'arrival_on', label: f('arrival_on'), type: 'date', cols: 4 },
  { key: 'temperature_setpoint_c', label: f('temperature_setpoint_c'), type: 'number', cols: 4 }, { key: 'temperature_min_c', label: f('temperature_min_c'), type: 'number', cols: 4 },
  { key: 'temperature_max_c', label: f('temperature_max_c'), type: 'number', cols: 4 }, { key: 'ventilation', label: f('ventilation'), cols: 4 },
  { key: 'cold_treatment', label: f('cold_treatment'), type: 'switch', cols: 4 }, { key: 'cold_treatment_protocol', label: f('cold_treatment_protocol'), cols: 8 },
  { key: 'inspection_point', label: f('inspection_point'), cols: 8 }, { key: 'inspection_on', label: f('inspection_on'), type: 'date', cols: 4 },
  { key: 'import_permit_no', label: f('import_permit_no'), cols: 6 },
  { key: 'additional_declarations', label: f('additional_declarations'), type: 'textarea', cols: 12 },
  { key: 'required_documents', label: f('required_documents'), type: 'multiselect', items: props.options.kinds.map(x => ({ title: k(`kinds.${x}`), value: x })), cols: 12 },
  { key: 'notes', label: f('notes'), type: 'textarea', cols: 12 },
]
const codeFields: FormField[] = [
  { key: 'kind', label: f('code_kind'), type: 'select', items: props.options.code_kinds.map(x => ({ title: x, value: x })), required: true, default: 'PUC' },
  { key: 'code', label: f('code'), required: true },
  { key: 'store_id', label: f('code_store'), type: 'select', items: props.options.stores, hint: k('code_store_hint') },
  { key: 'valid_until', label: f('valid_until'), type: 'date' },
  { key: 'notes', label: f('notes'), cols: 12 },
]

const editing = ref<any>(null)
const codeOpen = ref(false)
const linesFor = ref<null | { id: string; lines: any[] }>(null)
const editLines = (c: any) => { linesFor.value = { id: c.uuid, lines: c.lines.map((l: any) => ({ ...l })) } }
const saveLines = () => linesFor.value && router.post(route('agri.exports.lines', linesFor.value.id), { lines: linesFor.value.lines }, { ...keep, onSuccess: () => { linesFor.value = null } })
const generate = (c: any, kind?: string) => router.post(route('agri.exports.generate', c.uuid), { kind }, keep)
const removeCode = (c: any) => confirm(k('confirm_remove')) && router.delete(route('agri.exports.codes.destroy', c.id), keep)
const LINE_KEYS = ['description', 'hs_code', 'pallet_ids', 'pallets', 'cartons', 'net_kg', 'gross_kg', 'unit_price', 'variety', 'grade', 'puc']
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="filters.farm" :allow-all="false" />
    </div>
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ k('authorities_note') }}</VAlert>

    <VRow>
      <VCol cols="12" lg="9">
        <VCard v-for="d in loads" :key="d.id" class="mb-4">
          <VCardItem>
            <VCardTitle>{{ d.number }} · {{ d.buyer }}</VCardTitle>
            <VCardSubtitle>{{ human(d.status) }} · {{ k('destination', { country: d.country }) }}
              <VChip size="x-small" class="ms-1" :color="d.export ? 'indigo' : 'secondary'">{{ d.export ? k('export') : k('domestic') }}</VChip>
              <VChip v-if="d.residue.market" size="x-small" class="ms-1" :color="d.residue.blocks.length ? (d.residue.override ? 'warning' : 'error') : 'success'">
                {{ k('residue', { market: d.residue.market }) }}: {{ d.residue.blocks.length ? (d.residue.override ? k('overridden') : k('blocked')) : k('clear') }}</VChip></VCardSubtitle>
            <template #append><VBtn v-if="can.manage" size="small" variant="tonal" color="primary" @click="editing = d">{{ d.consignment ? k('edit_consignment') : k('prepare') }}</VBtn></template>
          </VCardItem>
          <VCardText v-if="d.refusal" class="text-error text-caption pt-0">{{ d.refusal }}</VCardText>
          <template v-if="d.consignment">
            <VCardText class="text-caption pt-0">
              {{ d.consignment.reference }} · {{ d.consignment.consignee_name }} · {{ d.consignment.incoterm ?? '—' }} {{ d.consignment.incoterm_place ?? '' }} ·
              {{ d.consignment.transport_mode ? k(`modes.${d.consignment.transport_mode}`) : '—' }} {{ d.consignment.vessel ?? d.consignment.awb_no ?? '' }} {{ d.consignment.voyage ?? '' }} ·
              {{ d.consignment.port_of_loading ?? '—' }} → {{ d.consignment.port_of_discharge ?? '—' }} · {{ d.consignment.container_no ?? '' }} {{ d.consignment.seal_no ?? '' }}
            </VCardText>
            <VTable density="compact">
              <thead><tr><th>{{ f('line_description') }}</th><th>{{ f('line_hs_code') }}</th><th class="text-end">{{ f('line_pallets') }}</th><th class="text-end">{{ f('line_cartons') }}</th>
                <th class="text-end">{{ f('line_net_kg') }}</th><th class="text-end">{{ f('line_gross_kg') }}</th><th class="text-end">{{ f('line_unit_price') }}</th>
                <th><VBtn v-if="can.manage" size="x-small" variant="text" @click="editLines(d.consignment)">{{ k('edit_lines') }}</VBtn></th></tr></thead>
              <tbody><tr v-for="l in d.consignment.lines" :key="l.id"><td>{{ l.description }}<div class="text-caption">{{ l.pallet_ids }}</div></td><td>{{ l.hs_code ?? '—' }}</td>
                <td class="text-end">{{ l.pallets ?? '—' }}</td><td class="text-end">{{ num(l.cartons) }}</td><td class="text-end">{{ num(l.net_kg) }}</td><td class="text-end">{{ num(l.gross_kg) }}</td>
                <td class="text-end">{{ num(l.unit_price) }}</td><td /></tr></tbody>
            </VTable>
            <VCardText>
              <div class="d-flex align-center mb-2"><span class="text-subtitle-2">{{ k('pack') }}</span><VSpacer />
                <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-files" @click="generate(d.consignment)">{{ k('generate_pack') }}</VBtn></div>
              <VTable density="compact">
                <tbody>
                  <tr v-for="doc in d.consignment.pack" :key="doc.kind" :class="{ 'text-disabled': !doc.required }">
                    <td>{{ k(`kinds.${doc.kind}`) }}<div v-if="doc.application" class="text-caption">{{ k('application_data') }}</div></td>
                    <td><VChip v-if="doc.version" size="x-small" :color="doc.stale ? 'warning' : 'success'">{{ doc.stale ? k('out_of_date', { v: doc.version }) : k('version', { v: doc.version }) }}</VChip>
                      <VChip v-else size="x-small" :color="doc.required ? 'error' : 'secondary'">{{ doc.required ? k('missing_doc') : k('not_required') }}</VChip>
                      <div v-if="doc.history.length > 1" class="text-caption">{{ k('history', { list: doc.history.map((h: any) => `v${h.version} ${h.at}`).join(', ') }) }}</div></td>
                    <td class="text-caption"><template v-if="doc.missing.length">{{ k('needs') }}: {{ doc.missing.map((m: string) => f(m.replace('line.', 'line_').replace('code.', 'code_'))).join(', ') }}</template></td>
                    <td class="text-end text-no-wrap"><a v-if="doc.url" :href="doc.url" class="text-caption me-2">{{ k('open_dm') }}</a>
                      <VBtn v-if="can.manage" size="x-small" variant="text" :disabled="doc.missing.length > 0" @click="generate(d.consignment, doc.kind)">{{ doc.version ? k('regenerate') : k('generate') }}</VBtn></td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </template>
        </VCard>
        <VAlert v-if="!loads.length" type="info" variant="tonal">{{ k('no_loads') }}</VAlert>
      </VCol>
      <VCol cols="12" lg="3">
        <VCard :title="k('codes_title')" :subtitle="k('codes_subtitle')">
          <VList density="compact">
            <VListItem v-for="c in codes" :key="c.id" :title="`${c.kind} ${c.code}`" :subtitle="[c.store, c.valid_until ? k('valid_until', { on: c.valid_until }) : null].filter(Boolean).join(' · ')">
              <template #append><VBtn v-if="can.manage" icon="tabler-trash" size="x-small" variant="text" @click="removeCode(c)" /></template>
            </VListItem>
            <VListItem v-if="!codes.length" :title="k('no_codes')" />
          </VList>
          <VCardActions v-if="can.manage && filters.farm"><VBtn prepend-icon="tabler-plus" @click="codeOpen = true">{{ k('add_code') }}</VBtn></VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog v-if="editing" :model-value="true" :title="editing.consignment ? k('edit_consignment') : k('prepare')" :fields="consignmentFields" max-width="900"
      :values="editing.consignment ?? { required_documents: options.kinds }" :url="route('agri.exports.save', editing.id)" :submit-label="k('save')" :intro="k('consignment_intro')"
      @update:model-value="editing = null" />
    <FormDialog v-if="codeOpen && filters.farm" :model-value="true" :title="k('add_code')" :fields="codeFields" :url="route('agri.exports.codes.store', filters.farm)"
      :submit-label="k('save')" @update:model-value="codeOpen = false" />
    <VDialog v-if="linesFor" :model-value="true" max-width="1100" scrollable @update:model-value="linesFor = null">
      <VCard :title="k('edit_lines')">
        <VCardText>
          <VRow v-for="l in linesFor.lines" :key="l.id" dense class="mb-2">
            <VCol v-for="key in LINE_KEYS" :key="key" cols="6" :sm="key === 'description' || key === 'pallet_ids' ? 6 : 3">
              <VTextField v-model="l[key]" :label="f(`line_${key}`)" density="compact" hide-details :type="['pallets', 'cartons', 'net_kg', 'gross_kg', 'unit_price'].includes(key) ? 'number' : 'text'" />
            </VCol>
            <VCol cols="12"><VDivider /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="linesFor = null">{{ k('cancel') }}</VBtn><VBtn color="primary" @click="saveLines">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
