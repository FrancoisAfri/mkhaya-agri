<script setup lang="ts">
/**
 * Peer benchmarks: each of the organisation's crops beside the band (P25–P75, median) of farms like
 * it — same crop, season and region — in other organisations that opted in. Bands come only from
 * groups of enough farms and organisations that no single farm can be seen.
 */
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from './Components/PageErrors.vue'
import FarmFilter from './Components/FarmFilter.vue'
import { num } from './Components/agri'

type Band = null | { p25: number; median: number; p75: number; farms: number; organisations: number; computed_at: string | null; seeded: boolean }
type Row = { plan: string; plan_id: string; crop: string; field: string | null; farm: string; season: string; region: string; status: string; currency: string
  own: null | Record<string, number | null>; bands: Record<string, Band> }
const props = defineProps<{
  filters: { farm: string | null }
  farms: Array<{ id: string; name: string }>
  data: { opted_in: boolean; opted_at?: string | null; rows: Row[]; bands: number }
  rules: { min_farms: number; min_orgs: number }
  can: { opt: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.benchmarks.${key}`, params ?? {})
const METRICS = [
  { key: 'yield_t_ha', unit: 't/ha', digits: 2, higherIsBetter: true },
  { key: 'cost_per_ha', unit: '/ha', digits: 0, higherIsBetter: false },
  { key: 'cost_per_t', unit: '/t', digits: 0, higherIsBetter: false },
]
const opt = (on: boolean) => router.post(route('agri.benchmarks.opt-in'), { opt_in: on }, { preserveScroll: true })

/** Where a value sits on a bar running from a little below P25 to a little above P75 (0–100 %). */
function scale(b: NonNullable<Band>, v: number | null | undefined) {
  const span = Math.max(b.p75 - b.p25, Math.abs(b.median) * 0.1, 0.0001)
  const lo = b.p25 - span * 0.6
  const hi = b.p75 + span * 0.6
  const at = (x: number) => Math.max(0, Math.min(100, ((x - lo) / (hi - lo)) * 100))
  return { p25: at(b.p25), median: at(b.median), p75: at(b.p75), own: v === null || v === undefined ? null : at(v) }
}
function verdict(m: typeof METRICS[number], b: Band, v: number | null | undefined) {
  if (!b || v === null || v === undefined) return null
  const good = m.higherIsBetter ? v >= b.p75 : v <= b.p25
  const poor = m.higherIsBetter ? v < b.p25 : v > b.p75
  return good ? { text: k('top_quarter'), color: 'success' } : poor ? { text: k('bottom_quarter'), color: 'warning' } : { text: k('middle_half'), color: undefined }
}
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="filters.farm" />
    </div>

    <VAlert :type="data.opted_in ? 'success' : 'info'" variant="tonal" class="mb-4">
      <div class="d-flex flex-wrap align-center gap-3">
        <div class="flex-grow-1">
          <strong>{{ data.opted_in ? k('opted_in') : k('not_opted_in') }}</strong>
          <div class="text-body-2">{{ k('privacy', { farms: rules.min_farms, orgs: rules.min_orgs }) }}</div>
        </div>
        <template v-if="can.opt">
          <VBtn v-if="!data.opted_in" color="primary" @click="opt(true)">{{ k('opt_in') }}</VBtn>
          <VBtn v-else variant="tonal" color="secondary" @click="opt(false)">{{ k('opt_out') }}</VBtn>
        </template>
        <span v-else-if="!data.opted_in" class="text-caption">{{ k('ask_admin') }}</span>
      </div>
    </VAlert>

    <template v-if="data.opted_in">
      <VCard v-for="r in data.rows" :key="r.plan_id" class="mb-3">
        <VCardItem>
          <VCardTitle class="text-wrap">{{ r.crop }} · {{ r.plan }}<span v-if="r.field" class="text-medium-emphasis"> · {{ r.field }}</span></VCardTitle>
          <VCardSubtitle>{{ r.farm }} · {{ r.season }} · {{ r.region }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow>
            <VCol v-for="m in METRICS" :key="m.key" cols="12" md="4">
              <div class="text-subtitle-2 mb-1">{{ k(m.key) }}</div>
              <template v-if="r.bands[m.key]">
                <div class="position-relative rounded" style="height: 18px; background: rgba(var(--v-theme-on-surface), 0.06)">
                  <div class="position-absolute rounded" :style="{ left: scale(r.bands[m.key]!, null).p25 + '%', width: (scale(r.bands[m.key]!, null).p75 - scale(r.bands[m.key]!, null).p25) + '%', top: '3px', bottom: '3px', background: 'rgba(var(--v-theme-primary), 0.3)' }" />
                  <div class="position-absolute" :style="{ left: scale(r.bands[m.key]!, null).median + '%', top: 0, bottom: 0, width: '2px', background: 'rgb(var(--v-theme-primary))' }" :title="k('median')" />
                  <div v-if="scale(r.bands[m.key]!, r.own?.[m.key]).own !== null" class="position-absolute rounded-circle" :title="k('yours')"
                    :style="{ left: `calc(${scale(r.bands[m.key]!, r.own?.[m.key]).own}% - 6px)`, top: '3px', width: '12px', height: '12px', background: 'rgb(var(--v-theme-error))' }" />
                </div>
                <div class="d-flex justify-space-between text-caption mt-1">
                  <span>P25 {{ num(r.bands[m.key]!.p25, m.digits) }}</span><span>{{ k('median') }} {{ num(r.bands[m.key]!.median, m.digits) }}</span><span>P75 {{ num(r.bands[m.key]!.p75, m.digits) }}</span>
                </div>
                <div class="text-body-2 mt-1">
                  {{ k('yours') }}: <strong>{{ r.own?.[m.key] !== null && r.own?.[m.key] !== undefined ? num(r.own[m.key] as number, m.digits) : '—' }}</strong>
                  <span class="text-caption"> {{ m.key === 'yield_t_ha' ? m.unit : r.currency + m.unit }}</span>
                  <VChip v-if="verdict(m, r.bands[m.key], r.own?.[m.key])" size="x-small" class="ms-1" :color="verdict(m, r.bands[m.key], r.own?.[m.key])!.color">{{ verdict(m, r.bands[m.key], r.own?.[m.key])!.text }}</VChip>
                </div>
                <div class="text-caption text-medium-emphasis">{{ k('band_of', { farms: r.bands[m.key]!.farms, orgs: r.bands[m.key]!.organisations }) }}<template v-if="r.bands[m.key]!.seeded"> · {{ k('demo') }}</template></div>
              </template>
              <div v-else class="text-body-2 text-medium-emphasis">{{ k('no_band') }}
                <div v-if="r.own?.[m.key] !== null && r.own?.[m.key] !== undefined" class="mt-1">{{ k('yours') }}: <strong>{{ num(r.own![m.key] as number, m.digits) }}</strong></div>
              </div>
            </VCol>
          </VRow>
          <div v-if="!r.own" class="text-caption text-medium-emphasis mt-2">{{ k('no_own') }}</div>
        </VCardText>
      </VCard>
      <VCard v-if="!data.rows.length"><VCardText class="text-center text-medium-emphasis py-8">{{ k('no_rows') }}</VCardText></VCard>
    </template>
  </div>
</template>
