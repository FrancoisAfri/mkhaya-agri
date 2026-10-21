<script setup lang="ts">
/**
 * Processing and packhouse: grading, packing and processing farm produce as Manufacturing production
 * runs. Lots go in, lots come out — each its own stock lot traced both ways — and a failed check
 * holds what was made until a passing re-inspection releases it.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import { human, money, num, statusColor } from './prd'

type Lot = { id: string; code: string; product: string; grade: string | null; kind: string; remaining: number; unit: string; status: string; farm: string | null; farm_id: string | null; produced_on: string | null; stocked: boolean }
const props = defineProps<{
  lots: Lot[]
  operations: Array<{ reference: string; kind: string; performed_on: string | null; input: number; output: number; loss: number; quality_passed: boolean; output_value: number | null; production_order_id: number | null }>
  batches: Array<{ value: string; title: string }>
  items: Array<{ value: number; title: string; uom: string }>
  stores: Array<{ value: number; title: string; farm_id: string | null }>
  kinds: string[]
  can: { process: boolean; release: boolean }
}>()

const held = computed(() => props.lots.filter(l => l.status === 'quarantined'))
const available = computed(() => props.lots.filter(l => l.status === 'available'))
const running = ref(false)
const form = useForm({ farm_id: '', kind: 'grading', batch_id: null as string | null, loss_reason: '',
  inputs: [{ lot_id: '', quantity: null as number | null }], outputs: [{ product: '', grade: '', quantity: null as number | null, unit: 'kg', stock_item_id: null as number | null, store_id: null as number | null }],
  quality_checks: [] as Array<{ check: string; value: string; ok: boolean; note?: string }> })
const start = (l?: Lot) => {
  form.reset()
  if (l) { form.farm_id = l.farm_id ?? ''; form.inputs = [{ lot_id: l.id, quantity: l.remaining }]; form.outputs[0].unit = l.unit }
  running.value = true
}
const lotsOnFarm = computed(() => available.value.filter(l => !form.farm_id || l.farm_id === form.farm_id).map(l => ({ value: l.id, title: `${l.code} — ${l.product}${l.grade ? ` ${l.grade}` : ''} (${num(l.remaining)} ${l.unit})` })))
const storesOnFarm = computed(() => props.stores.filter(s => !form.farm_id || s.farm_id === form.farm_id))
const farms = computed(() => [...new Map(available.value.filter(l => l.farm_id).map(l => [l.farm_id, { value: l.farm_id, title: l.farm }])).values()])
const run = () => form.post('/manufacturing/agriculture/processing', { preserveScroll: true, onSuccess: () => { running.value = false } })

const releasing = ref<Lot | null>(null)
const rel = useForm({ note: '', checks: [{ check: '', value: '', ok: true }] })
const release = () => rel.post(`/manufacturing/agriculture/processing/lots/${releasing.value!.id}/release`, { preserveScroll: true, onSuccess: () => { releasing.value = null } })
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">Processing &amp; packhouse</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Grading, packing and processing are production runs: lots in, lots out, traced both ways.</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-family-tree" href="/manufacturing/quality/traceability">Traceability</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-building-warehouse" href="/agri/packhouse/storage">Stores &amp; dispatch</VBtn>
        <VBtn v-if="can.process" color="primary" prepend-icon="tabler-player-play" @click="start()">Record a run</VBtn>
      </div>
    </div>

    <VAlert v-if="held.length" type="warning" variant="tonal" class="mb-4" title="Held by a failed check">
      <div v-for="l in held" :key="l.id" class="d-flex align-center gap-2 py-1">
        <span><strong>{{ l.code }}</strong> {{ l.product }} · {{ num(l.remaining) }} {{ l.unit }} · {{ l.farm }}</span>
        <VBtn v-if="can.release" size="x-small" variant="tonal" @click="releasing = l; rel.reset()">Re-inspect &amp; release</VBtn>
      </div>
    </VAlert>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border>
          <VCardTitle>Lots available</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>Lot</th><th>Product</th><th>Farm</th><th class="text-end">Left</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="l in lots" :key="l.id">
                <td class="font-weight-medium">{{ l.code }}<div class="text-caption text-medium-emphasis">{{ human(l.kind) }} · {{ l.produced_on }}</div></td>
                <td>{{ l.product }}<span v-if="l.grade" class="text-caption"> · {{ l.grade }}</span></td>
                <td>{{ l.farm }}</td>
                <td class="text-end">{{ num(l.remaining) }} {{ l.unit }}</td>
                <td><VChip size="x-small" label :color="l.status === 'quarantined' ? 'warning' : statusColor(l.status)">{{ l.status === 'quarantined' ? 'held' : human(l.status) }}</VChip></td>
                <td class="text-end"><VBtn v-if="can.process && l.status === 'available'" size="x-small" variant="tonal" @click="start(l)">Process</VBtn></td>
              </tr>
              <tr v-if="!lots.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No produce lots. Harvests into a store become lots here.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="5">
        <VCard border>
          <VCardTitle>Recent runs</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>Run</th><th class="text-end">In → out</th><th class="text-end">Value</th><th>QC</th></tr></thead>
            <tbody>
              <tr v-for="o in operations" :key="o.reference">
                <td class="font-weight-medium">{{ o.reference }}<div class="text-caption text-medium-emphasis">{{ human(o.kind) }} · {{ o.performed_on }}</div></td>
                <td class="text-end">{{ num(o.input) }} → {{ num(o.output) }}<div v-if="o.loss" class="text-caption">loss {{ num(o.loss) }}</div></td>
                <td class="text-end">{{ money(o.output_value) }}</td>
                <td><VChip size="x-small" label :color="o.quality_passed ? 'success' : 'warning'">{{ o.quality_passed ? 'passed' : 'held' }}</VChip></td>
              </tr>
              <tr v-if="!operations.length"><td colspan="4" class="text-center text-medium-emphasis py-4">None yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="running" max-width="900">
      <VCard title="Record a grading, packing or processing run">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4"><VSelect v-model="form.farm_id" :items="farms" label="Farm" :error-messages="form.errors.farm_id" /></VCol>
            <VCol cols="6" md="4"><VSelect v-model="form.kind" :items="kinds.map(k => ({ value: k, title: human(k) }))" label="Kind" /></VCol>
            <VCol cols="6" md="4"><VSelect v-model="form.batch_id" :items="batches" label="Processing batch (optional)" clearable /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2">In</div>
          <VRow v-for="(i, k) in form.inputs" :key="`i${k}`" dense>
            <VCol cols="8"><VSelect v-model="i.lot_id" :items="lotsOnFarm" label="Lot" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model.number="i.quantity" type="number" label="Quantity" density="compact" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" @click="form.inputs.push({ lot_id: '', quantity: null })">Add a lot</VBtn>
          <div class="text-subtitle-2 mt-2">Out</div>
          <VRow v-for="(o, k) in form.outputs" :key="`o${k}`" dense>
            <VCol cols="12" md="3"><VTextField v-model="o.product" label="Product" density="compact" /></VCol>
            <VCol cols="4" md="2"><VTextField v-model="o.grade" label="Grade" density="compact" /></VCol>
            <VCol cols="4" md="2"><VTextField v-model.number="o.quantity" type="number" label="Quantity" density="compact" /></VCol>
            <VCol cols="4" md="1"><VTextField v-model="o.unit" label="Unit" density="compact" /></VCol>
            <VCol cols="6" md="2"><VAutocomplete v-model="o.stock_item_id" :items="items" label="Stock item" density="compact" /></VCol>
            <VCol cols="6" md="2"><VSelect v-model="o.store_id" :items="storesOnFarm" label="Into store" density="compact" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" @click="form.outputs.push({ product: '', grade: '', quantity: null, unit: 'kg', stock_item_id: null, store_id: null })">Add an output</VBtn>
          <div class="text-subtitle-2 mt-2">Quality checks</div>
          <VRow v-for="(q, k) in form.quality_checks" :key="`q${k}`" dense>
            <VCol cols="5"><VTextField v-model="q.check" label="Check" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="q.value" label="Result" density="compact" /></VCol>
            <VCol cols="3"><VSwitch v-model="q.ok" :label="q.ok ? 'Pass' : 'Fail'" color="success" density="compact" hide-details /></VCol>
          </VRow>
          <VBtn size="small" variant="text" @click="form.quality_checks.push({ check: '', value: '', ok: true })">Add a check</VBtn>
          <VTextField v-model="form.loss_reason" label="Loss reason (if anything was lost)" class="mt-2" />
          <div v-for="(e, k) in form.errors" :key="k" class="text-error text-caption">{{ e }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="running = false">Cancel</VBtn><VBtn color="primary" :loading="form.processing" @click="run">Record</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!releasing" max-width="600" @update:model-value="v => !v && (releasing = null)">
      <VCard v-if="releasing" :title="`Release ${releasing.code}`">
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">Record the re-inspection; every check must pass. The person who recorded the batch cannot release it.</VAlert>
          <VRow v-for="(q, k) in rel.checks" :key="k" dense>
            <VCol cols="5"><VTextField v-model="q.check" label="Check" density="compact" /></VCol>
            <VCol cols="4"><VTextField v-model="q.value" label="Result" density="compact" /></VCol>
            <VCol cols="3"><VSwitch v-model="q.ok" :label="q.ok ? 'Pass' : 'Fail'" color="success" density="compact" hide-details /></VCol>
          </VRow>
          <VTextField v-model="rel.note" label="Why it is released" />
          <div v-for="(e, k) in rel.errors" :key="k" class="text-error text-caption">{{ e }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn @click="releasing = null">Cancel</VBtn><VBtn color="success" :loading="rel.processing" @click="release">Release</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
