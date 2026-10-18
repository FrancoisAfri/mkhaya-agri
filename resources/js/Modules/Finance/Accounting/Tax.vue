<script lang="ts" setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Box { field: string; label: string; amount: number }
interface Code { id: number; code: string; name: string; rate: number; type: string; jurisdiction: string; account_id: number | null; is_active: boolean }
const props = defineProps<{
  vat201: { period: { from: string; to: string }; boxes: Box[]; output_tax: number; input_tax: number; net: number; is_payable: boolean; total_sales: number; total_purchases: number }
  range: { from: string; to: string }
  codes: Code[]; types: Record<string, string>; accounts: { id: number; label: string }[]
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const range = ref({ ...props.range })
function reload() { router.get(route('finance.accounting.tax'), { from: range.value.from, to: range.value.to }, { preserveState: false }) }

// Tax code editor
const dialog = ref(false)
const editingId = ref<number | null>(null)
const form = useForm<any>({ code: '', name: '', rate: 15, type: 'output', jurisdiction: 'ZA', account_id: null, is_active: true })
function openNew() { editingId.value = null; form.reset(); form.rate = 15; form.type = 'output'; form.jurisdiction = 'ZA'; form.is_active = true; dialog.value = true }
function openEdit(c: Code) { editingId.value = c.id; form.code = c.code; form.name = c.name; form.rate = c.rate; form.type = c.type; form.jurisdiction = c.jurisdiction; form.account_id = c.account_id; form.is_active = c.is_active; dialog.value = true }
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editingId.value) form.patch(route('finance.accounting.tax.codes.update', editingId.value), opts)
  else form.post(route('finance.accounting.tax.codes.store'), opts)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_tax') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.tax_vat') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.tax_sub') }}</div>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard border>
          <VCardTitle class="d-flex align-center flex-wrap gap-2">
            <VIcon icon="tabler-receipt-tax" size="20" class="me-1" />{{ t('acc.vat201_return') }}
            <VSpacer />
            <VTextField v-model="range.from" type="date" density="compact" variant="outlined" hide-details style="max-width: 160px" />
            <VTextField v-model="range.to" type="date" density="compact" variant="outlined" hide-details style="max-width: 160px" />
            <VBtn size="small" color="primary" variant="tonal" @click="reload">{{ t('acc.run') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VTable density="comfortable">
            <tbody>
              <tr v-for="b in vat201.boxes" :key="b.field">
                <td style="width: 60px" class="text-caption text-medium-emphasis">{{ t('acc.box') }} {{ b.field }}</td>
                <td>{{ b.label }}</td>
                <td class="text-right font-weight-medium">{{ money(b.amount) }}</td>
              </tr>
            </tbody>
          </VTable>
          <VDivider />
          <VCardText>
            <div class="d-flex justify-space-between align-center">
              <span class="text-medium-emphasis">{{ t('acc.net_label') }} {{ vat201.is_payable ? t('acc.payable_to_sars') : t('acc.refundable') }}</span>
              <span class="text-h6" :class="vat201.is_payable ? 'text-error' : 'text-success'">{{ money(Math.abs(vat201.net)) }}</span>
            </div>
            <div class="text-caption text-medium-emphasis mt-2">{{ t('acc.tax_context', { sales: money(vat201.total_sales), purchases: money(vat201.total_purchases) }) }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard border>
          <VCardTitle class="d-flex align-center">{{ t('acc.tax_codes') }}<VSpacer /><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.add') }}</VBtn></VCardTitle>
          <VDivider />
          <VTable density="compact">
            <thead><tr><th>{{ t('acc.code') }}</th><th>{{ t('acc.name') }}</th><th class="text-right">{{ t('acc.rate') }}</th><th>{{ t('acc.type') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="c in codes" :key="c.id">
                <td class="font-weight-medium">{{ c.code }}<VIcon v-if="!c.is_active" icon="tabler-eye-off" size="14" class="ms-1 text-disabled" /></td>
                <td class="text-caption">{{ c.name }}</td>
                <td class="text-right">{{ c.rate }}%</td>
                <td class="text-caption">{{ (types[c.type] || c.type).split(' ')[0] }}</td>
                <td class="text-right"><VBtn size="x-small" variant="text" icon="tabler-edit" @click="openEdit(c)" /></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle>{{ editingId ? t('acc.edit_tax_code') : t('acc.new_tax_code') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.code" :label="t('acc.code')" variant="outlined" density="comfortable" style="max-width: 140px" :error-messages="form.errors.code" />
            <VTextField v-model="form.name" :label="t('acc.name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="form.rate" type="number" :label="t('acc.rate_pct')" suffix="%" variant="outlined" density="comfortable" style="max-width: 130px" />
            <VSelect v-model="form.type" :items="Object.entries(types).map(([v, ty]) => ({ title: ty, value: v }))" :label="t('acc.type')" variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.jurisdiction" :label="t('acc.jurisdiction')" variant="outlined" density="comfortable" style="max-width: 140px" />
            <VSelect v-model="form.account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.control_account')" clearable variant="outlined" density="comfortable" />
          </div>
          <VSwitch v-model="form.is_active" :label="t('acc.active')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.code || !form.name" @click="save">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
