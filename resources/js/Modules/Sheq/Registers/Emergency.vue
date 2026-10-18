<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'
import AddEntryDialog from '@/Modules/Sheq/Partials/AddEntryDialog.vue'

const { t, d } = useI18n()

const props = defineProps<{
  plans: Array<Record<string, any>>
  drills: Array<Record<string, any>>
  overdue: Array<Record<string, any>>
  types: string[]
  inForce: Array<{ emergency_type: string; plan: Record<string, any> | null; review_overdue: boolean }>
  siteId: number | null
  sites: Array<{ id: number; name: string }>
  canManage?: boolean
  noCompany?: boolean
}>()

/*
  Which plan governs, per emergency type, at the site asked about.

  The register lists every plan and leaves the reader to work out precedence — a site plan beats the
  company-wide one. Reasonable for an annual review; useless to somebody with a fire looking at two
  "FIRE" rows. A type with no plan at all is the finding, and it is invisible in a list of the plans
  that do exist.
*/
const siteFilter = computed(() => [
  { title: t('sheq.emergency.company_wide'), value: null as number | null },
  ...props.sites.map(site => ({ title: site.name, value: site.id as number | null })),
])

const uncovered = computed(() => props.inForce.filter(entry => entry.plan === null))

function changeSite(siteId: number | null) {
  router.get('/sheq/emergency', siteId === null ? {} : { site_id: siteId },
    { preserveState: false })
}

const siteOptions = computed(() => props.sites.map(s => ({ title: s.name, value: s.id })))

const typeOptions = computed(() => props.types.map(x => ({
  title: t('sheq.emergency.types.' + x), value: x,
})))

const today = () => new Date().toISOString().slice(0, 10)

const mmss = (s: number | null) => {
  if (s === null) return '—'
  const m = Math.floor(s / 60)
  return `${m}m ${String(s % 60).padStart(2, '0')}s`
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5 d-flex align-start justify-space-between flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.emergency.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.emergency.subtitle') }}</p>
      </div>
      <div class="d-flex align-center ga-2">
        <VSelect
          :model-value="siteId" :items="siteFilter" density="compact" variant="outlined"
          hide-details style="min-width: 200px" @update:model-value="changeSite"
        />
        <AddEntryDialog
          v-if="canManage"
          :title="t('sheq.emergency.record_drill')" action="/sheq/emergency/drills"
          :button-label="t('sheq.emergency.record_drill')"
          :note="t('sheq.emergency.record_drill_note')"
          :fields="[
            { key: 'drill_date', label: t('sheq.registers.col.date'), type: 'date' as const,
              default: today() },
            { key: 'drill_type', label: t('sheq.registers.col.type'), type: 'select' as const,
              items: typeOptions, default: 'FIRE' },
            { key: 'site_id', label: t('sheq.registers.col.site'), type: 'select' as const,
              items: siteOptions },
            { key: 'evacuation_seconds', label: t('sheq.emergency.evacuation_seconds'),
              type: 'number' as const },
            { key: 'participants', label: t('sheq.emergency.participants'), type: 'number' as const },
            { key: 'unaccounted', label: t('sheq.emergency.unaccounted'), type: 'number' as const,
              default: 0, hint: t('sheq.emergency.unaccounted_hint') },
            { key: 'observations', label: t('sheq.emergency.observations'),
              type: 'textarea' as const, cols: 12 },
            { key: 'improvements', label: t('sheq.emergency.improvements'),
              type: 'textarea' as const, cols: 12, hint: t('sheq.emergency.improvements_hint') },
          ]"
        />
      </div>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="overdue.length" type="warning" variant="tonal" class="mb-4"
        role="status" aria-live="polite">
        {{ overdue.length }} {{ t('sheq.emergency.overdue').toLowerCase() }}
      </VAlert>

      <!-- An emergency nobody has written a procedure for is the thing to know first. -->
      <VAlert v-if="uncovered.length" type="error" variant="tonal" class="mb-4">
        {{ t('sheq.emergency.uncovered', {
             types: uncovered.map(e => e.emergency_type).join(', ') }) }}
      </VAlert>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
          {{ t('sheq.emergency.in_force') }}
        </VCardTitle>
        <VDivider />
        <VTable density="compact">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.emergency.type') }}</th>
              <th scope="col">{{ t('sheq.emergency.plan') }}</th>
              <th scope="col">{{ t('sheq.emergency.assembly') }}</th>
              <th scope="col">{{ t('sheq.emergency.review_due') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry in inForce" :key="entry.emergency_type">
              <td class="font-weight-medium">{{ entry.emergency_type }}</td>
              <td class="text-body-2">
                <template v-if="entry.plan">{{ entry.plan.name }}</template>
                <VChip v-else size="x-small" variant="tonal" color="error">
                  {{ t('sheq.emergency.no_plan') }}
                </VChip>
              </td>
              <td class="text-body-2 text-medium-emphasis">
                {{ entry.plan ? (entry.plan.assembly_points ?? []).join(', ') || '—' : '—' }}
              </td>
              <td class="text-body-2">
                <span v-if="entry.plan?.review_due"
                      :class="entry.review_overdue ? 'text-error' : ''">
                  {{ entry.plan.review_due }}
                </span>
                <span v-else class="text-medium-emphasis">—</span>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <VRow>
        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.emergency.title') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!plans.length" class="text-center py-8 text-medium-emphasis">
              <VIcon icon="mdi-alarm-light-outline" size="32" class="mb-2" />
              <div class="text-body-2">{{ t('sheq.emergency.none') }}</div>
            </div>
            <div v-for="p in plans" :key="p.uuid" class="pa-4 border-b">
              <div class="d-flex align-center ga-2">
                <span class="text-body-2 font-weight-medium">{{ p.name }}</span>
                <VChip size="x-small" variant="tonal">{{ p.emergency_type }}</VChip>
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('sheq.emergency.assembly') }}:
                {{ (p.assembly_points || []).map((a: any) => a.name).join(', ') || '—' }}
              </div>
            </div>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="text-subtitle-1 font-weight-bold py-3">
              {{ t('sheq.emergency.drills') }}
            </VCardTitle>
            <VDivider />
            <div v-if="!drills.length" class="text-center py-8 text-medium-emphasis">
              <div class="text-body-2">No drills recorded.</div>
            </div>
            <VTable v-else density="compact">
              <tbody>
                <tr v-for="dr in drills" :key="dr.uuid">
                  <td class="text-caption">{{ dr.drill_date }}</td>
                  <td class="text-body-2">{{ dr.drill_type }}</td>
                  <td class="text-caption">{{ mmss(dr.evacuation_seconds) }}</td>
                  <td style="width: 150px">
                    <!--
                      A fast evacuation with somebody missing is a failed drill. The label says so
                      rather than leaving the time to imply success.
                    -->
                    <VChip size="x-small" :color="dr.passed ? 'success' : 'error'" variant="tonal">
                      {{ dr.passed ? t('sheq.emergency.passed') : `${dr.unaccounted} ${t('sheq.emergency.unaccounted').toLowerCase()}` }}
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.row-hover { cursor: pointer; transition: background-color 200ms ease; }
.row-hover:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
@media (prefers-reduced-motion: reduce) { .row-hover { transition: none; } }
</style>
