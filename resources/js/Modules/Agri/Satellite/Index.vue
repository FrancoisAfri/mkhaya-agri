<script setup lang="ts">
/**
 * Satellite crop health for one farm: every field by its latest NDVI change (the biggest drop
 * first), a chosen field's NDVI over time with its zones and its latest picture, and "fetch now".
 */
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { num } from '../Components/agri'
import NdviChart, { type NdviSeries } from './NdviChart.vue'

type Health = { id: string; code: string; name: string; mapped: boolean; plan: string | null; latest_on: string | null; ndvi: number | null; change_pct: number | null; worst_recent_pct: number | null; source: string | null; spark: number[]; open_flags: number; last_scene_on: string | null }
const props = defineProps<{
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  health: Health[]
  series: NdviSeries | null
  provider: null | { key: string; name: string; ok: boolean | null; budget: number; remaining: number; max_cloud_pct: number; watched: number }
  ramp: Array<[number, string]>
  can: { fetch: boolean; configure: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.satellite.${key}`, params ?? {})
const page = usePage()
const warning = computed(() => (page.props as any).flash?.warning as string | null)
const fetching = ref<string | null>(null)

function fetchNow(fieldId: string | null = null) {
  if (!props.farm) return
  fetching.value = fieldId ?? 'farm'
  router.post(route('agri.satellite.fetch'), { farm_id: props.farm.id, field_id: fieldId }, { preserveScroll: true, onFinish: () => { fetching.value = null } })
}
function choose(h: Health) {
  router.get(route('agri.satellite.index'), { farm: props.farm?.id, field: h.id }, { preserveScroll: true, preserveState: true, only: ['series'] })
}
const pct = (v: number | null) => (v === null ? '—' : `${v > 0 ? '+' : ''}${num(v, 1)}%`)
const changeColour = (v: number | null) => (v === null ? undefined : v <= -15 ? 'error' : v < -5 ? 'warning' : v > 5 ? 'success' : undefined)
const ndviColour = (v: number | null) => {
  if (v === null) return '#9e9e9e'
  let c = props.ramp[0][1]
  for (const [stop, colour] of props.ramp) if (v >= stop) c = colour
  return c
}
const latestImage = computed(() => [...(props.series?.points ?? [])].reverse().find(p => p.image) ?? null)
const sparkPath = (s: number[]) => {
  if (s.length < 2) return ''
  return s.map((v, i) => `${i ? 'L' : 'M'}${(i / (s.length - 1)) * 80},${22 - Math.max(0, Math.min(1, v)) * 20}`).join(' ')
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
      <div class="d-flex flex-wrap gap-2 align-center">
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
        <VBtn v-if="farm" variant="tonal" prepend-icon="tabler-map" :href="route('agri.farms.map', farm.id)">{{ k('open_map') }}</VBtn>
        <VBtn v-if="can.configure" variant="tonal" prepend-icon="tabler-settings" :href="route('agri.satellite.settings')">{{ k('settings') }}</VBtn>
        <VBtn v-if="can.fetch" color="primary" prepend-icon="tabler-satellite" :loading="fetching === 'farm'" @click="fetchNow(null)">{{ k('fetch_farm') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="warning" type="warning" variant="tonal" class="mb-3" closable>{{ warning }}</VAlert>
    <VAlert v-if="!provider" type="info" variant="tonal" class="mb-4">
      {{ k('no_provider') }}
      <Link v-if="can.configure" :href="route('agri.satellite.settings')" class="ms-1">{{ k('set_up') }}</Link>
    </VAlert>
    <div v-else class="d-flex flex-wrap gap-2 mb-4">
      <VChip size="small" prepend-icon="tabler-satellite" :color="provider.ok === false ? 'error' : 'primary'" variant="tonal">{{ provider.name }}</VChip>
      <VChip size="small" variant="tonal">{{ k('budget_left', { left: provider.remaining, budget: provider.budget }) }}</VChip>
      <VChip size="small" variant="tonal">{{ k('max_cloud', { pct: provider.max_cloud_pct }) }}</VChip>
      <VChip size="small" variant="tonal">{{ k('watched', { n: provider.watched }) }}</VChip>
    </div>

    <VAlert v-if="!farm" type="info" variant="tonal">{{ k('no_farm') }}</VAlert>
    <VRow v-else>
      <VCol cols="12" lg="5">
        <VCard :title="k('health_title')" :subtitle="k('health_subtitle')">
          <VTable density="compact" class="agri-health">
            <thead><tr><th>{{ k('field') }}</th><th class="text-end">NDVI</th><th class="text-end">{{ k('change') }}</th><th>{{ k('trend') }}</th><th /></tr></thead>
            <tbody>
              <tr v-for="h in health" :key="h.id" :class="{ 'bg-light-primary': series?.field.id === h.id }" style="cursor: pointer" @click="choose(h)">
                <td>
                  <div class="font-weight-medium">{{ h.code }}<span v-if="h.plan" class="text-caption text-medium-emphasis"> · {{ h.plan }}</span></div>
                  <div class="text-caption text-medium-emphasis">{{ h.latest_on ?? k('no_reading') }}<template v-if="!h.mapped"> · {{ k('not_mapped') }}</template></div>
                </td>
                <td class="text-end text-no-wrap"><span class="agri-dot" :style="`background:${ndviColour(h.ndvi)}`" /> {{ h.ndvi === null ? '—' : num(h.ndvi, 2) }}</td>
                <td class="text-end text-no-wrap">
                  <VChip v-if="h.change_pct !== null" size="x-small" :color="changeColour(h.change_pct)" variant="tonal">{{ pct(h.change_pct) }}</VChip>
                  <span v-else>—</span>
                  <div v-if="h.worst_recent_pct !== null && h.worst_recent_pct !== h.change_pct" class="text-caption text-medium-emphasis">{{ k('worst_30', { pct: pct(h.worst_recent_pct) }) }}</div>
                </td>
                <td><svg v-if="h.spark.length > 1" width="80" height="24" viewBox="0 0 80 24"><path :d="sparkPath(h.spark)" fill="none" stroke="#2e7d32" stroke-width="1.5" /></svg></td>
                <td class="text-end text-no-wrap">
                  <VChip v-if="h.open_flags" size="x-small" color="error" prepend-icon="tabler-flag">{{ h.open_flags }}</VChip>
                </td>
              </tr>
              <tr v-if="!health.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('no_fields') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" lg="7">
        <VCard v-if="series">
          <VCardItem>
            <VCardTitle>{{ series.field.code }} · {{ series.field.name }}</VCardTitle>
            <VCardSubtitle>{{ k('series_subtitle') }}</VCardSubtitle>
            <template #append>
              <div class="d-flex gap-2">
                <VBtn size="small" variant="tonal" prepend-icon="tabler-map" :href="`${route('agri.farms.map', farm.id)}?field=${series.field.id}`">{{ k('on_map') }}</VBtn>
                <VBtn v-if="can.fetch" size="small" color="primary" prepend-icon="tabler-satellite" :loading="fetching === series.field.id" @click="fetchNow(series.field.id)">{{ k('fetch_field') }}</VBtn>
              </div>
            </template>
          </VCardItem>
          <VCardText><NdviChart :series="series" /></VCardText>
          <VDivider />
          <VCardText>
            <VRow>
              <VCol cols="12" md="5">
                <div class="text-subtitle-2 mb-2">{{ k('latest_picture') }}</div>
                <template v-if="latestImage">
                  <img :src="route('agri.satellite.image', latestImage.id)" :alt="k('picture_alt', { code: series.field.code, date: latestImage.date })" class="agri-ndvi-img">
                  <div class="text-caption text-medium-emphasis">{{ latestImage.date }} · {{ latestImage.source }}</div>
                </template>
                <div v-else class="text-caption text-medium-emphasis">{{ k('no_picture') }}</div>
                <div class="d-flex align-center gap-1 mt-2 text-caption">
                  <span>{{ ramp[0][0] }}</span>
                  <span class="agri-ramp" :style="`background:linear-gradient(to right, ${ramp.map(r => r[1]).join(', ')})`" />
                  <span>{{ ramp[ramp.length - 1][0] }}</span>
                </div>
              </VCol>
              <VCol cols="12" md="7">
                <div class="text-subtitle-2 mb-2">{{ k('captures') }}</div>
                <VTable density="compact">
                  <thead><tr><th>{{ k('date') }}</th><th class="text-end">{{ k('mean') }}</th><th class="text-end">{{ k('cloud') }}</th><th class="text-end">{{ k('change') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="p in [...series.points].reverse().slice(0, 8)" :key="p.id">
                      <td>{{ p.date }}</td><td class="text-end">{{ num(p.mean, 3) }}</td>
                      <td class="text-end">{{ p.cloud_pct === null ? '—' : `${num(p.cloud_pct, 0)}%` }}</td>
                      <td class="text-end" :class="changeColour(p.change_pct) ? `text-${changeColour(p.change_pct)}` : ''">{{ pct(p.change_pct) }}<VIcon v-if="p.suppressed" icon="tabler-info-circle" size="14" class="ms-1" color="info" :title="p.suppressed" /></td>
                    </tr>
                  </tbody>
                </VTable>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
        <VCard v-else><VCardText class="text-center text-medium-emphasis py-8">{{ k('pick_field') }}</VCardText></VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.agri-dot { display: inline-block; inline-size: 10px; block-size: 10px; border-radius: 50%; vertical-align: middle; }
.agri-ramp { display: inline-block; flex: 1; block-size: 10px; border-radius: 2px; max-inline-size: 220px; }
.agri-ndvi-img { inline-size: 100%; max-inline-size: 320px; image-rendering: pixelated; background: repeating-conic-gradient(#eee 0% 25%, #fff 0% 50%) 50% / 16px 16px; border-radius: 4px; }
.agri-health tbody tr:hover { background: rgba(var(--v-theme-primary), 0.06); }
</style>
