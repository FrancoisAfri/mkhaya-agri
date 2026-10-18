<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Asset { id: number; tag: string | null; name: string; category: string | null; acquisition_date: string | null; cost: number; accumulated_depreciation: number; book_value: number; method: string; status: string; residual_value: number; useful_life_months: number; annual_rate: number | null; parent_id: number | null; disposal_date: string | null; disposal_proceeds: number | null }
const props = defineProps<{
  assets: Asset[]; methods: Record<string, string>; summary: { count: number; cost: number; accum: number; book_value: number }
  accounts: { id: number; code: string; label: string }[]; parents: { id: number; name: string }[]
}>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { active: 'success', disposed: 'default', written_off: 'error' }

// Register
const dialog = ref(false)
const form = useForm<any>({
  name: '', tag: '', category: '', parent_id: null, acquisition_date: new Date().toISOString().slice(0, 10),
  cost: null, residual_value: 0, method: 'straight_line', useful_life_months: 60, annual_rate: null,
  asset_account_id: null, accum_account_id: null, expense_account_id: null, post_acquisition: false, funding_account: '',
})
const showAdvanced = ref(false)
function openNew() { form.reset(); form.acquisition_date = new Date().toISOString().slice(0, 10); form.residual_value = 0; form.method = 'straight_line'; form.useful_life_months = 60; showAdvanced.value = false; dialog.value = true }
function save() { form.post(route('finance.accounting.assets.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } }) }

// Depreciation run
const depDialog = ref(false)
const depForm = useForm<any>({ period: new Date().toISOString().slice(0, 10) })
function runDepreciation() { depForm.post(route('finance.accounting.assets.depreciate'), { preserveScroll: true, onSuccess: () => { depDialog.value = false } }) }

// Disposal
const dispDialog = ref(false)
const dispAsset = ref<Asset | null>(null)
const dispForm = useForm<any>({ disposal_date: new Date().toISOString().slice(0, 10), proceeds: null, proceeds_account: '' })
function openDispose(a: Asset) { dispAsset.value = a; dispForm.reset(); dispForm.disposal_date = new Date().toISOString().slice(0, 10); dispDialog.value = true }
function submitDispose() { dispForm.post(route('finance.accounting.assets.dispose', dispAsset.value!.id), { preserveScroll: true, onSuccess: () => { dispDialog.value = false } }) }
const gainLoss = computed(() => dispAsset.value && dispForm.proceeds != null ? Number(dispForm.proceeds) - dispAsset.value.book_value : null)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_fixed_assets') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.fixed_asset_register') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.fixed_assets_sub') }}</div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-calendar-down" @click="depDialog = true">{{ t('acc.run_depreciation') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.register_asset') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.active_assets') }}</div><div class="text-h6">{{ summary.count }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.cost') }}</div><div class="text-h6">{{ money(summary.cost) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.accum_depreciation') }}</div><div class="text-h6 text-error">{{ money(summary.accum) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.net_book_value') }}</div><div class="text-h6 text-success">{{ money(summary.book_value) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VTable density="compact">
        <thead><tr><th>{{ t('acc.tag') }}</th><th>{{ t('acc.asset_word') }}</th><th>{{ t('acc.category') }}</th><th>{{ t('acc.acquired') }}</th><th class="text-right">{{ t('acc.cost') }}</th><th class="text-right">{{ t('acc.accum_depreciation') }}</th><th class="text-right">{{ t('acc.book_value') }}</th><th>{{ t('acc.method') }}</th><th>{{ t('acc.status') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-if="!assets.length"><td colspan="10" class="text-center text-medium-emphasis py-8">{{ t('acc.no_assets') }}</td></tr>
          <tr v-for="a in assets" :key="a.id">
            <td class="text-caption">{{ a.tag || '—' }}</td>
            <td class="font-weight-medium"><span v-if="a.parent_id" class="text-medium-emphasis">↳ </span>{{ a.name }}</td>
            <td class="text-caption">{{ a.category || '—' }}</td>
            <td class="text-caption text-medium-emphasis">{{ a.acquisition_date }}</td>
            <td class="text-right">{{ money(a.cost) }}</td>
            <td class="text-right text-error">{{ money(a.accumulated_depreciation) }}</td>
            <td class="text-right font-weight-medium">{{ money(a.book_value) }}</td>
            <td class="text-caption">{{ methods[a.method] ?? a.method }}<span v-if="a.method === 'reducing_balance'"> ({{ a.annual_rate }}%)</span></td>
            <td><VChip size="x-small" :color="statusColor[a.status]" variant="tonal" class="text-capitalize">{{ a.status.replace('_', ' ') }}</VChip></td>
            <td class="text-right"><VBtn v-if="a.status === 'active'" size="x-small" variant="tonal" color="warning" @click="openDispose(a)">{{ t('acc.dispose') }}</VBtn></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Register dialog -->
    <VDialog v-model="dialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('acc.register_asset') }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(form.errors as any).asset" type="error" variant="tonal" density="compact" class="mb-3">{{ (form.errors as any).asset }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.name" :label="t('acc.asset_name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
            <VTextField v-model="form.tag" :label="t('acc.tag_barcode')" variant="outlined" density="comfortable" style="max-width: 180px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.category" :label="t('acc.category')" variant="outlined" density="comfortable" />
            <VSelect v-model="form.parent_id" :items="parents.map(p => ({ title: p.name, value: p.id }))" :label="t('acc.component_of')" clearable variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.acquisition_date" type="date" :label="t('acc.acquisition_date')" variant="outlined" density="comfortable" />
            <VTextField v-model.number="form.cost" type="number" :label="t('acc.cost')" prefix="R" variant="outlined" density="comfortable" :error-messages="form.errors.cost" />
            <VTextField v-model.number="form.residual_value" type="number" :label="t('acc.residual')" prefix="R" variant="outlined" density="comfortable" style="max-width: 140px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="form.method" :items="Object.entries(methods).map(([v, lbl]) => ({ title: lbl, value: v }))" :label="t('acc.method')" variant="outlined" density="comfortable" />
            <VTextField v-if="form.method === 'straight_line'" v-model.number="form.useful_life_months" type="number" :label="t('acc.useful_life_months')" variant="outlined" density="comfortable" :error-messages="form.errors.useful_life_months" />
            <VTextField v-else v-model.number="form.annual_rate" type="number" :label="t('acc.annual_rate_pct')" suffix="%" variant="outlined" density="comfortable" :error-messages="form.errors.annual_rate" />
          </div>

          <VSwitch v-model="form.post_acquisition" :label="t('acc.post_acquisition_now')" color="primary" density="compact" hide-details class="mb-1" />
          <VSelect v-if="form.post_acquisition" v-model="form.funding_account" :items="accounts.map(a => ({ title: a.label, value: a.code }))" :label="t('acc.funded_from')" variant="outlined" density="comfortable" class="mb-2" />

          <VExpandTransition>
            <div v-show="showAdvanced">
              <div class="text-caption text-medium-emphasis mb-2">{{ t('acc.override_gl_accounts') }}</div>
              <VSelect v-model="form.asset_account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.cost_account')" clearable variant="outlined" density="compact" class="mb-2" />
              <VSelect v-model="form.accum_account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.accumulated_depreciation_label')" clearable variant="outlined" density="compact" class="mb-2" />
              <VSelect v-model="form.expense_account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.depreciation_expense')" clearable variant="outlined" density="compact" />
            </div>
          </VExpandTransition>
          <VBtn size="small" variant="text" @click="showAdvanced = !showAdvanced">{{ showAdvanced ? t('acc.hide') : t('acc.advanced_gl') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name || !form.cost" @click="save">{{ t('acc.register') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Depreciation run dialog -->
    <VDialog v-model="depDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ t('acc.run_depreciation') }}</VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3">{{ t('acc.dep_run_sub') }}</div>
          <VTextField v-model="depForm.period" type="date" :label="t('acc.depreciate_up_to')" variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="depDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="depForm.processing" @click="runDepreciation">{{ t('acc.run') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Disposal dialog -->
    <VDialog v-model="dispDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.dispose_asset') }}</VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3">{{ dispAsset?.name }} — {{ t('acc.net_book_value_inline') }} <strong>{{ money(dispAsset?.book_value || 0) }}</strong></div>
          <VTextField v-model="dispForm.disposal_date" type="date" :label="t('acc.disposal_date')" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model.number="dispForm.proceeds" type="number" :label="t('acc.proceeds')" prefix="R" variant="outlined" density="comfortable" class="mb-2" :error-messages="dispForm.errors.proceeds" />
          <VSelect v-model="dispForm.proceeds_account" :items="accounts.map(a => ({ title: a.label, value: a.code }))" :label="t('acc.proceeds_received_into')" clearable variant="outlined" density="comfortable" />
          <VAlert v-if="gainLoss !== null" :type="gainLoss >= 0 ? 'success' : 'warning'" variant="tonal" density="compact" class="mt-2">
            {{ gainLoss >= 0 ? t('acc.gain') : t('acc.loss') }} {{ t('acc.on_disposal') }} <strong>{{ money(Math.abs(gainLoss)) }}</strong>
          </VAlert>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dispDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="warning" :loading="dispForm.processing" :disabled="dispForm.proceeds == null" @click="submitDispose">{{ t('acc.dispose') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
