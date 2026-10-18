<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, Link, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Box { field: string; label: string; amount: number }
interface Err { entity: string; id: number | null; field: string; message: string }
interface Vat201 {
  period: { from: string; to: string; label: string }
  header: { company_name: string; vat_number: string | null; vat_registered: boolean; vat_frequency: string | null; contact_person: string | null; contact_email: string | null }
  boxes: Box[]
  totals: { output_tax: number; input_tax: number; net: number; is_payable: boolean; total_sales: number; total_purchases: number }
  validation: { validated: number; errors: Err[] }
}
interface Recent { id: number; reference: string; period: string; status: string; version: number; net: number | null; is_payable: boolean | null; generated_at: string | null }

const props = defineProps<{
  vat: Vat201
  selected: { from: string; to: string }
  frequency: string | null
  configured: boolean
  recent: Recent[]
  statuses: string[]
}>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const from = ref(props.selected.from)
const to = ref(props.selected.to)
const errors = computed(() => props.vat.validation.errors)
const clean = computed(() => errors.value.length === 0)
const statusColor: Record<string, string> = { draft: 'grey', generated: 'info', validated: 'primary', submitted: 'warning', accepted: 'success', rejected: 'error' }

function reload() {
  router.get(route('finance.accounting.sars.vat201'), { from: from.value, to: to.value }, { preserveState: true, preserveScroll: true, replace: true })
}

const genForm = useForm<{ from: string; to: string; notes: string }>({ from: props.selected.from, to: props.selected.to, notes: '' })
function generate() {
  genForm.from = from.value; genForm.to = to.value
  genForm.post(route('finance.accounting.sars.vat201.generate'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width: 1100px; margin: 0 auto">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('sars.finance_sars') }}</div>
        <h1 class="text-h5 font-weight-bold mb-0">{{ t('sars.vat201_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sars.vat201_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <Link :href="route('finance.accounting.sars.config')"><VBtn variant="text" prepend-icon="tabler-shield-check">{{ t('sars.config_title') }}</VBtn></Link>
        <Link :href="route('finance.accounting.sars.submissions')"><VBtn variant="tonal" prepend-icon="tabler-file-certificate">{{ t('sars.submissions') }}</VBtn></Link>
      </div>
    </div>

    <VAlert v-if="!configured" type="warning" variant="tonal" density="comfortable" class="mb-4">
      {{ t('sars.vat201_not_configured') }}
      <Link :href="route('finance.accounting.sars.config')" class="font-weight-medium">{{ t('sars.config_title') }}</Link>
    </VAlert>

    <!-- Period selector -->
    <VCard border rounded="lg" class="mb-4">
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VTextField v-model="from" type="date" :label="t('sars.period_from')" density="compact" variant="outlined" hide-details style="max-width: 190px" @update:model-value="reload" />
        <VTextField v-model="to" type="date" :label="t('sars.period_to')" density="compact" variant="outlined" hide-details style="max-width: 190px" @update:model-value="reload" />
        <VChip size="small" variant="tonal" color="primary">{{ vat.period.label }}</VChip>
        <VSpacer />
        <span class="text-caption text-medium-emphasis">{{ t('sars.freq_' + (frequency || 'monthly')) }}</span>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Boxes -->
      <VCol cols="12" md="7">
        <VCard border rounded="lg" class="h-100">
          <VCardText>
            <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('sars.vat201_return') }}</div>
            <VTable density="comfortable">
              <thead><tr><th style="width:70px">{{ t('sars.box') }}</th><th>{{ t('sars.description') }}</th><th class="text-right">{{ t('sars.amount_r') }}</th></tr></thead>
              <tbody>
                <tr v-for="b in vat.boxes" :key="b.field">
                  <td><VChip size="x-small" variant="tonal" color="primary">{{ b.field }}</VChip></td>
                  <td>{{ b.label }}</td>
                  <td class="text-right" style="font-variant-numeric: tabular-nums">{{ money(b.amount) }}</td>
                </tr>
              </tbody>
            </VTable>

            <VDivider class="my-3" />
            <div class="d-flex justify-space-between align-center px-2">
              <div>
                <div class="text-caption text-medium-emphasis">{{ vat.totals.is_payable ? t('sars.payable_sars') : t('sars.refundable_sars') }}</div>
                <div class="text-h5 font-weight-bold" :class="vat.totals.is_payable ? 'text-error' : 'text-success'" style="font-variant-numeric: tabular-nums">R {{ money(Math.abs(vat.totals.net)) }}</div>
              </div>
              <div class="text-right text-caption text-medium-emphasis" style="font-variant-numeric: tabular-nums">
                <div>{{ t('sars.output_tax') }}: {{ money(vat.totals.output_tax) }}</div>
                <div>{{ t('sars.input_tax') }}: −{{ money(vat.totals.input_tax) }}</div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Validation + generate -->
      <VCol cols="12" md="5">
        <VCard border rounded="lg" class="mb-4">
          <VCardText>
            <div class="d-flex align-center gap-2 mb-2">
              <VIcon :color="clean ? 'success' : 'warning'">{{ clean ? 'tabler-circle-check' : 'tabler-alert-triangle' }}</VIcon>
              <span class="text-subtitle-1 font-weight-medium">{{ t('sars.validation') }}</span>
            </div>
            <p class="text-body-2 mb-2">
              <strong>{{ vat.validation.validated }}</strong> {{ t('sars.n_checks') }} ·
              <span :class="clean ? 'text-success' : 'text-error'">{{ clean ? t('sars.all_valid') : t('sars.n_errors', { n: errors.length }) }}</span>
            </p>
            <VList v-if="errors.length" density="compact" class="py-0">
              <VListItem v-for="(e, i) in errors" :key="i" class="px-0">
                <template #prepend><VIcon size="16" color="error" class="me-2">tabler-point-filled</VIcon></template>
                <VListItemTitle class="text-caption text-wrap">{{ e.message }}</VListItemTitle>
              </VListItem>
            </VList>
            <div v-else class="text-caption text-success">{{ t('sars.vat201_ready') }}</div>
          </VCardText>
        </VCard>

        <VCard border rounded="lg">
          <VCardText>
            <div class="text-subtitle-1 font-weight-medium mb-2">{{ t('sars.generate') }}</div>
            <p class="text-caption text-medium-emphasis mb-3">{{ t('sars.vat201_generate_help') }}</p>
            <VTextarea v-model="genForm.notes" :label="t('sars.notes')" variant="outlined" density="compact" rows="2" hide-details class="mb-3" />
            <VBtn color="primary" block prepend-icon="tabler-file-download" :loading="genForm.processing" @click="generate">{{ t('sars.generate_vat201') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent VAT201s -->
    <VCard v-if="recent.length" border rounded="lg" class="mt-4">
      <VCardText class="pb-0"><div class="text-subtitle-1 font-weight-medium">{{ t('sars.recent_vat201') }}</div></VCardText>
      <VTable>
        <thead><tr><th>{{ t('sars.reference') }}</th><th>{{ t('sars.period') }}</th><th class="text-right">{{ t('sars.version') }}</th><th>{{ t('sars.status') }}</th><th class="text-right">{{ t('sars.net') }}</th><th>{{ t('sars.generated') }}</th></tr></thead>
        <tbody>
          <tr v-for="s in recent" :key="s.id" style="cursor:pointer" @click="router.get(route('finance.accounting.sars.submissions.show', s.id))">
            <td class="font-weight-medium">{{ s.reference }}</td>
            <td>{{ s.period }}</td>
            <td class="text-right">v{{ s.version }}</td>
            <td><VChip size="small" :color="statusColor[s.status]" variant="tonal">{{ t('sars.st_' + s.status) }}</VChip></td>
            <td class="text-right" style="font-variant-numeric: tabular-nums">{{ s.net != null ? (s.is_payable ? '' : '−') + money(Math.abs(s.net)) : '—' }}</td>
            <td class="text-caption">{{ s.generated_at ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
