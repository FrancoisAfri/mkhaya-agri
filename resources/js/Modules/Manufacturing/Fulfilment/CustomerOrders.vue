<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { fulfilmentFlagColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Eligible = {
  line_id: number; sales_order: string; sales_order_id: string; customer: string | null; customer_ref: string | null; order_date: string
  description: string | null; item: string; item_name: string; strategy: string; ordered: number; delivered: number; outstanding: number
  free_stock: number; commitment: string | null; committed_status: string | null
}
type Commitment = {
  id: string; sales_order: string | null; sales_order_status: string | null; customer: string | null; customer_ref: string | null
  item: string | null; item_name: string | null; strategy: string; configuration: string | null
  ordered: number; delivered: number; outstanding: number; ready: number; in_production: number; produced: number
  required_date: string | null; promised_date: string | null; projected_finish: string | null; flag: string; risks: string[]
  production_orders: Array<{ id: string; number: string; status: string; qty: number; completed: number }>
  references: { deliveries: Array<{ number: string; date: string; qty: number }>; invoice: { number: string; status: string; date: string } | null }
}
type Quote = { to_make: number; free_stock: number; strategy: string; days: number; material_days: number; production_days: number; finish: string
  shortages: Array<{ item: string; short: number; lead_days: number | null }>; warnings: string[] }

const props = defineProps<{
  commitments: Commitment[]
  eligible: Eligible[]
  factories: Array<{ id: number; name: string }>
  warehouses: Array<{ id: number; name: string }>
  filters: { status: string }
  can: { commit: boolean }
}>()

const status = ref(props.filters.status)
watch(status, v => router.get(route('manufacturing.fulfilment.customer-orders'), { status: v }, { preserveState: true, replace: true }))
const num = (n: number | null | undefined) => n == null ? '—' : Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 })

const dialog = ref(false)
const line = ref<Eligible | null>(null)
const quote = ref<Quote | null>(null)
const quoting = ref(false)
const form = useForm({ line_id: 0, factory_id: props.factories[0]?.id ?? null as number | null, warehouse_id: props.warehouses[0]?.id ?? null as number | null,
  required_date: '', configuration: '', make: false })

const open = (l: Eligible) => {
  line.value = l
  form.reset()
  form.line_id = l.line_id
  form.required_date = l.order_date
  form.configuration = l.strategy === 'CTO' ? (l.description ?? '') : ''
  dialog.value = true
  loadQuote()
}
const loadQuote = async () => {
  if (!line.value) return
  quoting.value = true
  quote.value = null
  try {
    const res = await fetch(route('manufacturing.fulfilment.customer-orders.quote', { line_id: line.value.line_id, factory_id: form.factory_id ?? undefined }),
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (res.ok) quote.value = await res.json()
  } finally {
    quoting.value = false
  }
}
watch(() => form.factory_id, () => dialog.value && loadQuote())
const submit = () => form.post(route('manufacturing.fulfilment.customer-orders.commit'), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
const cancel = (c: Commitment) => {
  if (confirm(t('manufacturing.fulfilment.cancel_confirm'))) router.post(route('manufacturing.fulfilment.customer-orders.cancel', c.id), {}, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.fulfilment.customer_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.fulfilment.customer_subtitle') }}</p>
      </div>
      <VBtnToggle v-model="status" density="compact" variant="outlined" mandatory>
        <VBtn value="open">{{ t('manufacturing.fulfilment.open') }}</VBtn>
        <VBtn value="all">{{ t('manufacturing.fulfilment.all') }}</VBtn>
      </VBtnToggle>
    </div>

    <VCard v-if="can.commit" border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.fulfilment.eligible_title') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.fulfilment.eligible_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <thead><tr>
          <th>{{ t('manufacturing.fulfilment.sales_order') }}</th><th>{{ t('manufacturing.fulfilment.customer') }}</th><th>{{ t('manufacturing.fulfilment.product') }}</th>
          <th>{{ t('manufacturing.fulfilment.strategy_label') }}</th><th class="text-end">{{ t('manufacturing.fulfilment.outstanding') }}</th>
          <th class="text-end">{{ t('manufacturing.fulfilment.free_stock') }}</th><th />
        </tr></thead>
        <tbody>
          <tr v-for="l in eligible" :key="l.line_id">
            <td>{{ l.sales_order }}<div class="text-caption text-medium-emphasis">{{ l.order_date }}</div></td>
            <td>{{ l.customer }}<div v-if="l.customer_ref" class="text-caption text-medium-emphasis">{{ l.customer_ref }}</div></td>
            <td>{{ l.item }}<div class="text-caption text-medium-emphasis">{{ l.item_name }}</div></td>
            <td><VChip size="small" label>{{ t(`manufacturing.fulfilment.strategy.${l.strategy}`) }}</VChip></td>
            <td class="text-end">{{ num(l.outstanding) }}</td>
            <td class="text-end">{{ num(l.free_stock) }}</td>
            <td class="text-end">
              <VBtn size="small" :variant="l.commitment ? 'text' : 'tonal'" @click="open(l)">{{ l.commitment ? t('manufacturing.fulfilment.top_up') : t('manufacturing.fulfilment.commit') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!eligible.length"><td colspan="7" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.fulfilment.no_eligible') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard border>
      <VCardItem><VCardTitle>{{ t('manufacturing.fulfilment.progress_title') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.fulfilment.progress_hint') }}</VCardSubtitle></VCardItem>
      <VExpansionPanels variant="accordion">
        <VExpansionPanel v-for="c in commitments" :key="c.id">
          <VExpansionPanelTitle>
            <div class="d-flex align-center gap-3 w-100 flex-wrap">
              <span class="font-weight-medium">{{ c.sales_order }}</span>
              <span>{{ c.customer }}</span>
              <span class="text-medium-emphasis">{{ c.item }} × {{ num(c.ordered) }}</span>
              <VSpacer />
              <span class="text-caption">{{ t('manufacturing.fulfilment.ready_of', { ready: num(c.ready), owed: num(c.outstanding) }) }}</span>
              <span class="text-caption">{{ t('manufacturing.fulfilment.required') }} {{ c.required_date ?? '—' }}</span>
              <VChip size="small" label :color="fulfilmentFlagColor(c.flag)">{{ t(`manufacturing.fulfilment.flag.${c.flag}`) }}</VChip>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <VRow dense class="mb-2">
              <VCol cols="6" md="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.delivered') }}</div>{{ num(c.delivered) }}</VCol>
              <VCol cols="6" md="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.ready') }}</div>{{ num(c.ready) }}</VCol>
              <VCol cols="6" md="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.in_production') }}</div>{{ num(c.in_production) }}</VCol>
              <VCol cols="6" md="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.promised') }}</div>{{ c.promised_date ?? '—' }}</VCol>
              <VCol cols="6" md="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.projected') }}</div>{{ c.projected_finish ?? '—' }}</VCol>
              <VCol cols="6" md="2"><div class="text-caption text-medium-emphasis">{{ t('manufacturing.fulfilment.strategy_label') }}</div>{{ t(`manufacturing.fulfilment.strategy.${c.strategy}`) }}</VCol>
            </VRow>
            <div v-if="c.configuration" class="text-body-2 mb-2"><span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.configuration') }}:</span> {{ c.configuration }}</div>
            <VAlert v-for="(r, i) in c.risks" :key="i" type="warning" variant="tonal" density="compact" class="mb-1">{{ r }}</VAlert>
            <div class="text-body-2 my-2">
              <span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.production_orders') }}:</span>
              <span v-if="!c.production_orders.length"> —</span>
              <a v-for="o in c.production_orders" :key="o.id" class="ms-2" :href="route('manufacturing.production.orders.show', o.id)">{{ o.number }} ({{ o.status }}, {{ num(o.completed) }}/{{ num(o.qty) }})</a>
            </div>
            <div class="text-body-2">
              <span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.deliveries') }}:</span>
              <span v-if="!c.references.deliveries.length"> —</span>
              <span v-for="d in c.references.deliveries" :key="d.number" class="ms-2">{{ d.number }} ({{ d.date }}, {{ num(d.qty) }})</span>
              · <span class="text-medium-emphasis">{{ t('manufacturing.fulfilment.invoice') }}:</span>
              {{ c.references.invoice ? `${c.references.invoice.number} (${c.references.invoice.status})` : '—' }}
            </div>
            <div v-if="can.commit && c.flag !== 'CANCELLED' && c.flag !== 'DELIVERED'" class="text-end mt-2">
              <VBtn size="small" variant="text" color="error" @click="cancel(c)">{{ t('manufacturing.fulfilment.cancel_commitment') }}</VBtn>
            </div>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
      <VCardText v-if="!commitments.length" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.fulfilment.no_commitments') }}</VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard v-if="line">
        <VCardItem>
          <VCardTitle>{{ t('manufacturing.fulfilment.commit_title', { order: line.sales_order }) }}</VCardTitle>
          <VCardSubtitle>{{ line.item }} — {{ line.item_name }} · {{ t('manufacturing.fulfilment.outstanding') }} {{ num(line.outstanding) }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="6"><VSelect v-model="form.factory_id" :items="factories" item-title="name" item-value="id" :label="t('manufacturing.fulfilment.factory')" :error-messages="form.errors.factory_id" /></VCol>
            <VCol cols="12" md="6"><VSelect v-model="form.warehouse_id" :items="warehouses" item-title="name" item-value="id" :label="t('manufacturing.fulfilment.warehouse')" :error-messages="form.errors.warehouse_id" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model="form.required_date" type="date" :label="t('manufacturing.fulfilment.required_date')" :error-messages="form.errors.required_date" /></VCol>
            <VCol v-if="line.strategy === 'MTS'" cols="12" md="6"><VCheckbox v-model="form.make" :label="t('manufacturing.fulfilment.make_anyway')" hide-details /></VCol>
            <VCol v-if="line.strategy === 'CTO'" cols="12"><VTextarea v-model="form.configuration" rows="2" :label="t('manufacturing.fulfilment.configuration')" :error-messages="form.errors.configuration" /></VCol>
          </VRow>
          <VProgressLinear v-if="quoting" indeterminate class="my-2" />
          <VAlert v-if="quote" type="info" variant="tonal" density="compact">
            <div>{{ t('manufacturing.fulfilment.quote_line', { make: num(quote.to_make), stock: num(quote.free_stock), days: quote.days, finish: quote.finish }) }}</div>
            <div class="text-caption">{{ t('manufacturing.fulfilment.quote_split', { material: quote.material_days, production: quote.production_days }) }}</div>
            <div v-for="s in quote.shortages" :key="s.item" class="text-caption">{{ t('manufacturing.fulfilment.quote_short', { item: s.item, short: num(s.short), days: s.lead_days ?? '?' }) }}</div>
            <div v-for="(w, i) in quote.warnings" :key="i" class="text-caption text-warning">{{ w }}</div>
          </VAlert>
          <div class="text-caption text-medium-emphasis mt-2">{{ t(`manufacturing.fulfilment.commit_explains.${line.strategy}`) }}</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.fulfilment.close') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="submit">{{ t('manufacturing.fulfilment.commit') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
