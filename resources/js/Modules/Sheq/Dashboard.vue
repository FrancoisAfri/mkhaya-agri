<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t, d } = useI18n()

type Tile = { key: string; count: number; href?: string; severity?: string }

type Incident = {
  uuid: string; reference: string; incident_type: string; occurred_at: string
  status: string; statutory_category: string | null; is_reportable: boolean
  site_name: string | null
}

type Rate = { value: number | null; label: string; numerator: number; denominator: number }

type Lagging = {
  rates: Record<string, Rate>
  lagging: Record<string, number>
  hours_worked: number
  exposure_estimated: boolean
  has_exposure: boolean
  computed_at: string
  lag_seconds: number
}

type Compliance = {
  by_status: Record<string, number>; lines: number; percent: number; needs_attention: number
}

const props = defineProps<{
  attention: Tile[]
  leading: Tile[]
  lagging: Lagging | null
  compliance: Compliance | null
  recentIncidents: Incident[]
  period: string | null
  noCompany?: boolean
}>()

/**
 * Only surface what actually needs doing. A row of zeroes trains people to ignore the block, so a
 * clear board says so in one line instead of showing four green noughts.
 */
const attentionItems = computed(() => props.attention.filter(a => a.count > 0))

const allClear = computed(() => !props.noCompany && attentionItems.value.length === 0)

const severityColour = (severity?: string) => severity === 'error' ? 'error' : 'warning'

const statusColour: Record<string, string> = {
  REPORTED: 'warning', UNDER_INVESTIGATION: 'info', ACTIONS_PENDING: 'info',
  CLOSED: 'success', REJECTED: 'grey',
}

/** Whatever rates the active regulatory pack defines — never a hard-coded list (SHEQ-REG-7). */
const rates = computed(() =>
  Object.entries(props.lagging?.rates ?? {}).map(([key, rate]) => ({ key, ...rate })))

/** A figure computed a day ago is fine; one from last week should say so. */
const staleness = computed(() => {
  if (!props.lagging) return null
  const hours = Math.floor(props.lagging.lag_seconds / 3600)

  return hours < 1 ? null : hours
})

const formatDate = (value: string) => {
  try { return d(new Date(value), 'short') } catch { return value }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('sheq.dashboard.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.dashboard.subtitle') }}</p>
      </div>
      <VChip v-if="period" size="small" variant="tonal">{{ period }}</VChip>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <!-- ── Needs attention. Statutory deadlines first: a missed section 24 notification is a
           criminal matter, not a task. -->
      <h2 class="text-subtitle-1 font-weight-semibold mb-2">{{ t('sheq.dashboard.needs_attention') }}</h2>

      <VAlert v-if="allClear" type="success" variant="tonal" density="comfortable" class="mb-6">
        {{ t('sheq.dashboard.all_clear') }}
      </VAlert>

      <VRow v-else class="mb-4">
        <VCol v-for="tile in attentionItems" :key="tile.key" cols="12" sm="6" md="3">
          <!-- The whole tile is the link, so the target is the full card rather than a word in it. -->
          <component
            :is="tile.href ? Link : 'div'"
            :href="tile.href"
            class="text-decoration-none d-block h-100"
          >
            <VCard
              elevation="0" border rounded="lg"
              :class="['h-100 sheq-tile', tile.href ? 'sheq-tile--link' : '']"
            >
              <VCardText>
                <div class="d-flex align-center justify-space-between">
                  <div class="text-caption text-medium-emphasis">
                    {{ t('sheq.dashboard.tile.' + tile.key) }}
                  </div>
                  <VIcon
                    :icon="tile.severity === 'error' ? 'mdi-alert-octagon' : 'mdi-alert'"
                    :color="severityColour(tile.severity)" size="18"
                  />
                </div>
                <div :class="['text-h4 font-weight-bold mt-1', 'text-' + severityColour(tile.severity)]">
                  {{ tile.count }}
                </div>
              </VCardText>
            </VCard>
          </component>
        </VCol>
      </VRow>

      <VRow>
        <!-- ── Leading indicators ─────────────────────────────────────────────────────────── -->
        <VCol cols="12" md="7">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="text-body-1 font-weight-semibold">
              {{ t('sheq.dashboard.leading_indicators') }}
            </VCardTitle>
            <VCardText>
              <div
                v-for="item in leading" :key="item.key"
                class="d-flex align-center justify-space-between py-2 border-b"
              >
                <span class="text-body-2">{{ t('sheq.dashboard.tile.' + item.key) }}</span>
                <span
                  :class="['text-body-1 font-weight-bold', item.count > 0 ? 'text-warning' : 'text-medium-emphasis']"
                >{{ item.count }}</span>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <!-- ── Legal register compliance ──────────────────────────────────────────────────── -->
        <VCol cols="12" md="5">
          <VCard elevation="0" border rounded="lg" class="h-100">
            <VCardTitle class="d-flex align-center justify-space-between">
              <span class="text-body-1 font-weight-semibold">{{ t('sheq.dashboard.legal_compliance') }}</span>
              <Link href="/sheq/legal-register" class="text-primary text-body-2 text-decoration-none">
                {{ t('sheq.dashboard.view_all') }}
              </Link>
            </VCardTitle>
            <VCardText>
              <template v-if="compliance">
                <div class="d-flex align-center gap-3">
                  <div class="text-h4 font-weight-bold">{{ compliance.percent }}%</div>
                  <VProgressLinear
                    :model-value="compliance.percent" height="8" rounded
                    :color="compliance.percent >= 90 ? 'success' : 'warning'"
                    :aria-label="t('sheq.dashboard.legal_compliance')"
                  />
                </div>
                <p class="text-caption text-medium-emphasis mt-2 mb-0">
                  {{ t('sheq.dashboard.lines_assessed', { count: compliance.lines }) }}
                </p>
                <VAlert
                  v-if="compliance.needs_attention" type="warning" variant="tonal"
                  density="compact" class="mt-3"
                >
                  {{ t('sheq.dashboard.clauses_need_attention', { count: compliance.needs_attention }) }}
                </VAlert>
              </template>
              <div v-else class="text-body-2 text-medium-emphasis py-4">
                {{ t('sheq.dashboard.no_register') }}
                <div class="mt-2">
                  <Link href="/sheq/regulatory-packs" class="text-primary text-decoration-none">
                    {{ t('sheq.dashboard.activate_pack') }}
                  </Link>
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- ── Lagging KPIs ───────────────────────────────────────────────────────────────── -->
      <VCard elevation="0" border rounded="lg" class="mt-4">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ t('sheq.dashboard.lagging_kpis') }}</VCardTitle>
        <VCardText>
          <!-- Not computed yet is a different statement from "no injuries", and the page must not
               blur them. The projection is built by sheq:kpis:compute, not by loading this page. -->
          <VAlert v-if="!lagging" type="info" variant="tonal" density="comfortable">
            {{ t('sheq.dashboard.no_kpi_snapshot') }}
          </VAlert>

          <!-- A rate with no exposure hours is unknown, not zero. Showing a green 0.0 LTIFR because
               nobody captured hours is the most dangerous number this page could print. -->
          <VAlert v-else-if="!lagging.has_exposure" type="info" variant="tonal" density="comfortable">
            {{ t('sheq.dashboard.no_exposure') }}
          </VAlert>

          <template v-else>
            <VAlert
              v-if="lagging.exposure_estimated" type="warning" variant="tonal"
              density="compact" class="mb-3"
            >
              {{ t('sheq.dashboard.exposure_estimated') }}
            </VAlert>

            <VRow>
              <VCol v-for="rate in rates" :key="rate.key" cols="6" md="2">
                <div class="text-caption text-medium-emphasis" :title="rate.label">{{ rate.key }}</div>
                <div class="text-h5 font-weight-bold">
                  {{ rate.value === null ? '—' : rate.value }}
                </div>
              </VCol>
              <VCol cols="6" md="2">
                <div class="text-caption text-medium-emphasis">{{ t('sheq.dashboard.hours_worked') }}</div>
                <div class="text-h5 font-weight-bold">{{ lagging.hours_worked }}</div>
              </VCol>
            </VRow>

            <p v-if="staleness" class="text-caption text-medium-emphasis mt-3 mb-0">
              {{ t('sheq.dashboard.computed_hours_ago', { hours: staleness }) }}
            </p>
          </template>
        </VCardText>
      </VCard>

      <!-- ── Recent incidents ───────────────────────────────────────────────────────────── -->
      <VCard elevation="0" border rounded="lg" class="mt-4">
        <VCardTitle class="d-flex align-center justify-space-between">
          <span class="text-body-1 font-weight-semibold">{{ t('sheq.dashboard.recent_incidents') }}</span>
          <Link href="/sheq/incidents" class="text-primary text-body-2 text-decoration-none">
            {{ t('sheq.dashboard.view_all') }}
          </Link>
        </VCardTitle>

        <div v-if="!recentIncidents.length" class="text-medium-emphasis text-body-2 py-8 text-center">
          {{ t('sheq.dashboard.no_incidents') }}
        </div>

        <VTable v-else density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.incident.reference') }}</th>
              <th scope="col">{{ t('sheq.incident.type') }}</th>
              <th scope="col">{{ t('sheq.incident.occurred') }}</th>
              <th scope="col">{{ t('sheq.incident.status') }}</th>
              <th scope="col">{{ t('sheq.incident.classification') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="incident in recentIncidents" :key="incident.uuid">
              <td>
                <Link
                  :href="`/sheq/incidents/${incident.uuid}`"
                  class="text-primary text-decoration-none font-weight-medium"
                >{{ incident.reference }}</Link>
                <div v-if="incident.site_name" class="text-caption text-medium-emphasis">
                  {{ incident.site_name }}
                </div>
              </td>
              <td><VChip size="x-small" variant="tonal">{{ incident.incident_type }}</VChip></td>
              <td class="text-body-2">{{ formatDate(incident.occurred_at) }}</td>
              <td>
                <VChip :color="statusColour[incident.status] ?? 'grey'" size="x-small" variant="tonal">
                  {{ incident.status }}
                </VChip>
              </td>
              <td>
                <VChip
                  v-if="incident.is_reportable" color="error" size="x-small" variant="tonal"
                  prepend-icon="mdi-gavel"
                >{{ incident.statutory_category }}</VChip>
                <span v-else class="text-body-2 text-medium-emphasis">
                  {{ incident.statutory_category ?? '—' }}
                </span>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
/* Hover feedback on colour only — no transform, so a hovered tile never nudges the grid. */
.sheq-tile--link {
  cursor: pointer;
  transition: border-color 200ms ease, background-color 200ms ease;
}

.sheq-tile--link:hover {
  border-color: rgb(var(--v-theme-primary));
  background-color: rgba(var(--v-theme-primary), 0.04);
}

/* The card is wrapped in an anchor, so the focus ring has to be drawn on the card itself. */
a:focus-visible .sheq-tile--link {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  .sheq-tile--link { transition: none; }
}
</style>
