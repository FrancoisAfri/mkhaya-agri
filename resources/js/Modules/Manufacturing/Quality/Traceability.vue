<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Node = { id: number; uuid: string; lot: string; item_code: string | null; item_name: string | null; origin: string; supplier_lot: string | null
  manufactured_on: string | null; expires_on: string | null; depth: number; in_stock: number; dispatched: number }
type Edge = { from: number; to: number; kind: string; qty: number; order?: string; order_id?: string }
type Trace = { root: number; nodes: Record<string, Node>; edges: Edge[]; untracked: Array<{ order: string; item: string | null; qty: number; in_progress?: boolean }> }
type LotOption = { id: string; lot: string; item: string; origin: string; expires_on: string | null }

const props = defineProps<{
  filters: { search?: string; lot?: string }
  matches: LotOption[]
  lot: LotOption | null
  detail: null | {
    lot: Node; supplier: string | null; purchase_order: string | null
    receipts: Array<{ id: string; type: string; qty: number; date: string | null; reference: string | null; warehouse: string | null }>
    production: Array<{ order_id: string; number: string; bom_revision: number | null; routing_revision: number | null; work_centres: string[]; machines: string[]; operators: string[] }>
    inspections: Array<{ id: string; number: string; stage: string; result: string; disposition: string | null; inspected_at: string | null }>
    positions: Array<{ warehouse: string | null; bin: string | null; status: string; qty: number }>
    movements: Array<{ id: string; type: string; qty: number; date: string | null; reference: string | null; warehouse: string | null; status: string | null; to_status: string | null; source_type: string | null }>
    dispatched: number
  }
  backward: Trace | null
  forward: Trace | null
  severities: string[]
  can: { recall: boolean }
}>()

const search = ref(props.filters.search ?? '')
const find = () => router.get(route('manufacturing.quality.traceability'), { search: search.value || undefined }, { preserveState: true })
const open = (id: string) => router.get(route('manufacturing.quality.traceability'), { lot: id })

/** The trace as an indented list, walked from the root along its edges. */
function rows(trace: Trace | null, direction: 'up' | 'down') {
  if (!trace) return []
  const out: Array<{ node: Node; level: number; via: Edge | null }> = []
  const seen = new Set<number>()
  const walk = (id: number, level: number, via: Edge | null) => {
    const node = trace.nodes[String(id)]
    if (!node) return
    out.push({ node, level, via })
    if (seen.has(id)) return
    seen.add(id)
    const next = trace.edges.filter(e => direction === 'up' ? e.to === id : e.from === id)
    for (const e of next) walk(direction === 'up' ? e.from : e.to, level + 1, e)
  }
  walk(trace.root, 0, null)
  return out
}
const up = computed(() => rows(props.backward, 'up'))
const down = computed(() => rows(props.forward, 'down'))
const kindColor = (k: string) => ({ PRODUCTION: 'primary', SPLIT: 'teal', MERGE: 'purple' } as Record<string, string>)[k] ?? 'default'

const recallDialog = ref(false)
const recall = useForm({ lot: props.lot?.id ?? '', title: '', reason: '', severity: 'CLASS_II' })
function openRecall() {
  recall.post(route('manufacturing.quality.recalls.store'), { onSuccess: () => { recallDialog.value = false } })
}
const affected = computed(() => props.forward ? Object.keys(props.forward.nodes).length : 0)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.trace.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.trace.subtitle') }}</p>
      </div>
      <VBtn variant="tonal" :href="route('manufacturing.quality.recalls.index')" prepend-icon="tabler-alert-octagon">{{ t('manufacturing.trace.recalls') }}</VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 align-center flex-wrap">
        <VTextField v-model="search" hide-details clearable style="max-width: 360px" prepend-inner-icon="tabler-search"
                    :label="t('manufacturing.trace.search')" @keyup.enter="find" />
        <VBtn color="primary" @click="find">{{ t('manufacturing.trace.find') }}</VBtn>
      </VCardText>
      <VList v-if="matches.length" density="compact">
        <VListItem v-for="m in matches" :key="m.id" :title="m.lot" :subtitle="`${m.item} · ${t(`manufacturing.trace.origin.${m.origin}`)}`" @click="open(m.id)" />
      </VList>
      <VCardText v-else-if="filters.search && !lot" class="text-medium-emphasis">{{ t('manufacturing.trace.no_match') }}</VCardText>
    </VCard>

    <template v-if="lot && detail">
      <div class="d-flex align-center flex-wrap gap-2 mb-4">
        <h5 class="text-h5 me-auto">{{ lot.lot }} · {{ lot.item }}</h5>
        <VBtn v-if="can.recall" color="error" prepend-icon="tabler-alert-octagon" @click="recallDialog = true">{{ t('manufacturing.trace.open_recall') }}</VBtn>
      </div>

      <VRow>
        <VCol cols="12" lg="6">
          <VCard border class="mb-4">
            <VCardItem><VCardTitle>{{ t('manufacturing.trace.backward') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.trace.backward_hint') }}</VCardSubtitle></VCardItem>
            <VList density="compact">
              <VListItem v-for="(r, i) in up" :key="i" :style="{ paddingInlineStart: `${16 + r.level * 24}px` }">
                <VListItemTitle>
                  <a v-if="r.level" href="#" @click.prevent="open(r.node.uuid)">{{ r.node.lot }}</a>
                  <strong v-else>{{ r.node.lot }}</strong>
                  · {{ r.node.item_code }}
                </VListItemTitle>
                <VListItemSubtitle>
                  <template v-if="r.via">
                    <VChip size="x-small" label :color="kindColor(r.via.kind)" class="me-1">{{ t(`manufacturing.trace.kind.${r.via.kind}`) }}</VChip>
                    {{ r.via.qty }}<template v-if="r.via.order"> · <a :href="route('manufacturing.production.orders.show', r.via.order_id)">{{ r.via.order }}</a></template>
                  </template>
                  <template v-else>{{ t(`manufacturing.trace.origin.${r.node.origin}`) }}</template>
                </VListItemSubtitle>
              </VListItem>
            </VList>
            <VAlert v-for="(u, i) in backward?.untracked ?? []" :key="i" type="warning" variant="tonal" density="compact" class="ma-3">
              {{ t('manufacturing.trace.untracked', { item: u.item, order: u.order, qty: u.qty }) }}
            </VAlert>
          </VCard>
        </VCol>

        <VCol cols="12" lg="6">
          <VCard border class="mb-4">
            <VCardItem><VCardTitle>{{ t('manufacturing.trace.forward') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.trace.forward_hint', { n: affected }) }}</VCardSubtitle></VCardItem>
            <VList density="compact">
              <VListItem v-for="(r, i) in down" :key="i" :style="{ paddingInlineStart: `${16 + r.level * 24}px` }">
                <VListItemTitle>
                  <a v-if="r.level" href="#" @click.prevent="open(r.node.uuid)">{{ r.node.lot }}</a>
                  <strong v-else>{{ r.node.lot }}</strong>
                  · {{ r.node.item_code }}
                </VListItemTitle>
                <VListItemSubtitle>
                  <template v-if="r.via">
                    <VChip size="x-small" label :color="kindColor(r.via.kind)" class="me-1">{{ t(`manufacturing.trace.kind.${r.via.kind}`) }}</VChip>
                    <template v-if="r.via.order"><a :href="route('manufacturing.production.orders.show', r.via.order_id)">{{ r.via.order }}</a> · </template>
                  </template>
                  {{ t('manufacturing.trace.stock_line', { stock: r.node.in_stock, out: r.node.dispatched }) }}
                </VListItemSubtitle>
              </VListItem>
            </VList>
            <VAlert v-for="(u, i) in forward?.untracked ?? []" :key="i" type="info" variant="tonal" density="compact" class="ma-3">
              {{ t('manufacturing.trace.in_progress', { order: u.order, qty: u.qty }) }}
            </VAlert>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard border class="mb-4">
            <VCardItem><VCardTitle>{{ t('manufacturing.trace.this_lot') }}</VCardTitle></VCardItem>
            <VList density="compact">
              <VListItem :title="t('manufacturing.trace.origin_label')" :subtitle="t(`manufacturing.trace.origin.${detail.lot.origin}`)" />
              <VListItem v-if="detail.supplier" :title="t('manufacturing.quality.supplier')" :subtitle="`${detail.supplier}${detail.lot.supplier_lot ? ` · ${detail.lot.supplier_lot}` : ''}`" />
              <VListItem v-if="detail.purchase_order" :title="t('manufacturing.quality.purchase_order')" :subtitle="detail.purchase_order" />
              <VListItem v-if="detail.lot.manufactured_on" :title="t('manufacturing.trace.made_on')" :subtitle="detail.lot.manufactured_on" />
              <VListItem v-if="detail.lot.expires_on" :title="t('manufacturing.quality.expires_on')" :subtitle="detail.lot.expires_on" />
            </VList>
            <template v-for="p in detail.production" :key="p.order_id">
              <VDivider />
              <VCardText class="text-body-2">
                <div class="font-weight-medium"><a :href="route('manufacturing.production.orders.show', p.order_id)">{{ p.number }}</a>
                  · {{ t('manufacturing.trace.revisions', { bom: p.bom_revision ?? '—', routing: p.routing_revision ?? '—' }) }}</div>
                <div>{{ t('manufacturing.trace.work_centres') }}: {{ p.work_centres.join(', ') || '—' }}</div>
                <div>{{ t('manufacturing.trace.machines') }}: {{ p.machines.join(', ') || '—' }}</div>
                <div>{{ t('manufacturing.trace.operators') }}: {{ p.operators.join(', ') || '—' }}</div>
              </VCardText>
            </template>
          </VCard>

          <VCard border class="mb-4">
            <VCardItem><VCardTitle>{{ t('manufacturing.trace.inspections') }}</VCardTitle></VCardItem>
            <VList density="compact">
              <VListItem v-for="i in detail.inspections" :key="i.id" :href="route('manufacturing.quality.inspections.show', i.id)"
                         :title="i.number" :subtitle="`${t(`manufacturing.quality.stage.${i.stage}`)} · ${t(`manufacturing.quality.result_${i.result}`)}${i.disposition ? ` · ${t(`manufacturing.quality.disposition.${i.disposition}`)}` : ''}`" />
              <VListItem v-if="!detail.inspections.length" :subtitle="t('manufacturing.trace.no_inspections')" />
            </VList>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard border class="mb-4">
            <VCardItem><VCardTitle>{{ t('manufacturing.trace.where_now') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.trace.dispatched_n', { n: detail.dispatched }) }}</VCardSubtitle></VCardItem>
            <VTable density="compact">
              <thead><tr><th>{{ t('manufacturing.quality.warehouse') }}</th><th>{{ t('manufacturing.trace.bin') }}</th><th>{{ t('manufacturing.bom.status') }}</th><th class="text-end">{{ t('manufacturing.orders.quantity') }}</th></tr></thead>
              <tbody>
                <tr v-for="(p, i) in detail.positions" :key="i"><td>{{ p.warehouse }}</td><td>{{ p.bin ?? '—' }}</td><td>{{ t(`manufacturing.quality.stock_status.${p.status}`) }}</td><td class="text-end">{{ p.qty }}</td></tr>
                <tr v-if="!detail.positions.length"><td colspan="4" class="text-medium-emphasis">{{ t('manufacturing.trace.none_in_stock') }}</td></tr>
              </tbody>
            </VTable>
          </VCard>

          <VCard border>
            <VCardItem><VCardTitle>{{ t('manufacturing.trace.movements') }}</VCardTitle></VCardItem>
            <VTable density="compact">
              <thead><tr><th>{{ t('manufacturing.trace.date') }}</th><th>{{ t('manufacturing.trace.type') }}</th><th class="text-end">{{ t('manufacturing.orders.quantity') }}</th><th>{{ t('manufacturing.quality.reference') }}</th></tr></thead>
              <tbody>
                <tr v-for="m in detail.movements" :key="m.id">
                  <td class="text-no-wrap">{{ m.date }}</td>
                  <td>{{ t(`manufacturing.trace.movement.${m.type}`) }}<span v-if="m.to_status" class="text-caption"> → {{ t(`manufacturing.quality.stock_status.${m.to_status}`) }}</span></td>
                  <td class="text-end">{{ m.qty }}</td>
                  <td>{{ m.reference ?? '—' }}<span v-if="m.warehouse" class="text-caption text-medium-emphasis"> · {{ m.warehouse }}</span></td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <VDialog v-model="recallDialog" max-width="560">
      <VCard :title="t('manufacturing.trace.open_recall')" :subtitle="t('manufacturing.trace.open_recall_hint', { n: affected })">
        <VCardText>
          <VTextField v-model="recall.title" :error-messages="recall.errors.title" :label="t('manufacturing.trace.recall_title')" class="mb-2" />
          <VTextarea v-model="recall.reason" rows="3" auto-grow :error-messages="recall.errors.reason" :label="t('manufacturing.orders.reason')" class="mb-2" />
          <VSelect v-model="recall.severity" :items="severities.map(s => ({ title: t(`manufacturing.trace.severity.${s}`), value: s }))" :label="t('manufacturing.trace.class')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="recallDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="error" :loading="recall.processing" :disabled="!recall.title || !recall.reason" @click="openRecall">{{ t('manufacturing.trace.open_recall') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
