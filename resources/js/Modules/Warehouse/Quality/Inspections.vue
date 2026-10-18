<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Opt = { id: string; title: string }
type Check = { id: string; name: string; kind: string; uom: string | null; lower: number | null; upper: number | null; critical: boolean }
type Row = { id: string; number: string; stage: string; status: string; result: string; disposition: string | null; disposition_reason: string | null; qty: number; item: string | null; item_name: string | null; uom: string | null; warehouse: string | null; bin: string | null; lot: string | null; reference: string | null; notes: string | null; sample_size: number | null; plan: string | null; checks: Check[]; decisions: string[]; created_at: string }
const props = defineProps<{
  inspections: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { status: string }
  defect_codes: Opt[]
  warehouses: Opt[]
  can: { quality: boolean; manage: boolean }
}>()

// Recording: one row per check and sample, or a single overall check where there is no plan.
const recording = ref<Row | null>(null)
const results = ref<Array<{ characteristic: string | null; name: string; kind: string; sample_no: number; value: number | null; passed: boolean | null; defect_code: string | null; hint: string }>>([])
const notes = ref('')
function openRecord(r: Row) {
  recording.value = r
  notes.value = ''
  const samples = Math.max(1, r.sample_size ?? 1)
  results.value = r.checks.length
    ? r.checks.flatMap(c => Array.from({ length: samples }, (_, i) => ({ characteristic: c.id, name: c.name, kind: c.kind, sample_no: i + 1, value: null, passed: null, defect_code: null,
      hint: c.kind === 'MEASURED' ? [c.lower, c.upper].some(v => v !== null) ? `${c.lower ?? '…'} – ${c.upper ?? '…'} ${c.uom ?? ''}` : '' : '' })))
    : [{ characteristic: null, name: t('warehouse_management.quality.overall'), kind: 'ATTRIBUTE', sample_no: 1, value: null, passed: null, defect_code: null, hint: '' }]
}
function submitResults() {
  if (!recording.value) return
  router.post(route('warehouse.quality.record', recording.value.id), { results: results.value.map(r => ({ characteristic: r.characteristic, name: r.characteristic ? null : r.name,
    value: r.kind === 'MEASURED' ? r.value : null, passed: r.kind === 'ATTRIBUTE' ? r.passed : null, sample_no: r.sample_no, defect_code: r.defect_code })), notes: notes.value },
  { preserveScroll: true, onSuccess: () => { recording.value = null } })
}

const deciding = ref<Row | null>(null)
const decision = useForm({ decision: null as string | null, reason: '' })
function submitDecision() {
  if (!deciding.value) return
  decision.post(route('warehouse.quality.decide', deciding.value.id), { preserveScroll: true, onSuccess: () => { deciding.value = null; decision.reset() } })
}

const holding = ref(false)
const hold = useForm({ warehouse: props.warehouses[0]?.id ?? null as string | null, item: null as string | null, bin: null as string | null, qty: 1, reason: '' })
const items = ref<Opt[]>([])
const bins = ref<Opt[]>([])
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
async function loadBins() { if (hold.warehouse) bins.value = (await axios.get(route('warehouse.floor.replenishment.bins'), { params: { warehouse: hold.warehouse } })).data }

const resultColor = (r: string) => ({ PENDING: 'secondary', PASSED: 'success', FAILED: 'error' } as Record<string, string>)[r] ?? 'secondary'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.quality.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.quality.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtnToggle :model-value="filters.status" mandatory density="compact" variant="outlined" @update:model-value="v => router.get(route('warehouse.quality.index'), { status: v })">
          <VBtn value="OPEN">{{ t('warehouse_management.quality.open') }}</VBtn>
          <VBtn value="CLOSED">{{ t('warehouse_management.quality.closed') }}</VBtn>
        </VBtnToggle>
        <VBtn variant="tonal" :href="route('warehouse.quality.setup')">{{ t('warehouse_management.cards.quality_setup.title') }}</VBtn>
        <VBtn v-if="can.quality" color="primary" prepend-icon="tabler-hand-stop" @click="holding = true; loadBins()">{{ t('warehouse_management.quality.hold') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!inspections.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.quality.none') }}</VCardText>
      <VTable v-else density="comfortable">
        <thead><tr><th>{{ t('warehouse_management.quality.number') }}</th><th>{{ t('warehouse_management.quality.stage') }}</th><th>{{ t('warehouse_management.counts.item') }}</th>
          <th>{{ t('warehouse_management.quality.where') }}</th><th class="text-end">{{ t('warehouse_management.quality.qty') }}</th><th>{{ t('warehouse_management.quality.result') }}</th><th /></tr></thead>
        <tbody>
          <tr v-for="r in inspections.data" :key="r.id">
            <td class="font-weight-medium">{{ r.number }}<div v-if="r.reference" class="text-caption">{{ r.reference }}</div></td>
            <td>{{ t(`warehouse_management.inspection_stage.${r.stage}`) }}<div v-if="r.plan" class="text-caption">{{ r.plan }}</div></td>
            <td>{{ r.item }} <span class="text-medium-emphasis">{{ r.item_name }}</span><div v-if="r.lot" class="text-caption">{{ t('warehouse_management.counts.lot', { lot: r.lot }) }}</div></td>
            <td>{{ r.warehouse }}<span v-if="r.bin"> · {{ r.bin }}</span></td>
            <td class="text-end">{{ r.qty }} {{ r.uom }}<div v-if="r.sample_size" class="text-caption">{{ t('warehouse_management.quality.sample', { n: r.sample_size }) }}</div></td>
            <td><VChip size="small" :color="resultColor(r.result)" variant="tonal">{{ t(`warehouse_management.inspection_result.${r.result}`) }}</VChip>
              <div v-if="r.disposition" class="text-caption">{{ t(`warehouse_management.disposition.${r.disposition}`) }}<span v-if="r.disposition_reason">: {{ r.disposition_reason }}</span></div></td>
            <td class="text-end text-no-wrap">
              <VBtn v-if="can.quality && r.status === 'OPEN'" size="small" color="primary" variant="tonal" @click="openRecord(r)">{{ t('warehouse_management.quality.record') }}</VBtn>
              <VBtn v-if="can.quality && r.status === 'AWAITING_DISPOSITION'" size="small" color="warning" variant="tonal" @click="deciding = r; decision.decision = r.decisions[0] ?? null">{{ t('warehouse_management.quality.decide') }}</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <div class="d-flex gap-1 mt-3 flex-wrap">
      <VBtn v-for="l in inspections.links" :key="l.label" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
    </div>

    <VDialog :model-value="recording !== null" max-width="760" @update:model-value="v => !v && (recording = null)">
      <VCard v-if="recording" :title="t('warehouse_management.quality.record_title', { number: recording.number })">
        <VCardText>
          <div class="overflow-x-auto">
            <VTable density="compact">
              <thead><tr><th>{{ t('warehouse_management.quality.check') }}</th><th>#</th><th>{{ t('warehouse_management.quality.result') }}</th><th>{{ t('warehouse_management.quality.defect') }}</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in results" :key="i">
                  <td>{{ r.name }}<div v-if="r.hint" class="text-caption">{{ r.hint }}</div></td><td>{{ r.sample_no }}</td>
                  <td>
                    <VTextField v-if="r.kind === 'MEASURED'" v-model.number="r.value" type="number" density="compact" hide-details style="max-width: 120px" />
                    <VBtnToggle v-else v-model="r.passed" density="compact" variant="outlined">
                      <VBtn :value="true" color="success">{{ t('warehouse_management.quality.pass') }}</VBtn>
                      <VBtn :value="false" color="error">{{ t('warehouse_management.quality.fail') }}</VBtn>
                    </VBtnToggle>
                  </td>
                  <td><VSelect v-model="r.defect_code" :items="defect_codes" item-value="id" density="compact" hide-details clearable style="min-width: 160px" /></td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <VTextarea v-model="notes" rows="2" class="mt-3" :label="t('warehouse_management.counts.investigation')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="recording = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="submitResults">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="deciding !== null" max-width="480" @update:model-value="v => !v && (deciding = null)">
      <VCard v-if="deciding" :title="t('warehouse_management.quality.decide_title', { number: deciding.number })">
        <VCardText class="d-flex flex-column gap-3">
          <VRadioGroup v-model="decision.decision" hide-details>
            <VRadio v-for="d in deciding.decisions" :key="d" :value="d" :label="t(`warehouse_management.disposition.${d}`)" />
          </VRadioGroup>
          <p class="text-caption mb-0">{{ t(`warehouse_management.disposition_hint.${decision.decision ?? 'ACCEPT'}`) }}</p>
          <VTextarea v-model="decision.reason" rows="2" :label="t('warehouse_management.common.reason')" :error-messages="decision.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="deciding = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="decision.processing" @click="submitDecision">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="holding" max-width="480">
      <VCard :title="t('warehouse_management.quality.hold')">
        <VCardText class="d-flex flex-column gap-3">
          <p class="text-body-2 mb-0">{{ t('warehouse_management.quality.hold_hint') }}</p>
          <VSelect v-model="hold.warehouse" :items="warehouses" item-value="id" :label="t('warehouse_management.common.warehouse')" @update:model-value="loadBins" />
          <VAutocomplete v-model="hold.item" :items="items" item-value="id" :label="t('warehouse_management.counts.item')" @update:search="searchItems" />
          <VAutocomplete v-model="hold.bin" :items="bins" item-value="id" clearable :label="t('warehouse_management.counts.bin')" />
          <VTextField v-model.number="hold.qty" type="number" min="0" :label="t('warehouse_management.quality.qty')" />
          <VTextField v-model="hold.reason" :label="t('warehouse_management.common.reason')" :error-messages="hold.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="holding = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="hold.processing" @click="hold.post(route('warehouse.quality.hold'), { preserveScroll: true, onSuccess: () => { holding = false; hold.reset() } })">{{ t('warehouse_management.quality.hold') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
