<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Rate { id: number; base_currency: string; quote_currency: string; rate: number; rate_type: string; source: string | null; effective_date: string }
const props = defineProps<{ rates: Rate[]; base_currency: string }>()

const dialog = ref(false)
const today = new Date().toISOString().slice(0, 10)
const form = useForm({ base_currency: '', quote_currency: props.base_currency, rate: null as number | null, rate_type: 'spot', source: '', effective_date: today })

function openNew() { form.reset(); form.quote_currency = props.base_currency; form.rate_type = 'spot'; form.effective_date = today; dialog.value = true }
function save() {
  form.transform(d => ({ ...d, base_currency: (d.base_currency || '').toUpperCase(), quote_currency: (d.quote_currency || '').toUpperCase() }))
    .post(route('finance.accounting.exchange-rates.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.gl_crumb') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.exchange_rates') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.base_currency_label') }}: <strong>{{ base_currency }}</strong> — {{ t('acc.exchange_rates_sub2') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_rate') }}</VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ t('acc.effective') }}</th><th>{{ t('acc.from') }}</th><th>{{ t('acc.to') }}</th><th class="text-right">{{ t('acc.rate') }}</th><th>{{ t('acc.type') }}</th><th>{{ t('acc.source') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in rates" :key="r.id">
            <td>{{ r.effective_date }}</td>
            <td class="font-weight-medium">{{ r.base_currency }}</td>
            <td>{{ r.quote_currency }}</td>
            <td class="text-right">{{ r.rate.toLocaleString(undefined, { maximumFractionDigits: 8 }) }}</td>
            <td class="text-capitalize"><VChip size="x-small" variant="tonal">{{ r.rate_type }}</VChip></td>
            <td class="text-caption text-medium-emphasis">{{ r.source || '—' }}</td>
          </tr>
          <tr v-if="!rates.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('acc.no_rates') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('acc.new_exchange_rate') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.base_currency" :label="t('acc.from_iso')" maxlength="3" variant="outlined" density="comfortable" style="text-transform: uppercase" :error-messages="form.errors.base_currency" />
            <VTextField v-model="form.quote_currency" :label="t('acc.to_iso')" maxlength="3" variant="outlined" density="comfortable" style="text-transform: uppercase" :error-messages="form.errors.quote_currency" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="form.rate" type="number" step="0.00000001" :label="t('acc.rate')" variant="outlined" density="comfortable" :error-messages="form.errors.rate" :hint="t('acc.rate_hint')" persistent-hint />
            <VSelect v-model="form.rate_type" :items="['spot', 'average', 'closing']" :label="t('acc.type')" variant="outlined" density="comfortable" class="text-capitalize" />
          </div>
          <div class="d-flex gap-3">
            <VTextField v-model="form.effective_date" type="date" :label="t('acc.effective_date')" variant="outlined" density="comfortable" :error-messages="form.errors.effective_date" />
            <VTextField v-model="form.source" :label="t('acc.source_optional')" variant="outlined" density="comfortable" />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.base_currency || !form.quote_currency || !form.rate" @click="save">{{ t('acc.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
