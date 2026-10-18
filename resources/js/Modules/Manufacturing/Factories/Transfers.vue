<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; number: string; status: string; from: string | null; to: string | null; items: string; needed_by: string | null; shipped_at: string | null; received_at: string | null }
type Option = { id: number; name: string }

const props = defineProps<{
  transfers: Row[]
  balances: { rows: Array<{ item: string; factory: string; need: number; available: number; balance: number }>
    suggestions: Array<{ item: string; stock_item_id: number; from: string; from_id: number; to: string; to_id: number; qty: number }> }
  factories: Option[]
  items: Option[]
  filters: { state: string }
  can: { manage: boolean }
}>()

const state = ref(props.filters.state)
watch(state, v => router.get(route('manufacturing.multi.transfers.index'), { state: v }, { preserveState: true, replace: true }))
const dialog = ref(false)
const form = useForm({ from_factory_id: null as number | null, to_factory_id: null as number | null, needed_by: '', reason: '', lines: [{ stock_item_id: null as number | null, qty: null as number | null }] })
const fromSuggestion = (s: Props['balances']['suggestions'][number]) => {
  form.from_factory_id = s.from_id; form.to_factory_id = s.to_id
  form.lines = [{ stock_item_id: s.stock_item_id, qty: s.qty }]; form.reason = t('manufacturing.multi.suggested_reason'); dialog.value = true
}
type Props = typeof props
const submit = () => form.post(route('manufacturing.multi.transfers.store'), { onSuccess: () => { dialog.value = false } })
const statusColor = (s: string) => ({ REQUESTED: 'warning', APPROVED: 'info', SHIPPED: 'primary', RECEIVED: 'success', CANCELLED: 'grey' } as Record<string, string>)[s] ?? 'grey'
const num = (n: number) => Number(n).toLocaleString(undefined, { maximumFractionDigits: 4 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.multi.transfers_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.multi.transfers_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtnToggle v-model="state" density="compact" variant="outlined" mandatory>
          <VBtn value="open">{{ t('manufacturing.multi.open') }}</VBtn><VBtn value="all">{{ t('manufacturing.multi.all') }}</VBtn>
        </VBtnToggle>
        <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="form.reset(); dialog = true">{{ t('manufacturing.multi.new_transfer') }}</VBtn>
      </div>
    </div>

    <VCard v-if="balances.suggestions.length" border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.multi.suggestions') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.multi.suggestions_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <tbody>
          <tr v-for="(s, i) in balances.suggestions" :key="i">
            <td>{{ t('manufacturing.multi.suggestion', { qty: num(s.qty), item: s.item, from: s.from, to: s.to }) }}</td>
            <td class="text-end"><VBtn v-if="can.manage" size="small" variant="tonal" @click="fromSuggestion(s)">{{ t('manufacturing.multi.request') }}</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard border class="mb-4">
      <VTable>
        <thead><tr><th>{{ t('manufacturing.multi.transfer') }}</th><th>{{ t('manufacturing.multi.from') }}</th><th>{{ t('manufacturing.multi.to') }}</th>
          <th>{{ t('manufacturing.multi.items') }}</th><th>{{ t('manufacturing.multi.needed_by') }}</th><th>{{ t('manufacturing.multi.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in transfers" :key="r.id" class="cursor-pointer" @click="router.visit(route('manufacturing.multi.transfers.show', r.id))">
            <td class="font-weight-medium">{{ r.number }}</td><td>{{ r.from }}</td><td>{{ r.to }}</td><td class="text-caption">{{ r.items }}</td>
            <td>{{ r.needed_by ?? '—' }}</td><td><VChip size="small" label :color="statusColor(r.status)">{{ t(`manufacturing.multi.statuses.${r.status}`) }}</VChip></td>
          </tr>
          <tr v-if="!transfers.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.multi.no_transfers') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard border>
      <VCardItem><VCardTitle>{{ t('manufacturing.multi.balances') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.multi.balances_hint') }}</VCardSubtitle></VCardItem>
      <VTable density="compact">
        <thead><tr><th>{{ t('manufacturing.multi.item') }}</th><th>{{ t('manufacturing.multi.factory') }}</th><th class="text-end">{{ t('manufacturing.multi.need') }}</th>
          <th class="text-end">{{ t('manufacturing.multi.available') }}</th><th class="text-end">{{ t('manufacturing.multi.balance') }}</th></tr></thead>
        <tbody>
          <tr v-for="(b, i) in balances.rows" :key="i"><td>{{ b.item }}</td><td>{{ b.factory }}</td><td class="text-end">{{ num(b.need) }}</td><td class="text-end">{{ num(b.available) }}</td>
            <td class="text-end" :class="b.balance < 0 ? 'text-error' : ''">{{ num(b.balance) }}</td></tr>
          <tr v-if="!balances.rows.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('manufacturing.multi.no_need') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardItem><VCardTitle>{{ t('manufacturing.multi.new_transfer') }}</VCardTitle></VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VSelect v-model="form.from_factory_id" :items="factories" item-title="name" item-value="id" :label="t('manufacturing.multi.from')" :error-messages="form.errors.from_factory_id" /></VCol>
            <VCol cols="6"><VSelect v-model="form.to_factory_id" :items="factories" item-title="name" item-value="id" :label="t('manufacturing.multi.to')" :error-messages="form.errors.to_factory_id" /></VCol>
            <template v-for="(l, i) in form.lines" :key="i">
              <VCol cols="8"><VAutocomplete v-model="l.stock_item_id" :items="items" item-title="name" item-value="id" :label="t('manufacturing.multi.item')" /></VCol>
              <VCol cols="4"><VTextField v-model.number="l.qty" type="number" :label="t('manufacturing.multi.qty')" /></VCol>
            </template>
            <VCol cols="12"><VBtn size="small" variant="text" @click="form.lines.push({ stock_item_id: null, qty: null })">{{ t('manufacturing.multi.add_line') }}</VBtn></VCol>
            <VCol cols="6"><VTextField v-model="form.needed_by" type="date" :label="t('manufacturing.multi.needed_by')" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.reason" rows="2" :label="t('manufacturing.multi.reason')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('manufacturing.multi.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="submit">{{ t('manufacturing.multi.request') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
