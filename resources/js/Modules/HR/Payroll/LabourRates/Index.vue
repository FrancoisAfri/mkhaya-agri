<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const r = (key: string, a?: Record<string, unknown>) => t('bureau.labour.' + key, a ?? {})

interface Rate {
  id: number; country: string; sector: string | null
  effective_from: string; effective_to: string | null
  nmw_hourly: number; nmw_monthly: number
  standard_monthly_hours: number; max_overtime_hours_per_week: number
  overtime_multiplier: number; sunday_multiplier: number; public_holiday_multiplier: number
  coida_earnings_ceiling: number | null; source: string | null
}

const props = defineProps<{ rates: Rate[]; current: Record<string, any> }>()

const rand = (n: number | null) =>
  n === null ? '—' : 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const inForce = computed(() => props.rates.find(x => x.effective_to === null && !x.sector) ?? null)

const dialog = ref(false)
const form = useForm({
  country: 'ZA', sector: '',
  effective_from: new Date().toISOString().slice(0, 10),
  nmw_hourly: inForce.value?.nmw_hourly ?? 0,
  standard_monthly_hours: inForce.value?.standard_monthly_hours ?? 160,
  max_overtime_hours_per_week: inForce.value?.max_overtime_hours_per_week ?? 10,
  overtime_multiplier: inForce.value?.overtime_multiplier ?? 1.5,
  sunday_multiplier: inForce.value?.sunday_multiplier ?? 2,
  public_holiday_multiplier: inForce.value?.public_holiday_multiplier ?? 2,
  coida_earnings_ceiling: inForce.value?.coida_earnings_ceiling ?? null,
  source: '',
})

function save() {
  form.post(route('payroll.labour-rates.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false },
  })
}

const monthlyPreview = computed(() =>
  (Number(form.nmw_hourly) || 0) * (Number(form.standard_monthly_hours) || 0))
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ r('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ r('description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ r('new_determination') }}</VBtn>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ r('why') }}</VAlert>

    <!-- What is in force today, which is the question somebody opens this to answer. -->
    <VRow v-if="inForce" class="mb-2">
      <VCol cols="12" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="text-caption text-medium-emphasis">{{ r('nmw') }}</div>
            <div class="text-h6">{{ rand(inForce.nmw_monthly) }}</div>
            <div class="text-caption text-medium-emphasis">{{ rand(inForce.nmw_hourly) }}/hr</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="text-caption text-medium-emphasis">{{ r('overtime') }}</div>
            <div class="text-h6">{{ inForce.overtime_multiplier }}×</div>
            <div class="text-caption text-medium-emphasis">{{ r('bcea10') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="text-caption text-medium-emphasis">{{ r('sunday_ph') }}</div>
            <div class="text-h6">{{ inForce.sunday_multiplier }}× / {{ inForce.public_holiday_multiplier }}×</div>
            <div class="text-caption text-medium-emphasis">{{ r('bcea1618') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="text-caption text-medium-emphasis">{{ r('coida_ceiling') }}</div>
            <div class="text-h6">{{ rand(inForce.coida_earnings_ceiling) }}</div>
            <div class="text-caption text-medium-emphasis">{{ r('per_employee') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ r('history') }}</VCardTitle>
      <div style="overflow-x:auto">
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>{{ r('in_force') }}</th>
              <th>{{ r('sector') }}</th>
              <th class="text-end">{{ r('nmw_hourly') }}</th>
              <th class="text-end">{{ r('nmw_monthly') }}</th>
              <th class="text-end">{{ r('ot_ceiling') }}</th>
              <th class="text-end">{{ r('multipliers') }}</th>
              <th class="text-end">{{ r('coida_ceiling') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="rate in rates" :key="rate.id">
              <td>
                {{ rate.effective_from }} → {{ rate.effective_to || r('current') }}
                <VChip v-if="!rate.effective_to && !rate.sector" size="x-small" color="success" variant="tonal" class="ms-1">
                  {{ r('active') }}
                </VChip>
              </td>
              <td>{{ rate.sector || r('general') }}</td>
              <td class="text-end">{{ rand(rate.nmw_hourly) }}</td>
              <td class="text-end">{{ rand(rate.nmw_monthly) }}</td>
              <td class="text-end">{{ rate.max_overtime_hours_per_week }} hrs/wk</td>
              <td class="text-end">
                {{ rate.overtime_multiplier }}× / {{ rate.sunday_multiplier }}× / {{ rate.public_holiday_multiplier }}×
              </td>
              <td class="text-end">{{ rand(rate.coida_earnings_ceiling) }}</td>
            </tr>
            <tr v-if="!rates.length">
              <td colspan="7" class="text-center text-medium-emphasis py-8">{{ r('empty') }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog v-model="dialog" max-width="700">
      <VCard>
        <VCardTitle>{{ r('new_determination') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ r('new_help') }}</p>
          <div class="field-row">
            <VTextField
              v-model="form.effective_from" type="date" :label="r('effective_from')"
              :error-messages="form.errors.effective_from"
            />
            <VTextField v-model="form.sector" :label="r('sector')" :hint="r('sector_hint')" persistent-hint />
          </div>
          <div class="field-row">
            <VTextField
              v-model.number="form.nmw_hourly" type="number" step="0.01" :label="r('nmw_hourly')"
              :error-messages="form.errors.nmw_hourly"
            />
            <VTextField
              v-model.number="form.standard_monthly_hours" type="number" :label="r('standard_hours')"
              :hint="r('monthly_preview', { amount: rand(monthlyPreview) })" persistent-hint
            />
          </div>
          <div class="field-row">
            <VTextField v-model.number="form.overtime_multiplier" type="number" step="0.1" :label="r('overtime')" />
            <VTextField v-model.number="form.sunday_multiplier" type="number" step="0.1" :label="r('sunday')" />
            <VTextField v-model.number="form.public_holiday_multiplier" type="number" step="0.1" :label="r('public_holiday')" />
          </div>
          <div class="field-row">
            <VTextField
              v-model.number="form.max_overtime_hours_per_week" type="number" :label="r('ot_ceiling')"
            />
            <VTextField
              v-model.number="form.coida_earnings_ceiling" type="number" :label="r('coida_ceiling')"
            />
          </div>
          <VTextField v-model="form.source" :label="r('source')" :hint="r('source_hint')" persistent-hint />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('bureau.ui.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('bureau.ui.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Fields sharing a row keep an even split whatever is written beneath them. */
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 11rem; min-width: 0; }
</style>
