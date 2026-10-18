<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Entry = { id: number; period_sequence: number; period_year: number; period_month: number; opening_book_value: string; depreciation_amount: string; closing_book_value: string; is_posted: boolean; posted_at: string | null; postedBy: { name: string } | null }
type Schedule = { uuid: string; depreciation_method: { value: string; label: string }; useful_life_years: number; purchase_cost: string; salvage_value: string | null; sars_wear_rate: string | null; total_periods: number; generated_at: string; generatedBy: { name: string } | null; entries: Entry[] }

const props = defineProps<{ asset: { uuid: string; name: string; asset_tag: string | null; purchase_cost: number }; schedule: Schedule | null }>()

const showGenerateDialog = ref(false)
const confirmDialog = ref()
const generateForm = useForm({ depreciation_method: 'straight_line', useful_life_years: 5, purchase_cost: props.asset.purchase_cost ?? 0, salvage_value: null as number | null, sars_wear_rate: null as number | null })

const methodOptions = computed(() => [
  { title: t('assets_depreciation_show.methods.straight_line'), value: 'straight_line' },
  { title: t('assets_depreciation_show.methods.reducing_balance'), value: 'reducing_balance' },
  { title: t('assets_depreciation_show.methods.sars_wear_and_tear'), value: 'sars_wear_and_tear' },
  { title: t('assets_depreciation_show.methods.units_of_production'), value: 'units_of_production' },
])

function submitGenerate() { generateForm.post(route('assets.depreciation.store', props.asset.uuid), { onSuccess: () => { showGenerateDialog.value = false } }) }

function deleteSchedule() {
  if (!props.schedule) return
  confirmDialog.value.open(
    { title: t('assets_depreciation_show.confirm.delete_title'), message: t('assets_depreciation_show.confirm.delete_message'), confirmText: t('assets_depreciation_show.confirm.delete_text'), confirmColor: 'error', icon: 'tabler-trash' },
    () => router.delete(route('assets.depreciation.destroy', props.schedule!.uuid)),
  )
}

function postEntry(entry: Entry) {
  confirmDialog.value.open(
    { title: t('assets_depreciation_show.confirm.post_title'), message: t('assets_depreciation_show.confirm.post_message', { period: entry.period_sequence }), confirmText: t('assets_depreciation_show.confirm.post_text'), confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => router.post(route('assets.depreciation.entries.post', entry.id)),
  )
}

function formatCurrency(val: string | number | null) { if (val == null) return '—'; return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 2 }).format(Number(val)) }
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function monthName(m: number) { return new Date(2000, m - 1).toLocaleString('en-ZA', { month: 'short' }) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold">{{ asset.name }}</h4>
        <p class="text-medium-emphasis mb-0">{{ asset.asset_tag }} · {{ formatCurrency(asset.purchase_cost) }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn color="primary" prepend-icon="tabler-calendar-plus" @click="showGenerateDialog = true">
          {{ schedule ? t('assets_depreciation_show.regenerate') : t('assets_depreciation_show.generate_schedule') }}
        </VBtn>
        <VBtn v-if="schedule" variant="tonal" color="error" prepend-icon="tabler-trash" @click="deleteSchedule">{{ t('assets_depreciation_show.delete') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="!schedule" type="info" class="mb-6">{{ t('assets_depreciation_show.no_schedule_alert') }}</VAlert>

    <template v-if="schedule">
      <VCard elevation="0" border class="mb-6">
        <VCardText>
          <VRow>
            <VCol cols="6" sm="2"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_depreciation_show.schedule_info.method') }}</p><p class="text-body-1 font-weight-medium mb-0">{{ schedule.depreciation_method.label }}</p></VCol>
            <VCol cols="6" sm="2"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_depreciation_show.schedule_info.useful_life') }}</p><p class="text-body-1 font-weight-medium mb-0">{{ schedule.useful_life_years }}{{ t('assets_depreciation_show.schedule_info.yrs') }}</p></VCol>
            <VCol cols="6" sm="2"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_depreciation_show.schedule_info.purchase_cost') }}</p><p class="text-body-1 font-weight-medium mb-0">{{ formatCurrency(schedule.purchase_cost) }}</p></VCol>
            <VCol cols="6" sm="2"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_depreciation_show.schedule_info.salvage_value') }}</p><p class="text-body-1 font-weight-medium mb-0">{{ formatCurrency(schedule.salvage_value) }}</p></VCol>
            <VCol cols="6" sm="2"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_depreciation_show.schedule_info.total_periods') }}</p><p class="text-body-1 font-weight-medium mb-0">{{ schedule.total_periods }}</p></VCol>
            <VCol cols="6" sm="2"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_depreciation_show.schedule_info.generated') }}</p><p class="text-body-1 font-weight-medium mb-0">{{ formatDate(schedule.generated_at) }}</p></VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard elevation="0" border>
        <VCardText class="pa-4 pb-0"><p class="text-subtitle-1 font-weight-semibold">{{ t('assets_depreciation_show.entries.title', { count: schedule.entries.length }) }}</p></VCardText>
        <VTable density="compact" fixed-header height="500">
          <thead><tr><th>{{ t('assets_depreciation_show.entries.table.seq') }}</th><th>{{ t('assets_depreciation_show.entries.table.period') }}</th><th class="text-right">{{ t('assets_depreciation_show.entries.table.opening') }}</th><th class="text-right">{{ t('assets_depreciation_show.entries.table.depreciation') }}</th><th class="text-right">{{ t('assets_depreciation_show.entries.table.closing') }}</th><th class="text-center">{{ t('assets_depreciation_show.entries.table.posted') }}</th><th>{{ t('assets_depreciation_show.entries.table.posted_by') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="entry in schedule.entries" :key="entry.id" :class="entry.is_posted ? 'bg-success-soft' : ''">
              <td>{{ entry.period_sequence }}</td>
              <td>{{ monthName(entry.period_month) }} {{ entry.period_year }}</td>
              <td class="text-right">{{ formatCurrency(entry.opening_book_value) }}</td>
              <td class="text-right text-error">{{ formatCurrency(entry.depreciation_amount) }}</td>
              <td class="text-right font-weight-medium">{{ formatCurrency(entry.closing_book_value) }}</td>
              <td class="text-center"><VIcon v-if="entry.is_posted" icon="tabler-check" color="success" size="16" /></td>
              <td>{{ entry.postedBy?.name ?? '—' }}</td>
              <td><VBtn v-if="!entry.is_posted" size="x-small" variant="tonal" color="primary" @click="postEntry(entry)">{{ t('assets_depreciation_show.entries.post') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <VDialog v-model="showGenerateDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-6 pb-0">{{ t('assets_depreciation_show.dialog.title') }}</VCardTitle>
        <VCardText class="pa-6">
          <VForm @submit.prevent="submitGenerate">
            <VRow>
              <VCol cols="12"><VSelect v-model="generateForm.depreciation_method" :items="methodOptions" item-title="title" item-value="value" :label="t('assets_depreciation_show.dialog.method')" :error-messages="generateForm.errors.depreciation_method" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="generateForm.useful_life_years" :label="t('assets_depreciation_show.dialog.useful_life')" type="number" min="1" max="50" :error-messages="generateForm.errors.useful_life_years" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="generateForm.purchase_cost" :label="t('assets_depreciation_show.dialog.purchase_cost')" type="number" step="0.01" :error-messages="generateForm.errors.purchase_cost" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="generateForm.salvage_value" :label="t('assets_depreciation_show.dialog.salvage_value')" type="number" step="0.01" /></VCol>
              <VCol v-if="generateForm.depreciation_method === 'sars_wear_and_tear'" cols="12" sm="6"><VTextField v-model.number="generateForm.sars_wear_rate" :label="t('assets_depreciation_show.dialog.sars_wear_rate')" type="number" step="0.01" min="0" max="1" :error-messages="generateForm.errors.sars_wear_rate" /></VCol>
            </VRow>
            <div class="d-flex gap-3 mt-4">
              <VBtn type="submit" color="primary" :loading="generateForm.processing">{{ t('assets_depreciation_show.dialog.generate') }}</VBtn>
              <VBtn variant="text" @click="showGenerateDialog = false">{{ t('assets_depreciation_show.dialog.cancel') }}</VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
