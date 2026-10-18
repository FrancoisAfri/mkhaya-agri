<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Line { account_id: number | null; item_id: number | null; description: string; quantity: number; unit_price: number | null; tax_rate: number }
interface Schedule { id: number; name: string | null; party: string; party_id: number; frequency: string; interval: number; start_date: string; next_run_date: string; end_date: string | null; status: string; auto_email: boolean; description: string | null; generated_count: number; total: number; lines: Line[] }
interface Option { id: number; label: string }
interface Item { id: number; code: string; name: string; description: string | null; price: number; tax_rate: number; account_id: number | null }
const props = defineProps<{ schedules: Schedule[]; customers: { id: number; name: string }[]; incomeAccounts: Option[]; items: Item[] }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Filters the schedules already on the page — name, customer, frequency, status, dates. */
const search = ref('')
const visibleSchedules = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.schedules

  return props.schedules.filter(s =>
    [s.name, s.party, s.frequency, s.status, s.next_run_date, s.end_date, s.description]
      .some(v => (v ?? '').toString().toLowerCase().includes(term)))
})

const statusColor: Record<string, string> = { active: 'success', paused: 'warning', ended: 'default' }
const freqLabel = (f: string, i: number) => t('acc.freq_' + f) + (i > 1 ? ` ×${i}` : '')

const dialog = ref(false)
const editingId = ref<number | null>(null)
const confirm = ref()
const blankLine = (): Line => ({ account_id: props.incomeAccounts[0]?.id ?? null, item_id: null, description: '', quantity: 1, unit_price: null, tax_rate: 15 })
const form = useForm<any>({ party_id: null, name: '', frequency: 'monthly', interval: 1, start_date: new Date().toISOString().slice(0, 10), end_date: '', auto_email: false, description: '', lines: [blankLine()] })

const total = computed(() => form.lines.reduce((s: number, l: Line) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * (1 + (Number(l.tax_rate) || 0) / 100), 0))

function applyItem(l: Line) {
  const it = props.items.find(i => i.id === l.item_id)
  if (!it) return
  l.description = it.name + (it.description ? ` — ${it.description}` : '')
  l.unit_price = it.price; l.tax_rate = it.tax_rate
  if (it.account_id) l.account_id = it.account_id
}
function openNew() { editingId.value = null; form.reset(); form.start_date = new Date().toISOString().slice(0, 10); form.lines = [blankLine()]; dialog.value = true }
function openEdit(s: Schedule) {
  editingId.value = s.id
  Object.assign(form, { party_id: s.party_id, name: s.name ?? '', frequency: s.frequency, interval: s.interval, start_date: s.start_date, end_date: s.end_date ?? '', auto_email: s.auto_email, description: s.description ?? '' })
  form.lines = s.lines.length ? s.lines.map(l => ({ ...l })) : [blankLine()]
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editingId.value ? form.patch(route('finance.accounting.recurring.update', editingId.value), opts) : form.post(route('finance.accounting.recurring.store'), opts)
}
function setStatus(s: Schedule, status: string) { useForm({ status }).post(route('finance.accounting.recurring.status', s.id), { preserveScroll: true }) }
function runNow(s: Schedule) {
  confirm.value.open(
    { title: t('acc.generate_now'), message: t('acc.generate_now_confirm', { name: s.name || s.party }), confirmText: t('acc.generate'), confirmColor: 'primary', icon: 'tabler-repeat' },
    () => useForm({}).post(route('finance.accounting.recurring.run', s.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.recurring_invoices') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.recurring_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_schedule') }}</VBtn>
    </div>

    <VCard border>
      <VCardText class="pb-0">
        <VTextField
          v-model="search"
          :placeholder="t('acc.search')"
          prepend-inner-icon="tabler-search"
          density="compact" variant="outlined" hide-details clearable single-line
          style="max-width: 340px"
        />
      </VCardText>
      <VTable>
        <thead><tr><th>{{ t('acc.name') }}</th><th>{{ t('acc.customer') }}</th><th>{{ t('acc.frequency') }}</th><th>{{ t('acc.next_run') }}</th><th>{{ t('acc.ends') }}</th><th class="text-right">{{ t('acc.amount') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="s in visibleSchedules" :key="s.id">
            <td class="font-weight-medium">{{ s.name || '—' }}<VChip v-if="s.auto_email" size="x-small" variant="tonal" class="ms-1" prepend-icon="tabler-mail">{{ t('acc.auto') }}</VChip></td>
            <td>{{ s.party }}</td>
            <td class="text-caption">{{ freqLabel(s.frequency, s.interval) }}</td>
            <td class="text-caption">{{ s.next_run_date }}</td>
            <td class="text-caption">{{ s.end_date || '—' }}</td>
            <td class="text-right">{{ money(s.total) }}</td>
            <td><VChip size="x-small" :color="statusColor[s.status]" variant="tonal" class="text-capitalize">{{ s.status }}</VChip><span v-if="s.generated_count" class="text-caption text-medium-emphasis ms-1">· {{ s.generated_count }}</span></td>
            <td class="text-right text-no-wrap">
              <VBtn icon="tabler-pencil" size="small" variant="text" :title="t('acc.edit')" @click="openEdit(s)" />
              <VBtn v-if="s.status === 'active'" size="x-small" variant="tonal" color="primary" class="ms-1" @click="runNow(s)">{{ t('acc.run_now') }}</VBtn>
              <VBtn v-if="s.status === 'active'" size="x-small" variant="text" color="warning" class="ms-1" @click="setStatus(s, 'paused')">{{ t('acc.pause') }}</VBtn>
              <VBtn v-else-if="s.status === 'paused'" size="x-small" variant="text" color="success" class="ms-1" @click="setStatus(s, 'active')">{{ t('acc.resume') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!visibleSchedules.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ t('acc.no_recurring') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="1100" persistent>
      <VCard>
        <VCardTitle>{{ editingId ? t('acc.edit_recurring') : t('acc.new_recurring') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VAutocomplete v-model="form.party_id" :items="customers.map(c => ({ title: c.name, value: c.id }))" :label="t('acc.customer')" variant="outlined" density="comfortable" style="min-width: 220px" :error-messages="(form.errors as any).party_id" />
            <VTextField v-model="form.name" :label="t('acc.name_retainer_ph')" variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 mb-3 flex-wrap align-center">
            <VSelect v-model="form.frequency" :items="[{ title: t('acc.freq_weekly'), value: 'weekly' }, { title: t('acc.freq_monthly'), value: 'monthly' }, { title: t('acc.freq_quarterly'), value: 'quarterly' }, { title: t('acc.freq_yearly'), value: 'yearly' }]" :label="t('acc.frequency')" variant="outlined" density="comfortable" style="max-width: 160px" />
            <VTextField v-model.number="form.interval" type="number" :label="t('acc.every_n')" variant="outlined" density="comfortable" style="max-width: 110px" />
            <VTextField v-model="form.start_date" type="date" :label="t('acc.start')" variant="outlined" density="comfortable" style="max-width: 170px" />
            <VTextField v-model="form.end_date" type="date" :label="t('acc.end_optional')" variant="outlined" density="comfortable" style="max-width: 180px" />
            <VSwitch v-model="form.auto_email" :label="t('acc.auto_email_each')" color="primary" hide-details />
          </div>
          <VTextField v-model="form.description" :label="t('acc.description_each')" variant="outlined" density="comfortable" class="mb-3" />

          <VTable density="compact">
            <thead><tr><th style="width: 24%">{{ t('acc.item') }}</th><th style="width: 20%">{{ t('acc.income_account_label') }}</th><th>{{ t('acc.description') }}</th><th class="text-right" style="width: 80px">{{ t('acc.qty') }}</th><th class="text-right" style="width: 120px">{{ t('acc.price') }}</th><th class="text-right" style="width: 80px">{{ t('acc.tax_pct') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in form.lines" :key="i">
                <td class="pa-1"><VAutocomplete v-model="l.item_id" :items="items.map(it => ({ title: `${it.code} · ${it.name}`, value: it.id }))" density="compact" variant="outlined" hide-details clearable :placeholder="t('acc.search_item')" @update:model-value="applyItem(l)" /></td>
                <td class="pa-1"><VAutocomplete v-model="l.account_id" :items="incomeAccounts.map(a => ({ title: a.label, value: a.id }))" density="compact" variant="outlined" hide-details /></td>
                <td class="pa-1"><VTextField v-model="l.description" density="compact" variant="outlined" hide-details /></td>
                <td class="pa-1"><VTextField v-model.number="l.quantity" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                <td class="pa-1"><VTextField v-model.number="l.unit_price" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                <td class="pa-1"><VTextField v-model.number="l.tax_rate" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                <td class="pa-1"><VBtn icon="tabler-x" size="x-small" variant="text" :disabled="form.lines.length <= 1" @click="form.lines.splice(i, 1)" /></td>
              </tr>
            </tbody>
            <tfoot><tr class="font-weight-bold"><td colspan="6" class="text-right">{{ t('acc.total_per_invoice') }}</td><td class="text-right">{{ money(total) }}</td></tr></tfoot>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-2" @click="form.lines.push(blankLine())">{{ t('acc.add_line') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.party_id || total <= 0" @click="save">{{ editingId ? t('acc.save') : t('acc.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
