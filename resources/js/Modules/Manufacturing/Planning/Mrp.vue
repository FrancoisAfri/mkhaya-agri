<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Week = { week: string; gross: number; receipts: number; expiring: number; net: number; planned: number; projected: number }
type PItem = { id: number; code: string | null; name: string | null; level: number; make_or_buy: string; on_hand: number; safety_stock: number
  gross: number; scheduled_receipts: number; net: number; planned: number; projected_end: number; shortage_on: string | null; available_on: string | null
  timeline: Week[]; demand: Array<{ type: string; ref: string | null; qty: number; date: string }>; exceptions: string[] }
type Rec = { id: string; kind: string; code: string | null; name: string | null; qty: number; net_qty: number; need_by: string | null; release_by: string | null
  supplier: string | null; unit_price: number | null; status: string; override_qty: number | null; override_date: string | null; decision_reason: string | null; converted_type: string | null }

const props = defineProps<{
  run: null | { id: string; number: string; starts_on: string | null; horizon_weeks: number; status: string; summary: Record<string, number> | null; exceptions: string[]; run_at: string | null }
  runs: Array<{ id: string; number: string; status: string; at: string | null }>
  items: PItem[]
  recommendations: Rec[]
  factories: Array<{ id: string; name: string }>
  can: { run: boolean; decide: boolean }
}>()

const horizon = ref(props.run?.horizon_weeks ?? 12)
const busy = ref(false)
const runNow = () => { busy.value = true; router.post(route('manufacturing.planning.mrp.run'), { horizon_weeks: horizon.value }, { onFinish: () => { busy.value = false } }) }

const selected = ref<string[]>([])
const proposed = computed(() => props.recommendations.filter(r => r.status === 'PROPOSED'))
const approveDialog = ref(false)
const factory = ref<string | null>(props.factories[0]?.id ?? null)
const needsFactory = computed(() => props.recommendations.some(r => selected.value.includes(r.id) && r.kind === 'PRODUCE'))
function approve() {
  busy.value = true
  router.post(route('manufacturing.planning.mrp.approve'), { recommendations: selected.value, factory: needsFactory.value ? factory.value : null }, {
    preserveScroll: true, onSuccess: () => { approveDialog.value = false; selected.value = [] }, onFinish: () => { busy.value = false },
  })
}

const deciding = ref<null | { rec: Rec; action: 'override' | 'reject' }>(null)
const decision = ref({ qty: null as number | null, date: '', reason: '' })
function openDecide(rec: Rec, action: 'override' | 'reject') { deciding.value = { rec, action }; decision.value = { qty: rec.qty, date: rec.need_by ?? '', reason: '' } }
function submitDecision() {
  const d = deciding.value!
  router.post(route('manufacturing.planning.mrp.decide', d.rec.id), { action: d.action, qty: decision.value.qty, date: decision.value.date || null, reason: decision.value.reason },
    { preserveScroll: true, onSuccess: () => { deciding.value = null } })
}

const expanded = ref<number[]>([])
const toggle = (id: number) => { expanded.value = expanded.value.includes(id) ? expanded.value.filter(x => x !== id) : [...expanded.value, id] }
const statusColor = (s: string) => ({ PROPOSED: 'info', CONVERTED: 'success', REJECTED: 'grey', APPROVED: 'success' } as Record<string, string>)[s] ?? 'default'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.plan.mrp_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.plan.mrp_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center flex-wrap">
        <VSelect v-if="runs.length" :model-value="run?.id" :items="runs.map(r => ({ title: `${r.number}${r.status === 'OPEN' ? '' : ' (' + t('manufacturing.plan.superseded') + ')'}`, value: r.id }))"
                 hide-details density="compact" style="min-width: 220px" @update:model-value="(v: string) => router.get(route('manufacturing.planning.mrp'), { run: v })" />
        <template v-if="can.run">
          <VTextField v-model.number="horizon" type="number" min="1" max="52" hide-details density="compact" style="max-width: 120px" :label="t('manufacturing.plan.weeks')" />
          <VBtn color="primary" prepend-icon="tabler-player-play" :loading="busy" @click="runNow">{{ t('manufacturing.plan.run') }}</VBtn>
        </template>
      </div>
    </div>

    <VCard v-if="!run" border><VCardText class="text-center py-10 text-medium-emphasis">{{ t('manufacturing.plan.no_run') }}</VCardText></VCard>

    <template v-else>
      <VAlert v-if="run.status !== 'OPEN'" type="info" variant="tonal" class="mb-3">{{ t('manufacturing.plan.old_run') }}</VAlert>
      <VAlert v-for="(e, i) in run.exceptions" :key="i" type="warning" variant="tonal" density="compact" class="mb-2">{{ e }}</VAlert>
      <VRow class="mb-1">
        <VCol v-for="k in ['items', 'purchase', 'produce', 'short_items', 'exceptions']" :key="k" cols="6" md>
          <VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t(`manufacturing.plan.summary.${k}`) }}</div><div class="text-h5">{{ run.summary?.[k] ?? 0 }}</div></VCardText></VCard>
        </VCol>
      </VRow>

      <VCard border class="mb-4">
        <VCardItem>
          <VCardTitle>{{ t('manufacturing.plan.recommendations') }}</VCardTitle>
          <VCardSubtitle>{{ t('manufacturing.plan.recommendations_hint') }}</VCardSubtitle>
          <template v-if="can.decide" #append>
            <VBtn color="success" :disabled="!selected.length" @click="approveDialog = true">{{ t('manufacturing.plan.approve_n', { n: selected.length }) }}</VBtn>
          </template>
        </VCardItem>
        <VTable density="compact">
          <thead><tr><th v-if="can.decide" style="width: 40px" /><th>{{ t('manufacturing.plan.action') }}</th><th>{{ t('manufacturing.orders.material') }}</th>
            <th class="text-end">{{ t('manufacturing.orders.quantity') }}</th><th>{{ t('manufacturing.plan.release_by') }}</th><th>{{ t('manufacturing.plan.need_by') }}</th>
            <th>{{ t('manufacturing.quality.supplier') }}</th><th>{{ t('manufacturing.bom.status') }}</th><th v-if="can.decide" /></tr></thead>
          <tbody>
            <tr v-for="r in recommendations" :key="r.id">
              <td v-if="can.decide"><VCheckbox v-if="r.status === 'PROPOSED'" v-model="selected" :value="r.id" hide-details density="compact" /></td>
              <td><VChip size="x-small" label :color="r.kind === 'PRODUCE' ? 'indigo' : 'teal'">{{ t(`manufacturing.plan.kind.${r.kind}`) }}</VChip></td>
              <td>{{ r.code }}<div class="text-caption text-medium-emphasis">{{ r.name }}</div></td>
              <td class="text-end">
                <span :class="{ 'text-decoration-line-through text-medium-emphasis': r.override_qty !== null }">{{ r.qty }}</span>
                <span v-if="r.override_qty !== null" class="ms-1 font-weight-medium">{{ r.override_qty }}</span>
                <div v-if="r.qty !== r.net_qty" class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.net_n', { n: r.net_qty }) }}</div>
              </td>
              <td>{{ r.release_by }}</td><td>{{ r.override_date ?? r.need_by }}</td>
              <td>{{ r.supplier ?? '—' }}<div v-if="r.unit_price !== null" class="text-caption">{{ r.unit_price.toFixed(2) }}</div></td>
              <td><VChip size="x-small" label :color="statusColor(r.status)">{{ t(`manufacturing.plan.rec_status.${r.status}`) }}</VChip>
                <div v-if="r.decision_reason" class="text-caption text-medium-emphasis">{{ r.decision_reason }}</div></td>
              <td v-if="can.decide" class="text-no-wrap">
                <template v-if="r.status === 'PROPOSED'">
                  <VBtn size="x-small" variant="text" @click="openDecide(r, 'override')">{{ t('manufacturing.plan.change') }}</VBtn>
                  <VBtn size="x-small" variant="text" color="error" @click="openDecide(r, 'reject')">{{ t('manufacturing.plan.reject') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!recommendations.length"><td colspan="9" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.plan.nothing_needed') }}</td></tr>
          </tbody>
        </VTable>
      </VCard>

      <VCard border>
        <VCardItem><VCardTitle>{{ t('manufacturing.plan.items') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.plan.items_hint') }}</VCardSubtitle></VCardItem>
        <VTable density="compact">
          <thead><tr><th>{{ t('manufacturing.orders.material') }}</th><th>{{ t('manufacturing.plan.level') }}</th><th class="text-end">{{ t('manufacturing.plan.on_hand') }}</th>
            <th class="text-end">{{ t('manufacturing.plan.gross') }}</th><th class="text-end">{{ t('manufacturing.plan.receipts') }}</th><th class="text-end">{{ t('manufacturing.plan.net') }}</th>
            <th class="text-end">{{ t('manufacturing.plan.planned') }}</th><th>{{ t('manufacturing.plan.short_on') }}</th></tr></thead>
          <tbody>
            <template v-for="i in items" :key="i.id">
              <tr class="cursor-pointer" @click="toggle(i.id)">
                <td><VIcon size="16" :icon="expanded.includes(i.id) ? 'tabler-chevron-down' : 'tabler-chevron-right'" /> {{ i.code }}
                  <VChip size="x-small" label class="ms-1">{{ t(`manufacturing.plan.mob.${i.make_or_buy}`) }}</VChip>
                  <VChip v-if="i.exceptions.length" size="x-small" label color="warning" class="ms-1">{{ i.exceptions.length }}</VChip>
                  <div class="text-caption text-medium-emphasis">{{ i.name }}</div></td>
                <td>{{ i.level }}</td><td class="text-end">{{ i.on_hand }}</td><td class="text-end">{{ i.gross }}</td><td class="text-end">{{ i.scheduled_receipts }}</td>
                <td class="text-end" :class="{ 'text-error': i.net > 0 }">{{ i.net }}</td><td class="text-end">{{ i.planned }}</td>
                <td :class="{ 'text-error': i.shortage_on }">{{ i.shortage_on ?? '—' }}</td>
              </tr>
              <tr v-if="expanded.includes(i.id)">
                <td colspan="8" class="pa-3">
                  <VAlert v-for="(e, k) in i.exceptions" :key="k" type="warning" variant="tonal" density="compact" class="mb-1">{{ e }}</VAlert>
                  <div class="overflow-x-auto">
                    <VTable density="compact">
                      <thead><tr><th /><th v-for="w in i.timeline" :key="w.week" class="text-end text-caption">{{ w.week.slice(5) }}</th></tr></thead>
                      <tbody>
                        <tr v-for="k in ['gross', 'receipts', 'expiring', 'planned', 'projected']" :key="k">
                          <td class="text-caption">{{ t(`manufacturing.plan.row.${k}`) }}</td>
                          <td v-for="w in i.timeline" :key="w.week" class="text-end text-caption" :class="{ 'text-error': k === 'projected' && (w as any)[k] < i.safety_stock }">{{ (w as any)[k] || '' }}</td>
                        </tr>
                      </tbody>
                    </VTable>
                  </div>
                  <div class="text-caption text-medium-emphasis mt-2">{{ t('manufacturing.plan.made_of') }}:
                    <span v-for="(d, k) in i.demand" :key="k">{{ t(`manufacturing.plan.demand_type.${d.type}`) }} {{ d.ref }} {{ d.qty }} ({{ d.date }}){{ k < i.demand.length - 1 ? '; ' : '' }}</span>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <VDialog v-model="approveDialog" max-width="480">
      <VCard :title="t('manufacturing.plan.approve_n', { n: selected.length })" :subtitle="t('manufacturing.plan.approve_hint')">
        <VCardText>
          <VSelect v-if="needsFactory" v-model="factory" :items="factories" item-value="id" item-title="name" :label="t('manufacturing.plan.make_at')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="approveDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="success" :loading="busy" @click="approve">{{ t('manufacturing.common.approve') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="deciding !== null" max-width="480" @update:model-value="v => { if (!v) deciding = null }">
      <VCard v-if="deciding" :title="deciding.action === 'reject' ? t('manufacturing.plan.reject') : t('manufacturing.plan.change')" :subtitle="`${deciding.rec.code} · ${deciding.rec.qty}`">
        <VCardText>
          <template v-if="deciding.action === 'override'">
            <VTextField v-model.number="decision.qty" type="number" min="0" step="any" :label="t('manufacturing.orders.quantity')" class="mb-2" />
            <VTextField v-model="decision.date" type="date" :label="t('manufacturing.plan.need_by')" class="mb-2" />
          </template>
          <VTextarea v-model="decision.reason" rows="2" auto-grow :label="t('manufacturing.orders.reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="deciding = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!decision.reason.trim()" @click="submitDecision">{{ t('manufacturing.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
