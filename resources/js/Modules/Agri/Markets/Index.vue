<script setup lang="ts">
/**
 * Markets & hedging: commodity prices per futures month (from the organisation's price feeds or
 * typed in), the feeds, the hedging register with its daily mark-to-market, the price position per
 * crop, and unsold grain and open contracts at today's prices. Records only: nothing trades.
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import PriceChart from './PriceChart.vue'
import { human, money, num, today } from '../Components/agri'

type Opt = { value: any; title: string }
const props = defineProps<{
  tab: 'prices' | 'feeds' | 'hedges' | 'position' | 'valuation'
  crop: null | { id: string; name: string }
  data: Record<string, any>
  options: { crops: Array<Opt & { id: number }>; contracts: Opt[]; plans: Opt[]; columns: string[]; contract_sizes: Record<string, number> }
  can: { manage: boolean; hedges: boolean; manage_hedges: boolean; sales: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.markets.${key}`, params ?? {})
const keep = { preserveScroll: true }
const TABS = computed(() => [...(props.can.sales ? ['prices', 'feeds'] : []), ...(props.can.hedges ? ['hedges', 'position'] : []), ...(props.can.sales ? ['valuation'] : [])])
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.markets', tab), { crop: props.crop?.id, ...extra }, { preserveState: false })
const cropIds = props.options.crops.map(c => ({ title: c.title, value: c.id }))
const signed = (v: number | null | undefined) => (v === null || v === undefined ? '—' : (v > 0 ? '+' : '') + money(v))

// ── prices ───────────────────────────────────────────────────────────────────────────────────
const priceFields: FormField[] = [
  { key: 'crop_type_id', label: k('f.crop'), type: 'select', items: cropIds, required: true, cols: 6 }, { key: 'product', label: k('f.product'), cols: 6, hint: k('f.product_hint') },
  { key: 'market', label: k('f.market'), required: true, cols: 6 },
  { key: 'price_type', label: k('f.price_type'), type: 'select', items: ['spot', 'futures', 'cash'].map(x => ({ title: k(`type.${x}`), value: x })), required: true, cols: 6 },
  { key: 'contract_month', label: k('f.contract_month'), cols: 4, hint: 'YYYY-MM', show: f => f.price_type === 'futures' },
  { key: 'price_date', label: k('f.date'), type: 'date', required: true, cols: 4 }, { key: 'price', label: k('f.price'), type: 'number', required: true, cols: 4 },
  { key: 'unit', label: k('f.unit'), required: true, cols: 3 }, { key: 'currency', label: k('f.currency'), required: true, cols: 3 },
  { key: 'grade', label: k('f.grade'), cols: 6 }, { key: 'source', label: k('f.source'), required: true, cols: 12, hint: k('f.source_hint') },
]

// ── feeds ────────────────────────────────────────────────────────────────────────────────────
const feed = ref<any>(null)
const productsText = ref('')
const editFeed = (f: any = null) => {
  feed.value = f
    ? { ...f, config: { delimiter: '', list: '', price_multiplier: 1, date_format: '', skip_rows: 0, ...f.config, columns: { ...(f.config?.columns ?? {}) } }, auth: { type: f.auth_type, name: '', value: '', username: '', password: '' }, paused: f.status === 'paused' }
    : { name: '', adapter: 'csv', url: '', market: 'JSE SAFEX', product: '', crop_type_id: null, currency: 'ZAR', unit: 't', price_type: 'futures', refresh_minutes: 1440,
        config: { delimiter: '', list: '', price_multiplier: 1, date_format: '', skip_rows: 0, columns: { date: 'Date', product: 'Contract', contract_month: 'Expiry', price: 'MTM' } },
        auth: { type: 'none', name: '', value: '', username: '', password: '' }, paused: false }
  productsText.value = Object.entries(feed.value.config.products ?? {}).map(([code, m]: any) => `${code}=${m.product}${m.crop_code ? '; ' + m.crop_code : ''}`).join('\n')
}
const saveFeed = () => {
  // "WMAZ=White maize; MAIZE-W" per line: the feed's product code, our product name and the crop.
  const products = Object.fromEntries(productsText.value.split('\n').map(l => l.trim()).filter(l => l.includes('=')).map(l => {
    const [code, rest] = l.split('=')
    const [product, crop] = rest.split(';').map(x => x.trim())
    return [code.trim(), { product, ...(crop ? { crop_code: crop } : {}) }]
  }))
  const columns = Object.fromEntries(Object.entries(feed.value.config.columns).filter(([, v]) => v !== '' && v !== null))
  const body = { ...feed.value, config: { ...feed.value.config, columns, products } }
  const opts = { ...keep, onSuccess: () => { feed.value = null } }
  feed.value.uuid ? router.patch(route('agri.markets.feeds.update', feed.value.uuid), body, opts) : router.post(route('agri.markets.feeds.store'), body, opts)
}
const fetchFeed = (f: any) => router.post(route('agri.markets.feeds.fetch', f.uuid), {}, keep)

// ── hedges ───────────────────────────────────────────────────────────────────────────────────
const hedgeFields: FormField[] = [
  { key: 'crop_type_id', label: k('f.crop'), type: 'select', items: cropIds, required: true, cols: 6 }, { key: 'market', label: k('f.market'), cols: 6 },
  { key: 'instrument', label: k('f.instrument'), type: 'select', items: [{ title: k('instrument.futures'), value: 'futures' }, { title: k('instrument.option'), value: 'option' }], required: true, cols: 4 },
  { key: 'side', label: k('f.side'), type: 'select', items: [{ title: k('side.sell'), value: 'sell' }, { title: k('side.buy'), value: 'buy' }], required: true, cols: 4 },
  { key: 'option_type', label: k('f.option_type'), type: 'select', items: [{ title: k('option.put'), value: 'put' }, { title: k('option.call'), value: 'call' }], cols: 4, show: f => f.instrument === 'option' },
  { key: 'strike', label: k('f.strike'), type: 'number', cols: 4, show: f => f.instrument === 'option' }, { key: 'premium', label: k('f.premium'), type: 'number', cols: 4, show: f => f.instrument === 'option' },
  { key: 'contract_month', label: k('f.contract_month'), required: true, cols: 4, hint: 'YYYY-MM' }, { key: 'contracts', label: k('f.contracts'), type: 'number', required: true, cols: 4 },
  { key: 'contract_size', label: k('f.contract_size'), type: 'number', cols: 4, hint: k('f.contract_size_hint') },
  { key: 'entry_price', label: k('f.entry_price'), type: 'number', required: true, cols: 4, hint: k('f.entry_price_hint') }, { key: 'entry_date', label: k('f.entry_date'), type: 'date', required: true, cols: 4 },
  { key: 'broker', label: k('f.broker'), cols: 4 }, { key: 'account', label: k('f.account'), cols: 4 },
  { key: 'crop_plan_id', label: k('f.plan'), type: 'select', items: props.options.plans, cols: 6 }, { key: 'contract_id', label: k('f.sales_contract'), type: 'select', items: props.options.contracts, cols: 6 },
  { key: 'notes', label: k('f.notes'), type: 'textarea', cols: 12 },
]
const closeFields: FormField[] = [
  { key: 'status', label: k('f.how'), type: 'select', items: ['closed', 'expired', 'exercised'].map(x => ({ title: k(`status.${x}`), value: x })), required: true, cols: 4 },
  { key: 'exit_price', label: k('f.exit_price'), type: 'number', cols: 4, hint: k('f.exit_price_hint'), show: f => f.status !== 'expired' },
  { key: 'exit_date', label: k('f.exit_date'), type: 'date', required: true, cols: 4 },
]
const markAll = () => router.post(route('agri.markets.hedges.mark'), {}, keep)
const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const expanded = ref<string | null>(null)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.hub.operations')">{{ k('breadcrumb') }}</Link></div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <VSelect v-if="['prices', 'hedges'].includes(tab)" :model-value="crop?.id ?? null" :items="options.crops" :label="k('f.crop')" density="compact" hide-details
        style="min-width: 220px; max-width: 280px" @update:model-value="v => go(tab, { crop: v })" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="x in TABS" :key="x" :color="tab === x ? 'primary' : undefined" :variant="tab === x ? 'flat' : 'tonal'" @click="go(x)">{{ k(`tabs.${x}`) }}</VChip>
    </div>

    <!-- prices -->
    <template v-if="tab === 'prices'">
      <VCard class="mb-4">
        <VCardItem><VCardTitle>{{ k('history', { crop: crop?.name ?? '—' }) }}</VCardTitle><VCardSubtitle>{{ data.reference }}</VCardSubtitle>
          <template #append><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('price')">{{ k('add_price') }}</VBtn></template></VCardItem>
        <VCardText>
          <PriceChart :series="data.series" />
          <VAlert v-if="!data.series?.length" type="info" variant="tonal">{{ k('no_prices') }}</VAlert>
          <div class="d-flex flex-wrap gap-2 mt-2"><VChip v-for="s in data.series" :key="s.key" size="small" variant="tonal">{{ s.label }}: {{ money(s.latest.price) }} ({{ s.latest.date }})</VChip></div>
        </VCardText>
      </VCard>
      <VCard :title="k('recorded')"><VTable density="compact"><tbody>
        <tr v-for="p in data.prices" :key="p.id"><td>{{ p.price_date }}</td><td>{{ p.product }} {{ p.grade }}</td>
          <td>{{ p.market }}<div class="text-caption">{{ k(`type.${p.price_type}`) }}<template v-if="p.contract_month"> {{ p.contract_month }}</template></div></td>
          <td class="text-end">{{ num(p.price) }} {{ p.currency }}/{{ p.unit }}</td>
          <td><VChip size="x-small" :color="p.kind === 'forecast' ? 'warning' : undefined">{{ p.kind === 'forecast' ? k('estimate') : k('actual') }}</VChip>
            <VChip v-if="p.flagged" size="x-small" color="error" class="ms-1">{{ k('flagged') }}</VChip></td><td class="text-caption">{{ p.source }}</td></tr>
      </tbody></VTable></VCard>
    </template>

    <!-- feeds -->
    <template v-if="tab === 'feeds'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ k('feeds_intro') }}</VAlert>
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="editFeed()">{{ k('add_feed') }}</VBtn></div>
      <VRow>
        <VCol v-for="f in data.feeds" :key="f.uuid" cols="12" md="6"><VCard>
          <VCardItem><VCardTitle>{{ f.name }}</VCardTitle><VCardSubtitle>{{ f.adapter.toUpperCase() }} · {{ f.market }} · {{ k(`type.${f.price_type}`) }} · {{ f.currency }}/{{ f.unit }}</VCardSubtitle>
            <template #append><VChip size="small" :color="f.status === 'active' ? (f.stale ? 'warning' : 'success') : f.status === 'failing' ? 'error' : undefined">{{ f.stale && f.status === 'active' ? k('stale') : k(`feed_status.${f.status}`) }}</VChip></template></VCardItem>
          <VCardText>
            <div class="text-caption text-truncate">{{ f.url }}</div>
            <div class="text-body-2">{{ k('last_success', { at: f.last_success_at ?? k('never'), n: f.last_items ?? 0 }) }}</div>
            <div class="text-body-2">{{ k('next', { at: f.next_attempt_at ?? k('now') }) }} · {{ k('auth', { type: k(`auth_type.${f.auth_type}`) }) }}</div>
            <div v-if="f.last_error" class="text-body-2 text-error">{{ f.last_error }}<template v-if="f.failure_count"> ({{ k('failures', { n: f.failure_count }) }})</template></div>
            <div class="text-caption mt-1">{{ k('mapping') }}: {{ Object.entries(f.config?.columns ?? {}).map(([a, b]) => `${a} ← ${b}`).join(' · ') }}</div>
          </VCardText>
          <VCardActions v-if="can.manage"><VBtn size="small" variant="tonal" prepend-icon="tabler-refresh" :disabled="f.status === 'paused'" @click="fetchFeed(f)">{{ k('fetch_now') }}</VBtn>
            <VBtn size="small" variant="text" @click="editFeed(f)">{{ k('edit') }}</VBtn></VCardActions>
        </VCard></VCol>
      </VRow>
      <VAlert v-if="!data.feeds?.length" type="info" variant="tonal" class="mt-2">{{ k('no_feeds') }}</VAlert>
    </template>

    <!-- hedges -->
    <template v-if="tab === 'hedges'">
      <VAlert type="warning" variant="tonal" class="mb-4" :title="k('records_only_title')">{{ k('records_only') }}</VAlert>
      <div class="d-flex gap-2 mb-3">
        <VBtn v-if="can.manage_hedges" color="primary" prepend-icon="tabler-plus" @click="open('hedge')">{{ k('add_hedge') }}</VBtn>
        <VBtn v-if="can.manage_hedges" variant="tonal" prepend-icon="tabler-refresh" @click="markAll">{{ k('mark_now') }}</VBtn>
      </div>
      <VCard><VTable density="compact">
        <thead><tr><th>{{ k('col.hedge') }}</th><th>{{ k('col.position') }}</th><th class="text-end">{{ k('col.quantity') }}</th><th class="text-end">{{ k('col.entry') }}</th>
          <th class="text-end">{{ k('col.mark') }}</th><th class="text-end">{{ k('col.pnl') }}</th><th>{{ k('col.status') }}</th><th /></tr></thead>
        <tbody>
          <template v-for="h in data.hedges" :key="h.uuid">
            <tr>
              <td><a href="#" @click.prevent="expanded = expanded === h.uuid ? null : h.uuid"><strong>{{ h.reference }}</strong></a><div class="text-caption">{{ h.crop }} · {{ h.market }} {{ h.contract_month }}</div></td>
              <td>{{ k(`side.${h.side}`) }} {{ h.instrument === 'option' ? `${k(`option.${h.option_type}`)} @ ${num(h.strike)}` : k('instrument.futures') }}
                <div class="text-caption">{{ h.broker }}<template v-if="h.account"> · {{ h.account }}</template><template v-if="h.contract"> · {{ h.contract }}</template><template v-if="h.plan"> · {{ h.plan }}</template></div></td>
              <td class="text-end">{{ num(h.quantity) }} t<div class="text-caption">{{ num(h.contracts) }} × {{ num(h.contract_size) }} t</div></td>
              <td class="text-end">{{ num(h.entry_price) }}<div class="text-caption">{{ h.entry_date }}<template v-if="h.premium !== null"> · {{ k('premium_short', { p: num(h.premium) }) }}</template></div></td>
              <td class="text-end">{{ h.status === 'open' ? num(h.last_mark_price) : num(h.exit_price) }}<div class="text-caption">{{ h.status === 'open' ? h.last_mark_date : h.exit_date }}</div></td>
              <td class="text-end font-weight-medium" :class="(h.status === 'open' ? h.unrealised_pnl : h.realised_pnl) < 0 ? 'text-error' : 'text-success'">
                {{ signed(h.status === 'open' ? h.unrealised_pnl : h.realised_pnl) }}<div class="text-caption">{{ h.status === 'open' ? k('unrealised') : k('realised') }}</div></td>
              <td><VChip size="small" :color="h.status === 'open' ? 'info' : undefined">{{ k(`status.${h.status}`) }}</VChip></td>
              <td><VBtn v-if="can.manage_hedges && h.status === 'open'" size="small" variant="tonal" @click="open('close', h)">{{ k('close') }}</VBtn></td>
            </tr>
            <tr v-if="expanded === h.uuid"><td colspan="8" class="bg-grey-lighten-5"><span v-if="!h.marks.length" class="text-caption">{{ k('no_marks') }}</span>
              <span v-for="m in h.marks.slice(-15)" :key="m.date" class="text-caption me-3">{{ m.date }}: {{ num(m.price) }} → {{ signed(m.pnl) }}</span>
              <div v-if="h.notes" class="text-caption">{{ h.notes }}</div></td></tr>
          </template>
          <tr v-if="!data.hedges?.length"><td colspan="8" class="text-center text-medium-emphasis py-4">{{ k('no_hedges') }}</td></tr>
        </tbody>
      </VTable></VCard>
      <div class="text-caption mt-2">{{ k('valuation_note') }}</div>
    </template>

    <!-- position -->
    <template v-if="tab === 'position'">
      <VRow>
        <VCol v-for="p in data.crops" :key="p.crop_type_id" cols="12" md="6"><VCard>
          <VCardItem><VCardTitle>{{ p.crop }}</VCardTitle><VCardSubtitle>{{ p.covered_pct !== null ? k('covered', { pct: p.covered_pct }) : '' }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VProgressLinear v-if="p.expected > 0" :model-value="Math.min(100, p.covered_pct ?? 0)" height="10" rounded color="primary" class="mb-3" />
            <VTable density="compact"><tbody>
              <tr><td>{{ k('pos.expected') }}</td><td class="text-end">{{ num(p.expected, 1) }} t</td></tr>
              <tr><td>{{ k('pos.sold_forward') }}</td><td class="text-end">− {{ num(p.sold_forward, 1) }} t</td></tr>
              <tr><td>{{ k('pos.hedged') }}</td><td class="text-end">− {{ num(p.hedged, 1) }} t</td></tr>
              <tr class="font-weight-medium"><td>{{ k('pos.unhedged') }}</td><td class="text-end" :class="p.unhedged < 0 ? 'text-error' : ''">{{ num(p.unhedged, 1) }} t</td></tr>
              <tr><td>{{ k('pos.unhedged_value') }}</td><td class="text-end">{{ money(p.unhedged_value) }}<div class="text-caption">{{ p.price_per_t !== null ? `${money(p.price_per_t)}/t` : '' }}</div></td></tr>
              <tr><td>{{ k('pos.hedge_pnl') }}</td><td class="text-end">{{ signed(p.unrealised_pnl) }}</td></tr>
            </tbody></VTable>
            <div v-if="p.unhedged < 0" class="text-caption text-error mt-1">{{ k('over_hedged') }}</div>
            <div class="text-caption mt-2">{{ p.price_basis }}</div>
            <div v-for="(n, i) in p.notes" :key="i" class="text-caption">{{ n }}</div>
          </VCardText>
        </VCard></VCol>
      </VRow>
      <VAlert v-if="!data.crops?.length" type="info" variant="tonal">{{ k('no_position') }}</VAlert>
      <div v-else class="text-caption mt-2">{{ data.crops[0].basis }}</div>
    </template>

    <!-- valuation -->
    <template v-if="tab === 'valuation'">
      <VAlert type="info" variant="tonal" class="mb-4">{{ data.label }}</VAlert>
      <VCard class="mb-4" :title="k('unsold', { v: money(data.stock_value) })"><VTable density="compact">
        <thead><tr><th>{{ k('col.crop') }}</th><th class="text-end">{{ k('col.on_hand') }}</th><th>{{ k('col.by_grade') }}</th><th class="text-end">{{ k('col.price') }}</th><th class="text-end">{{ k('col.value') }}</th></tr></thead>
        <tbody><tr v-for="s in data.stock" :key="s.crop_type_id"><td>{{ s.crop }}</td><td class="text-end">{{ num(s.tonnes, 1) }} t</td>
          <td class="text-caption">{{ Object.entries(s.by_grade).map(([g, q]) => `${g} ${num(q as number, 1)} t`).join(' · ') }}</td>
          <td class="text-end">{{ s.price_per_t !== null ? money(s.price_per_t) + '/t' : '—' }}<div class="text-caption">{{ s.basis }}</div></td><td class="text-end font-weight-medium">{{ money(s.value) }}</td></tr>
          <tr v-if="!data.stock?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('no_stock') }}</td></tr></tbody>
      </VTable></VCard>
      <VCard :title="k('open_contracts')"><VTable density="compact">
        <thead><tr><th>{{ k('col.contract') }}</th><th class="text-end">{{ k('col.outstanding') }}</th><th class="text-end">{{ k('col.contract_price') }}</th><th class="text-end">{{ k('col.market_price') }}</th>
          <th class="text-end">{{ k('col.value') }}</th><th class="text-end">{{ k('col.against_market') }}</th></tr></thead>
        <tbody><tr v-for="c in data.contracts" :key="c.reference"><td><strong>{{ c.reference }}</strong><div class="text-caption">{{ c.product }} · {{ human(c.pricing) }}</div></td>
          <td class="text-end">{{ num(c.outstanding, 3) }} {{ c.unit }}</td>
          <td class="text-end">{{ c.contract_price !== null ? money(c.contract_price) : '—' }}<div class="text-caption">{{ c.contract_basis }}</div></td>
          <td class="text-end">{{ c.market_price !== null ? money(c.market_price) : '—' }}<div class="text-caption">{{ c.market_basis }}</div></td>
          <td class="text-end">{{ money(c.value_at_contract) }}</td>
          <td class="text-end font-weight-medium" :class="(c.against_market ?? 0) < 0 ? 'text-error' : 'text-success'">{{ signed(c.against_market) }}</td></tr>
          <tr v-if="!data.contracts?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">{{ k('no_contracts') }}</td></tr></tbody>
      </VTable></VCard>
    </template>

    <!-- feed -->
    <VDialog :model-value="!!feed" max-width="980" scrollable @update:model-value="v => { if (!v) feed = null }">
      <VCard v-if="feed" :title="feed.uuid ? k('edit_feed', { name: feed.name }) : k('add_feed')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VTextField v-model="feed.name" :label="k('f.name') + ' *'" /></VCol>
            <VCol cols="6" md="3"><VSelect v-model="feed.adapter" :items="[{ title: 'CSV', value: 'csv' }, { title: 'JSON', value: 'json' }]" :label="k('f.adapter')" /></VCol>
            <VCol cols="6" md="3"><VSelect v-model="feed.price_type" :items="['spot', 'futures', 'cash'].map(x => ({ title: k(`type.${x}`), value: x }))" :label="k('f.price_type')" /></VCol>
            <VCol cols="12"><VTextField v-model="feed.url" :label="k('f.url') + ' *'" placeholder="https://" /></VCol>
            <VCol cols="6" md="4"><VTextField v-model="feed.market" :label="k('f.market') + ' *'" /></VCol>
            <VCol cols="6" md="4"><VSelect v-model="feed.crop_type_id" :items="cropIds" :label="k('f.default_crop')" clearable /></VCol>
            <VCol cols="6" md="4"><VTextField v-model="feed.product" :label="k('f.default_product')" /></VCol>
            <VCol cols="4" md="2"><VTextField v-model="feed.currency" :label="k('f.currency')" /></VCol>
            <VCol cols="4" md="2"><VTextField v-model="feed.unit" :label="k('f.unit')" /></VCol>
            <VCol cols="4" md="2"><VTextField v-model.number="feed.config.price_multiplier" type="number" :label="k('f.multiplier')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-if="feed.adapter === 'csv'" v-model="feed.config.delimiter" :label="k('f.delimiter')" :hint="k('f.delimiter_hint')" persistent-hint />
              <VTextField v-else v-model="feed.config.list" :label="k('f.list')" :hint="k('f.list_hint')" persistent-hint /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="feed.config.date_format" :label="k('f.date_format')" :hint="k('f.date_format_hint')" persistent-hint /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-3">{{ k('columns') }}</div>
          <div class="text-caption mb-2">{{ k('columns_hint') }}</div>
          <VRow dense><VCol v-for="c in options.columns" :key="c" cols="6" md="3"><VTextField v-model="feed.config.columns[c]" :label="k(`column.${c}`)" density="compact" /></VCol></VRow>
          <VTextarea v-model="productsText" :label="k('f.products')" :hint="k('f.products_hint')" persistent-hint rows="3" class="mt-2" />
          <div class="text-subtitle-2 mt-3">{{ k('credentials') }}</div>
          <VRow dense>
            <VCol cols="12" md="3"><VSelect v-model="feed.auth.type" :items="['none', 'header', 'bearer', 'basic', 'query'].map(x => ({ title: k(`auth_type.${x}`), value: x }))" :label="k('f.auth')" /></VCol>
            <VCol v-if="['header', 'query'].includes(feed.auth.type)" cols="6" md="3"><VTextField v-model="feed.auth.name" :label="k('f.auth_name')" /></VCol>
            <VCol v-if="['header', 'query', 'bearer'].includes(feed.auth.type)" cols="6" md="6"><VTextField v-model="feed.auth.value" type="password" :label="k('f.auth_value')" :hint="feed.uuid ? k('f.secret_kept') : ''" persistent-hint /></VCol>
            <template v-if="feed.auth.type === 'basic'"><VCol cols="6" md="4"><VTextField v-model="feed.auth.username" :label="k('f.username')" /></VCol>
              <VCol cols="6" md="5"><VTextField v-model="feed.auth.password" type="password" :label="k('f.password')" :hint="feed.uuid ? k('f.secret_kept') : ''" persistent-hint /></VCol></template>
          </VRow>
          <VRow dense><VCol cols="6" md="3"><VTextField v-model.number="feed.refresh_minutes" type="number" :label="k('f.refresh')" /></VCol>
            <VCol cols="6" md="3"><VSwitch v-model="feed.paused" :label="k('f.paused')" color="primary" inset hide-details /></VCol></VRow>
          <div class="text-caption mt-2">{{ k('guard') }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="feed = null">{{ k('cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!feed.name || !feed.url || !feed.market" @click="saveFeed">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <FormDialog v-if="dialog?.kind === 'price'" :model-value="true" :title="k('add_price')" :url="route('agri.markets.prices.store')" :fields="priceFields"
      :values="{ crop_type_id: options.crops.find(c => c.value === crop?.id)?.id ?? null, market: 'JSE SAFEX', price_type: 'futures', price_date: today(), unit: 't', currency: 'ZAR' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'hedge'" :model-value="true" :title="k('add_hedge')" :url="route('agri.markets.hedges.store')" :fields="hedgeFields" :intro="k('records_only')"
      :values="{ crop_type_id: options.crops.find(c => c.value === crop?.id)?.id ?? null, market: 'JSE SAFEX', instrument: 'futures', side: 'sell', entry_date: today() }" max-width="900" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'close'" :model-value="true" :title="k('close_title', { ref: dialog.row.reference })" :url="route('agri.markets.hedges.close', dialog.row.uuid)" :fields="closeFields"
      :values="{ status: 'closed', exit_date: today() }" @update:model-value="close" />
  </div>
</template>
