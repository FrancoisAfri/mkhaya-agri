<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Rec = { item_id: string; code: string; name: string; uom: string; suggested_qty: number; supplier: string | null; unit_cost: number; days_of_cover: number | null;
  inputs: Array<{ label: string; value: number | null; source: string }>; assumptions: string[] }
const props = defineProps<{ recommendations: Rec[]; service: number; terms: Array<{ id: number; supplier: string; item: string; min_order_qty: number | null; order_multiple: number | null; lead_time_days: number | null; unit_price: number | null }>;
  suppliers: Array<{ id: number; title: string }>; can: { plan: boolean } }>()

const chosen = reactive<Record<string, number>>({})
const open = ref<string | null>(null)
function choose(r: Rec, on: boolean) { if (on) chosen[r.item_id] = r.suggested_qty; else delete chosen[r.item_id] }
const comparing = ref<Rec | null>(null)
const comparison = ref<Array<Record<string, any>>>([])
async function compare(r: Rec) { comparing.value = r; comparison.value = (await axios.get(route('warehouse.planning.buying.compare', r.item_id))).data }
function raise() { router.post(route('warehouse.planning.buying.request'), { lines: chosen }, { preserveScroll: true, onSuccess: () => Object.keys(chosen).forEach(k => delete chosen[k]) }) }
const termsDialog = ref(false)
const termsForm = useForm({ supplier_id: null as number | null, item: null as string | null, min_order_qty: null as number | null, order_multiple: null as number | null, lead_time_days: null as number | null, unit_price: null as number | null })
const items = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
const money = (v: number | null) => v === null ? '—' : v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })
const pct = (v: number | null) => v === null || v === undefined ? '—' : `${Math.round(v * 1000) / 10}%`
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.buying.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.buying.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center">
        <VSelect :model-value="service" :items="[90, 95, 97, 98, 99].map(s => ({ value: s, title: t('warehouse_management.buying.service', { s }) }))" density="compact" hide-details style="width: 190px"
                 @update:model-value="v => router.get(route('warehouse.planning.buying'), { service: v })" />
        <VBtn v-if="can.plan" variant="tonal" @click="termsDialog = true">{{ t('warehouse_management.buying.terms') }}</VBtn>
        <VBtn v-if="can.plan" color="primary" :disabled="!Object.keys(chosen).length" @click="raise">{{ t('warehouse_management.buying.raise', { n: Object.keys(chosen).length }) }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('warehouse_management.buying.approvals') }}</VAlert>
    <VCard border>
      <VCardText v-if="!recommendations.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.buying.none') }}</VCardText>
      <VList v-else lines="two">
        <template v-for="r in recommendations" :key="r.item_id">
          <VListItem>
            <template v-if="can.plan" #prepend><VCheckbox :model-value="chosen[r.item_id] !== undefined" hide-details density="compact"
              @update:model-value="v => choose(r, !!v)" /></template>
            <VListItemTitle>{{ r.code }} · {{ r.name }} — <strong>{{ r.suggested_qty }} {{ r.uom }}</strong><span v-if="r.supplier" class="text-caption"> · {{ r.supplier }}</span></VListItemTitle>
            <VListItemSubtitle>{{ r.days_of_cover === null ? t('warehouse_management.buying.no_demand') : t('warehouse_management.buying.cover', { d: r.days_of_cover }) }} · {{ money(r.suggested_qty * r.unit_cost) }}
              <span v-for="a in r.assumptions" :key="a" class="text-warning"> · {{ a }}</span></VListItemSubtitle>
            <template #append>
              <VTextField v-if="chosen[r.item_id] !== undefined" v-model.number="chosen[r.item_id]" type="number" density="compact" hide-details style="width: 110px" class="me-2" />
              <VBtn size="small" variant="text" @click="open = open === r.item_id ? null : r.item_id">{{ t('warehouse_management.buying.why') }}</VBtn>
              <VBtn size="small" variant="text" @click="compare(r)">{{ t('warehouse_management.buying.compare') }}</VBtn>
            </template>
          </VListItem>
          <VListItem v-if="open === r.item_id" class="bg-surface-variant">
            <VTable density="compact"><tbody><tr v-for="i in r.inputs" :key="i.label"><td>{{ i.label }}</td><td class="text-end">{{ i.value ?? '—' }}</td><td class="text-caption">{{ i.source }}</td></tr></tbody></VTable>
          </VListItem>
        </template>
      </VList>
    </VCard>

    <VDialog :model-value="comparing !== null" max-width="900" @update:model-value="v => !v && (comparing = null)">
      <VCard v-if="comparing" :title="t('warehouse_management.buying.compare_title', { item: comparing.code })">
        <VCardText>
          <p class="text-caption">{{ t('warehouse_management.buying.compare_hint', { uom: comparing.uom }) }}</p>
          <VCardText v-if="!comparison.length" class="text-medium-emphasis">{{ t('warehouse_management.buying.no_suppliers') }}</VCardText>
          <div v-else class="overflow-x-auto">
            <VTable density="compact">
              <thead><tr><th>{{ t('warehouse_management.batches.supplier') }}</th><th class="text-end">{{ t('warehouse_management.buying.price') }}</th><th class="text-end">{{ t('warehouse_management.buying.landed') }}</th>
                <th class="text-end">{{ t('warehouse_management.buying.cost_here') }}</th><th class="text-end">{{ t('warehouse_management.buying.on_time') }}</th>
                <th class="text-end">{{ t('warehouse_management.buying.rejected') }}</th><th class="text-end">{{ t('warehouse_management.buying.lead') }}</th><th class="text-end">MOQ</th></tr></thead>
              <tbody>
                <tr v-for="(s, i) in comparison" :key="s.supplier_id" :class="i === 0 ? 'font-weight-bold' : ''">
                  <td>{{ s.supplier }}<div v-for="nt in s.notes" :key="nt" class="text-caption text-medium-emphasis font-weight-regular">{{ nt }}</div></td>
                  <td class="text-end">{{ money(s.price_per_unit) }}</td><td class="text-end">{{ money(s.landed_per_unit) }}</td><td class="text-end">{{ money(s.cost_here) }}</td>
                  <td class="text-end">{{ pct(s.on_time_rate) }}</td><td class="text-end">{{ pct(s.rejection_rate) }}</td><td class="text-end">{{ s.lead_days ?? '—' }}</td><td class="text-end">{{ s.min_order_qty ?? '—' }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCardText>
      </VCard>
    </VDialog>
    <VDialog v-model="termsDialog" max-width="560">
      <VCard :title="t('warehouse_management.buying.terms')">
        <VCardText class="d-flex flex-column gap-3">
          <VTable v-if="terms.length" density="compact"><tbody><tr v-for="tm in terms" :key="tm.id"><td>{{ tm.supplier }}</td><td>{{ tm.item }}</td><td class="text-caption">MOQ {{ tm.min_order_qty ?? '—' }} · ×{{ tm.order_multiple ?? '—' }} · {{ tm.lead_time_days ?? '—' }}d · {{ tm.unit_price ?? '—' }}</td></tr></tbody></VTable>
          <VAutocomplete v-model="termsForm.supplier_id" :items="suppliers" item-value="id" :label="t('warehouse_management.batches.supplier')" />
          <VAutocomplete v-model="termsForm.item" :items="items" item-value="id" :label="t('warehouse_management.counts.item')" @update:search="searchItems" />
          <div class="d-flex gap-2">
            <VTextField v-model.number="termsForm.min_order_qty" type="number" label="MOQ" />
            <VTextField v-model.number="termsForm.order_multiple" type="number" :label="t('warehouse_management.buying.multiple')" />
            <VTextField v-model.number="termsForm.lead_time_days" type="number" :label="t('warehouse_management.buying.lead')" />
            <VTextField v-model.number="termsForm.unit_price" type="number" :label="t('warehouse_management.buying.price')" />
          </div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="termsDialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="termsForm.post(route('warehouse.planning.buying.terms'), { preserveScroll: true, onSuccess: () => termsForm.reset() })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
