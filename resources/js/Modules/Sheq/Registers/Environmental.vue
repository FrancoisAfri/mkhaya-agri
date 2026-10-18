<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

const props = defineProps<{
  meters: Array<Record<string, any>>
  summary: Record<string, any> | null
  trend: Array<Record<string, any>>
  stale: Array<Record<string, any>>
  aspects: string[]
  period: { from: string; to: string }
  canManage?: boolean
  noCompany?: boolean
}>()

const meterItems = computed(() => props.meters.map(m => ({
  // The unit beside the name, because "4 200" means nothing until you know whether it is litres
  // or kilowatt-hours, and the field below asks only for the number.
  title: m.unit ? `${m.name} (${m.unit})` : m.name,
  value: m.id,
})))

// Today, which is what somebody recording a reading would type. Applied each time the dialog
// opens rather than once, so a cancelled entry does not leave yesterday behind in the next one.
const today = () => new Date().toISOString().slice(0, 10)

const aspectIcon = (a: string) => ({
  WATER: 'mdi-water', ELECTRICITY: 'mdi-flash', DIESEL: 'mdi-fuel', PETROL: 'mdi-fuel',
  GAS: 'mdi-fire', WASTE: 'mdi-delete', RECYCLING: 'mdi-recycle', EMISSIONS: 'mdi-smoke',
}[a] ?? 'mdi-leaf')

const carbon = computed(() => props.summary?.total_co2e_kg ?? 0)
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.environmental.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.environmental.subtitle') }}</p>
      </div>
      <!-- In a dialog like every other entry form in the module. Inline it sat permanently in the
           page between the heading and the figures, pushing the carbon total out of sight on a
           laptop whenever it was open. -->
      <AddEntryDialog
        v-if="canManage"
        :title="t('sheq.environmental.record')" action="/sheq/environmental/readings"
        :button-label="t('sheq.environmental.record')" :note="t('sheq.environmental.record_note')"
        :fields="[
          { key: 'meter_id', label: t('sheq.environmental.meter'), type: 'select' as const,
            items: meterItems, cols: 12, required: true },
          { key: 'reading_date', label: t('sheq.registers.col.date'), type: 'date' as const,
            default: today() },
          { key: 'value', label: t('sheq.environmental.value'), type: 'number' as const },
          { key: 'note', label: t('sheq.registers.col.note'), type: 'textarea' as const, cols: 12 },
        ]"
      />
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!--
        Named rather than silently excluded: a carbon total that quietly omits a site's diesel
        is worse than one that says which meters are missing a factor.
      -->
      <VAlert
        v-if="summary?.meters_without_factor?.length" type="info" variant="tonal" class="mb-4"
      >
        {{ t('sheq.environmental.no_factor') }}
        {{ summary.meters_without_factor.join(', ') }}
      </VAlert>

      <VAlert v-if="stale.length" type="warning" variant="tonal" class="mb-4" role="status" aria-live="polite">
        {{ stale.length }} {{ t('sheq.environmental.stale').toLowerCase() }}
      </VAlert>

      <VRow class="mb-1">
        <VCol cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="pa-4">
            <div class="text-caption text-uppercase text-medium-emphasis">
              {{ t('sheq.environmental.total_carbon') }}
            </div>
            <div class="text-h4 font-weight-bold">{{ carbon.toLocaleString() }}</div>
            <div class="text-caption text-medium-emphasis">kg CO2e · {{ period.from }} → {{ period.to }}</div>
          </VCard>
        </VCol>
        <VCol cols="12" md="8">
          <VCard elevation="0" border rounded="lg">
            <VTable density="compact">
              <thead>
                <tr>
                  <th scope="col">{{ t('sheq.environmental.aspect') }}</th>
                  <th scope="col">{{ t('sheq.environmental.total') }}</th>
                  <th scope="col">{{ t('sheq.environmental.co2e') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!summary?.aspects?.length">
                  <td colspan="3" class="text-center py-6 text-medium-emphasis text-body-2">
                    {{ t('sheq.environmental.none') }}
                  </td>
                </tr>
                <tr v-for="a in summary?.aspects ?? []" :key="a.aspect">
                  <td class="text-body-2">
                    <VIcon :icon="aspectIcon(a.aspect)" size="16" class="me-1" />{{ a.aspect }}
                  </td>
                  <td class="text-body-2">{{ a.total.toLocaleString() }} {{ a.unit }}</td>
                  <td class="text-body-2">{{ a.co2e_kg.toLocaleString() }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>

      <VCard v-if="stale.length" elevation="0" border rounded="lg" class="mt-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.environmental.stale') }}
        </VCardTitle>
        <VDivider />
        <VTable density="compact">
          <tbody>
            <tr v-for="m in stale" :key="m.uuid">
              <td class="text-body-2">{{ m.name }}</td>
              <td class="text-caption text-medium-emphasis">{{ m.aspect }}</td>
              <td class="text-caption" style="width: 150px">
                <VChip size="x-small" :color="m.never_read ? 'error' : 'warning'" variant="tonal">
                  {{ m.never_read ? t('sheq.environmental.never_read') : m.last_read }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
.row-hover:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
