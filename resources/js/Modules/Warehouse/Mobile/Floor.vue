<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import axios from 'axios'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ScanInput from '../components/ScanInput.vue'
import { useOfflineQueue } from '@/composables/useOfflineQueue'
import { doneTargets, loadBundle, record, saveBundle, signal } from '../lib/floorOffline'

const { t } = useI18n()
const route = ziggyRoute
const props = defineProps<{ user_id: number; offline_kinds: string[]; can: { count: boolean; pick: boolean; putaway: boolean; review: boolean } }>()

const queue = useOfflineQueue()
const bundle = ref<any>(null)
const cachedAt = ref<string | null>(null)
const done = ref<Set<string>>(new Set())
const mode = ref<'COUNT' | 'PICK' | 'PUTAWAY'>(props.can.pick ? 'PICK' : props.can.count ? 'COUNT' : 'PUTAWAY')
const message = ref<{ type: 'success' | 'error' | 'info'; text: string } | null>(null)
const syncUrl = route('warehouse.mobile.sync')

async function refresh() {
  if (queue.isOnline.value) {
    try {
      await queue.syncQueue(props.user_id)
      const { data } = await axios.get(route('warehouse.mobile.bundle'))
      bundle.value = data
      cachedAt.value = data.issued_at
      await saveBundle(props.user_id, data)
    } catch { await fromCache() }
  } else {
    await fromCache()
  }
  done.value = await doneTargets()
}
async function fromCache() {
  const c = await loadBundle(props.user_id)
  bundle.value = c?.bundle ?? { counts: [], picks: [], putaways: [], problems: [] }
  cachedAt.value = c?.saved_at ?? null
}
onMounted(refresh)

function tell(type: 'success' | 'error' | 'info', text: string) {
  message.value = { type, text }
  signal(type === 'error' ? 'error' : 'ok')
}
async function act(kind: string, payload: Record<string, unknown>, target: string, summary: string) {
  await record(kind, payload, target, summary, props.user_id, syncUrl)
  done.value = await doneTargets()
  tell('success', queue.isOnline.value ? t('warehouse_management.floor.recorded') : t('warehouse_management.floor.recorded_offline'))
  if (queue.isOnline.value) queue.syncQueue(props.user_id)
}
const matches = (scan: string, code?: string | null, barcode?: string | null) => !!scan && [code, barcode].filter(Boolean).some(v => v!.toUpperCase() === scan.toUpperCase())

// ── Counting ────────────────────────────────────────────────────────────────
const countId = ref<string | null>(null)
const countBin = ref<string | null>(null)
const countQty = ref<Record<number, number | null>>({})
const count = computed(() => bundle.value?.counts?.find((c: any) => c.id === countId.value))
const countLines = computed(() => (count.value?.lines ?? []).filter((l: any) => l.bin === countBin.value && !done.value.has(`COUNT_ENTRY:${l.id}:${l.round}`)))
function scanCountBin(v: string) {
  const line = count.value?.lines.find((l: any) => matches(v, l.bin, l.bin_barcode))
  if (!line) return tell('error', t('warehouse_management.floor.not_in_count', { code: v }))
  countBin.value = line.bin
  tell('info', t('warehouse_management.floor.count_bin', { bin: line.bin }))
}
function enterCount(l: any) {
  const q = countQty.value[l.id]
  if (q === null || q === undefined || q < 0) return tell('error', t('warehouse_management.floor.enter_qty'))
  act('COUNT_ENTRY', { line_id: l.id, qty: q }, `${l.id}:${l.round}`, `${l.bin} ${l.item}: ${q}`)
}

// ── Picking ─────────────────────────────────────────────────────────────────
const picks = computed(() => (bundle.value?.picks ?? []).filter((p: any) => !done.value.has(`PICK_CONFIRM:${p.id}`)))
const pick = computed(() => picks.value[0] ?? null)
const pickBinOk = ref(false)
const pickItemScan = ref<string | null>(null)
const pickQty = ref<number | null>(null)
function scanPick(v: string) {
  if (!pick.value) return
  if (!pickBinOk.value) {
    if (!matches(v, pick.value.bin, pick.value.bin_barcode)) return tell('error', t('warehouse_management.floor.wrong_bin', { want: pick.value.bin }))
    pickBinOk.value = true
    pickQty.value = pick.value.qty
    return tell('info', t('warehouse_management.floor.scan_item', { item: pick.value.item }))
  }
  // Product barcodes beyond the code are checked when the pick reaches the server.
  pickItemScan.value = v
  tell('info', t('warehouse_management.floor.confirm_qty'))
}
function confirmPick(short = false) {
  if (!pick.value || !pickBinOk.value || !pickItemScan.value) return tell('error', t('warehouse_management.floor.scan_first'))
  const qty = pickQty.value ?? 0
  if (qty > pick.value.qty) return tell('error', t('warehouse_management.floor.too_many', { n: pick.value.qty }))
  act('PICK_CONFIRM', { task: pick.value.id, qty, bin_scan: pick.value.bin_barcode ?? pick.value.bin, item_scan: pickItemScan.value, short: short || qty < pick.value.qty },
    pick.value.id, `${pick.value.bin} ${pick.value.item} × ${qty}`)
  pickBinOk.value = false
  pickItemScan.value = null
}

// ── Put-away ────────────────────────────────────────────────────────────────
const putaways = computed(() => (bundle.value?.putaways ?? []).filter((p: any) => !done.value.has(`PUTAWAY_COMPLETE:${p.id}`)))
const putaway = ref<any>(null)
const putQty = ref<number | null>(null)
function scanPutaway(v: string) {
  if (!putaway.value) return
  act('PUTAWAY_COMPLETE', { task: putaway.value.id, to_bin: v, qty: putQty.value ?? putaway.value.qty }, putaway.value.id, `${putaway.value.item} → ${v}`)
  putaway.value = null
}
</script>

<template>
  <div class="pa-3" style="max-width: 720px; margin: 0 auto">
    <div class="d-flex align-center justify-space-between mb-3 flex-wrap gap-2">
      <h4 class="text-h5">{{ t('warehouse_management.floor.title') }}</h4>
      <div class="d-flex gap-2 align-center">
        <VChip :color="queue.isOnline.value ? 'success' : 'warning'" size="small">{{ queue.isOnline.value ? t('warehouse_management.floor.online') : t('warehouse_management.floor.offline') }}</VChip>
        <VChip v-if="queue.pendingCount.value" color="warning" size="small" role="status">{{ t('warehouse_management.floor.unsynced', { n: queue.pendingCount.value }) }}</VChip>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-refresh" :loading="queue.syncStatus.value === 'syncing'" @click="refresh">{{ t('warehouse_management.floor.sync') }}</VBtn>
      </div>
    </div>
    <p class="text-caption text-medium-emphasis mb-2">{{ t('warehouse_management.floor.offline_scope') }}<span v-if="cachedAt"> · {{ t('warehouse_management.floor.cached', { at: new Date(cachedAt).toLocaleString() }) }}</span></p>
    <VAlert v-for="p in bundle?.problems ?? []" :key="p.client_ref" type="warning" variant="tonal" density="compact" class="mb-2">
      {{ t(`warehouse_management.offline_kind.${p.kind}`) }}: {{ p.message }} {{ t('warehouse_management.floor.supervisor') }}</VAlert>
    <VAlert v-if="message" :type="message.type" variant="tonal" class="mb-3" role="alert">{{ message.text }}</VAlert>

    <VBtnToggle v-model="mode" mandatory divided class="mb-4 w-100" density="comfortable">
      <VBtn v-if="can.pick" value="PICK" class="flex-grow-1" size="large">{{ t('warehouse_management.task_type.PICK') }} ({{ picks.length }})</VBtn>
      <VBtn v-if="can.count" value="COUNT" class="flex-grow-1" size="large">{{ t('warehouse_management.task_type.COUNT') }} ({{ bundle?.counts?.length ?? 0 }})</VBtn>
      <VBtn v-if="can.putaway" value="PUTAWAY" class="flex-grow-1" size="large">{{ t('warehouse_management.task_type.PUTAWAY') }} ({{ putaways.length }})</VBtn>
    </VBtnToggle>

    <template v-if="mode === 'PICK'">
      <VCard v-if="!pick" border><VCardText class="text-center py-8">{{ t('warehouse_management.floor.no_picks') }}</VCardText></VCard>
      <VCard v-else border>
        <VCardText>
          <div class="text-overline">{{ t('warehouse_management.floor.next_pick', { n: picks.length }) }} · {{ pick.order }}</div>
          <div class="text-h3 my-2">{{ pick.bin }}</div>
          <div class="text-h6">{{ pick.item }} <span class="text-medium-emphasis">{{ pick.name }}</span></div>
          <div class="text-h5 mb-3">× {{ pick.qty }} {{ pick.uom }}<span v-if="pick.lot" class="text-body-1"> · {{ t('warehouse_management.counts.lot', { lot: pick.lot }) }}</span></div>
          <ScanInput :label="pickBinOk ? t('warehouse_management.floor.scan_product') : t('warehouse_management.floor.scan_location')" autofocus @scan="scanPick" />
          <div class="d-flex gap-2 mt-3 align-center">
            <VTextField v-model.number="pickQty" type="number" min="0" :label="t('warehouse_management.quality.qty')" density="comfortable" hide-details style="max-width: 140px" />
            <VBtn color="primary" size="x-large" class="flex-grow-1" :disabled="!pickItemScan" @click="confirmPick()">{{ t('warehouse_management.floor.confirm') }}</VBtn>
            <VBtn variant="tonal" size="x-large" color="warning" :disabled="!pickBinOk" @click="confirmPick(true)">{{ t('warehouse_management.floor.short') }}</VBtn>
          </div>
        </VCardText>
      </VCard>
    </template>

    <template v-if="mode === 'COUNT'">
      <VSelect v-model="countId" :items="(bundle?.counts ?? []).map((c: any) => ({ value: c.id, title: `${c.number} · ${c.warehouse} · ${c.lines.length}` }))" :label="t('warehouse_management.floor.choose_count')" class="mb-3" />
      <template v-if="count">
        <ScanInput :label="t('warehouse_management.floor.scan_location')" autofocus @scan="scanCountBin" />
        <VCard v-for="l in countLines" :key="l.id" border class="mt-3">
          <VCardText class="d-flex gap-2 align-center">
            <div class="flex-grow-1"><div class="text-h6">{{ l.item }}</div><div class="text-caption">{{ l.name }}<span v-if="l.lot"> · {{ l.lot }}</span><span v-if="l.round > 1"> · {{ t('warehouse_management.counts.round', { n: l.round }) }}</span></div></div>
            <VTextField v-model.number="countQty[l.id]" type="number" min="0" density="comfortable" hide-details style="max-width: 120px" :suffix="l.uom" @keyup.enter="enterCount(l)" />
            <VBtn color="primary" size="large" @click="enterCount(l)">{{ t('warehouse_management.counts.enter') }}</VBtn>
          </VCardText>
        </VCard>
        <p v-if="countBin && !countLines.length" class="text-body-2 mt-3">{{ t('warehouse_management.floor.bin_done', { bin: countBin }) }}</p>
      </template>
    </template>

    <template v-if="mode === 'PUTAWAY'">
      <VCard v-if="putaway" border class="mb-3">
        <VCardText>
          <div class="text-h6">{{ putaway.item }} × {{ putaway.qty }} {{ putaway.uom }}</div>
          <div class="text-body-1 mb-2">{{ t('warehouse_management.floor.put_to', { from: putaway.from ?? '—', to: putaway.to ?? '?' }) }}</div>
          <VTextField v-model.number="putQty" type="number" min="0" :label="t('warehouse_management.quality.qty')" density="comfortable" class="mb-2" />
          <ScanInput :label="t('warehouse_management.floor.scan_destination')" autofocus @scan="scanPutaway" />
        </VCardText>
      </VCard>
      <VList v-else lines="two">
        <VListItem v-if="!putaways.length">{{ t('warehouse_management.floor.no_putaways') }}</VListItem>
        <VListItem v-for="p in putaways" :key="p.id" :title="`${p.number} · ${p.item} × ${p.qty}`" :subtitle="`${p.from ?? '—'} → ${p.to ?? '?'}`" @click="putaway = p; putQty = p.qty" />
      </VList>
    </template>
    <div v-if="can.review" class="mt-6 text-center"><a :href="route('warehouse.mobile.conflicts')">{{ t('warehouse_management.floor.review') }}</a></div>
  </div>
</template>
