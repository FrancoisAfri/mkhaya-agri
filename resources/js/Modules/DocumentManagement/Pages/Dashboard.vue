<script setup lang="ts">
/**
 * The module's front page.
 *
 * Ordered by what somebody can do something about. The alerts come first because a lapsed
 * insurance certificate is the reason anybody opens this page in a hurry; the totals come last
 * because "1,482 documents" is a fact and not a task.
 *
 * Every number links to the list behind it. A dashboard that reports a count and leaves the reader
 * to go and find the eight documents it refers to has told them there is a problem and hidden the
 * problem.
 */
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

const props = defineProps<{
  stats: {
    overview: { total: number; this_month: number; archived: number; mine: number }
    alerts: { windows: { days: number; count: number }[]; expired: number }
    workflow: {
      awaiting_approval: number
      awaiting_signature: number
      changes_requested: number
      shared_externally: number
    }
    storage: {
      used: number
      quota: number
      enforced: boolean
      breakdown: { label: string; bytes: number }[]
    }
    my_actions: {
      approvals: { uuid: string; title: string; step: string; due_at: string | null; overdue: boolean }[]
      signatures: { uuid: string; title: string; status: string }[]
    }
    expiring_soon: {
      uuid: string; title: string; category: string | null
      expiry_date: string | null; days: number; expired: boolean; blocking: boolean
    }[]
    recent_activity: {
      action: string; at: string | null; user: string | null
      document: { uuid: string; title: string }
    }[]
    by_category: { name: string; colour: string | null; count: number }[]
    recently_added: { uuid: string; title: string; category: string | null; added: string | null }[]
    trend: { month: string; count: number }[]
  }
}>()

const bytes = (value: number) => {
  if (!value) return '0 B'

  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = value
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) { size /= 1024; unit++ }

  return `${size.toFixed(unit <= 1 ? 0 : 1)} ${units[unit]}`
}

const storagePercent = computed(() => {
  const { used, quota } = props.stats.storage

  return quota > 0 ? Math.min(100, Math.round((used / quota) * 100)) : 0
})

/** Amber from three-quarters, red at nine-tenths — before it is a problem, not once it is one. */
const storageColour = computed(() => {
  if (storagePercent.value >= 90) return 'error'
  if (storagePercent.value >= 75) return 'warning'

  return 'primary'
})

const overviewCards = computed(() => [
  { key: 'total', value: props.stats.overview.total, icon: 'mdi-file-document-multiple-outline', to: route('document-management.index') },
  { key: 'this_month', value: props.stats.overview.this_month, icon: 'mdi-calendar-month-outline', to: route('document-management.index') },
  { key: 'mine', value: props.stats.overview.mine, icon: 'mdi-account-outline', to: route('document-management.mine') },
  { key: 'archived', value: props.stats.overview.archived, icon: 'mdi-archive-outline', to: route('document-management.archived') },
])

const workflowCards = computed(() => [
  { key: 'awaiting_approval', value: props.stats.workflow.awaiting_approval, colour: 'primary', to: route('document-management.awaiting-approval') },
  { key: 'awaiting_signature', value: props.stats.workflow.awaiting_signature, colour: 'info', to: route('document-management.awaiting-signature') },
  { key: 'changes_requested', value: props.stats.workflow.changes_requested, colour: 'warning', to: route('document-management.index') },
  { key: 'shared_externally', value: props.stats.workflow.shared_externally, colour: undefined, to: route('document-management.index') },
])

const hasAlerts = computed(() =>
  props.stats.alerts.expired > 0 || props.stats.alerts.windows.some(w => w.count > 0),
)

const myActionCount = computed(() =>
  props.stats.my_actions.approvals.length + props.stats.my_actions.signatures.length,
)

/** The tallest bar in the trend, so the rest are drawn relative to it rather than to a guess. */
const trendPeak = computed(() => Math.max(1, ...props.stats.trend.map(m => m.count)))

/** Biggest category first, and everything sized against it. */
const categoryPeak = computed(() => Math.max(1, ...props.stats.by_category.map(c => c.count)))

/** How urgent an expiry is, said in colour as well as in words. */
const expiryColour = (days: number) => (days < 0 ? 'error' : days <= 7 ? 'error' : days <= 30 ? 'warning' : undefined)

const eventLabel = (action: string) => {
  const key = 'document_management.events.' + action

  return t(key) === key ? action.replace(/_/g, ' ') : t(key)
}

/** Whether the repository has anything in it at all — a new installation needs telling, not a grid of zeros. */
const isEmpty = computed(() => props.stats.overview.total === 0 && props.stats.overview.archived === 0)
</script>

<template>
  <div class="pa-4" style="max-width:1200px;margin:auto">
    <h1 class="text-h5 font-weight-bold">{{ d('dashboard_title') }}</h1>
    <p class="text-body-2 text-medium-emphasis mb-4">{{ d('dashboard_subtitle') }}</p>

    <!-- A repository with nothing in it needs telling, not a grid of zeros to interpret. -->
    <VCard v-if="isEmpty" elevation="0" border class="mb-4">
      <VCardText class="text-center py-8">
        <VIcon size="44" icon="mdi-folder-open-outline" class="mb-3" style="opacity:.4" />
        <div class="text-body-1 font-weight-medium mb-1">{{ d('dashboard_empty_title') }}</div>
        <div class="text-body-2 text-medium-emphasis mb-4">{{ d('dashboard_empty_hint') }}</div>
        <VBtn color="primary" variant="tonal" prepend-icon="mdi-cloud-upload-outline"
          :href="route('document-management.upload')">{{ d('dashboard_empty_action') }}</VBtn>
      </VCardText>
    </VCard>

    <!-- ── Waiting on you ───────────────────────────────────────────────────
         Before anything counted across the company. "12 awaiting approval" is a statistic;
         "two of them are yours" is the only thing on this page that says what to do next. -->
    <VCard v-if="myActionCount" elevation="0" border class="mb-4">
      <VCardText class="d-flex align-center ga-2 pb-2">
        <VIcon size="20" icon="mdi-account-clock-outline" color="primary" />
        <span class="text-body-2 font-weight-medium">{{ d('dashboard_my_actions') }}</span>
        <VChip size="x-small" variant="tonal" color="primary" class="ms-1">{{ myActionCount }}</VChip>
      </VCardText>
      <VDivider />
      <VList density="compact">
        <VListItem
          v-for="item in stats.my_actions.approvals" :key="'a' + item.uuid"
          :href="route('document-management.documents.show', item.uuid)"
        >
          <template #prepend>
            <VIcon size="18" icon="mdi-clipboard-check-outline" :color="item.overdue ? 'error' : 'primary'" />
          </template>
          <VListItemTitle class="text-body-2">{{ item.title }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ item.step }}
            <template v-if="item.due_at">
              · <span :class="item.overdue ? 'text-error' : ''">
                {{ item.overdue ? d('dashboard_overdue', { date: item.due_at }) : d('dashboard_due', { date: item.due_at }) }}
              </span>
            </template>
          </VListItemSubtitle>
        </VListItem>

        <VListItem
          v-for="item in stats.my_actions.signatures" :key="'s' + item.uuid"
          :href="route('document-management.documents.show', item.uuid)"
        >
          <template #prepend>
            <VIcon size="18" icon="mdi-draw-pen" color="info" />
          </template>
          <VListItemTitle class="text-body-2">{{ item.title }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">{{ d('signing.' + item.status) }}</VListItemSubtitle>
        </VListItem>
      </VList>
    </VCard>

    <!-- ── What needs attention ─────────────────────────────────────────────
         First, because a lapsed certificate is why anybody opens this in a hurry. -->
    <VCard v-if="hasAlerts" elevation="0" border class="mb-4">
      <VCardText class="d-flex align-center ga-2 pb-2">
        <VIcon size="20" icon="mdi-bell-alert-outline" color="warning" />
        <span class="text-body-2 font-weight-medium">{{ d('dashboard_alerts') }}</span>
      </VCardText>
      <VDivider />
      <VList density="compact">
        <VListItem
          v-if="stats.alerts.expired > 0"
          :href="route('document-management.expired')"
        >
          <template #prepend>
            <VIcon icon="mdi-calendar-remove" color="error" size="20" />
          </template>
          <VListItemTitle class="text-body-2">
            {{ d('dashboard_expired', { n: stats.alerts.expired }) }}
          </VListItemTitle>
        </VListItem>

        <VListItem
          v-for="window in stats.alerts.windows.filter(w => w.count > 0)"
          :key="window.days"
          :href="route('document-management.expiring')"
        >
          <template #prepend>
            <VIcon icon="mdi-calendar-clock" :color="window.days <= 7 ? 'error' : 'warning'" size="20" />
          </template>
          <VListItemTitle class="text-body-2">
            {{ d('dashboard_expiring_within', { n: window.count, days: window.days }) }}
          </VListItemTitle>
        </VListItem>
      </VList>
    </VCard>

    <VAlert v-else type="success" variant="tonal" density="compact" class="mb-4">
      {{ d('dashboard_nothing_expiring') }}
    </VAlert>

    <!-- ── Waiting on somebody ──────────────────────────────────────────── -->
    <div class="stat-grid mb-4">
      <VCard
        v-for="card in workflowCards" :key="card.key"
        elevation="0" border :href="card.to" class="stat-card"
      >
        <VCardText>
          <div class="text-h4 font-weight-bold" :class="card.colour ? `text-${card.colour}` : ''">
            {{ card.value }}
          </div>
          <div class="text-body-2 text-medium-emphasis">{{ d('dashboard_' + card.key) }}</div>
        </VCardText>
      </VCard>
    </div>

    <!-- ── The totals ───────────────────────────────────────────────────── -->
    <div class="stat-grid mb-4">
      <VCard
        v-for="card in overviewCards" :key="card.key"
        elevation="0" border :href="card.to" class="stat-card"
      >
        <VCardText class="d-flex align-center ga-3">
          <VIcon :icon="card.icon" size="28" class="text-medium-emphasis" />
          <div>
            <div class="text-h6 font-weight-bold">{{ card.value }}</div>
            <div class="text-caption text-medium-emphasis">{{ d('dashboard_' + card.key) }}</div>
          </div>
        </VCardText>
      </VCard>
    </div>

    <!-- ── The documents behind the expiry counts ───────────────────────────
         A count says there is a problem; the list says which certificate. -->
    <div class="two-up mb-4">
      <VCard v-if="stats.expiring_soon.length" elevation="0" border>
        <VCardText class="d-flex align-center ga-2 pb-2">
          <VIcon size="20" icon="mdi-calendar-clock" />
          <span class="text-body-2 font-weight-medium">{{ d('dashboard_expiring_next') }}</span>
          <VSpacer />
          <VBtn size="small" variant="text" :href="route('document-management.expiring')">
            {{ d('dashboard_see_all') }}
          </VBtn>
        </VCardText>
        <VDivider />
        <VList density="compact">
          <VListItem
            v-for="item in stats.expiring_soon" :key="item.uuid"
            :href="route('document-management.documents.show', item.uuid)"
          >
            <template #prepend>
              <VIcon size="18" :icon="item.expired ? 'mdi-calendar-remove' : 'mdi-calendar-clock'"
                :color="expiryColour(item.days)" />
            </template>
            <VListItemTitle class="text-body-2">
              {{ item.title }}
              <!-- A blocking document is the one to renew first: it is stopping other work. -->
              <VChip v-if="item.blocking" size="x-small" variant="tonal" color="error" class="ms-1">
                {{ d('dashboard_blocking') }}
              </VChip>
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              <span :class="expiryColour(item.days) ? `text-${expiryColour(item.days)}` : ''">
                {{ item.expired
                  ? d('dashboard_expired_days', { n: Math.abs(item.days) })
                  : d('dashboard_days_left', { n: item.days }) }}
              </span>
              <template v-if="item.expiry_date"> · {{ item.expiry_date }}</template>
              <template v-if="item.category"> · {{ item.category }}</template>
            </VListItemSubtitle>
          </VListItem>
        </VList>
      </VCard>

      <!-- ── What has been happening ────────────────────────────────────── -->
      <VCard v-if="stats.recent_activity.length" elevation="0" border>
        <VCardText class="d-flex align-center ga-2 pb-2">
          <VIcon size="20" icon="mdi-history" />
          <span class="text-body-2 font-weight-medium">{{ d('dashboard_recent_activity') }}</span>
          <VSpacer />
          <VBtn size="small" variant="text" :href="route('document-management.activity')">
            {{ d('dashboard_see_all') }}
          </VBtn>
        </VCardText>
        <VDivider />
        <VList density="compact">
          <VListItem
            v-for="(event, index) in stats.recent_activity" :key="index"
            :href="route('document-management.documents.show', event.document.uuid)"
          >
            <VListItemTitle class="text-body-2">
              <span class="font-weight-medium">{{ event.user ?? d('activity_system') }}</span>
              {{ eventLabel(event.action) }}
              {{ event.document.title }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">{{ event.at }}</VListItemSubtitle>
          </VListItem>
        </VList>
      </VCard>
    </div>

    <!-- ── What the repository holds, and what has been arriving ──────────── -->
    <div class="two-up mb-4">
      <VCard v-if="stats.by_category.length" elevation="0" border>
        <VCardText class="d-flex align-center ga-2 pb-2">
          <VIcon size="20" icon="mdi-shape-outline" />
          <span class="text-body-2 font-weight-medium">{{ d('dashboard_by_category') }}</span>
        </VCardText>
        <VDivider />
        <VCardText>
          <div v-for="row in stats.by_category" :key="row.name" class="mb-2">
            <div class="d-flex justify-space-between text-body-2">
              <span>{{ row.name }}</span>
              <span class="font-weight-medium">{{ row.count }}</span>
            </div>
            <VProgressLinear
              :model-value="(row.count / categoryPeak) * 100" height="5" rounded
              :color="row.colour ? undefined : 'primary'" :style="row.colour ? { color: row.colour } : undefined"
            />
          </div>
        </VCardText>
      </VCard>

      <VCard elevation="0" border>
        <VCardText class="d-flex align-center ga-2 pb-2">
          <VIcon size="20" icon="mdi-chart-timeline-variant" />
          <span class="text-body-2 font-weight-medium">{{ d('dashboard_trend') }}</span>
        </VCardText>
        <VDivider />
        <VCardText>
          <!-- Six months of arrivals. Every month is drawn, including the empty ones: a gap reads
               as missing data where a zero reads as a quiet month. -->
          <div class="trend">
            <div v-for="month in stats.trend" :key="month.month" class="trend-col">
              <div class="trend-bar-track">
                <div
                  class="trend-bar"
                  :style="{ height: Math.max(2, (month.count / trendPeak) * 100) + '%' }"
                  :aria-label="d('dashboard_trend_bar', { n: month.count, month: month.month })"
                />
              </div>
              <div class="text-caption text-medium-emphasis">{{ month.month }}</div>
              <div class="text-caption font-weight-medium">{{ month.count }}</div>
            </div>
          </div>

          <div v-if="stats.recently_added.length" class="mt-4">
            <div class="text-caption font-weight-medium text-medium-emphasis mb-1">
              {{ d('dashboard_recently_added') }}
            </div>
            <div v-for="doc in stats.recently_added" :key="doc.uuid" class="text-body-2">
              <a :href="route('document-management.documents.show', doc.uuid)">{{ doc.title }}</a>
              <span class="text-caption text-medium-emphasis"> · {{ doc.added }}</span>
            </div>
          </div>
        </VCardText>
      </VCard>
    </div>

    <!-- ── Storage ──────────────────────────────────────────────────────── -->
    <VCard elevation="0" border>
      <VCardText class="d-flex align-center ga-2 pb-2">
        <VIcon size="20" icon="mdi-database-outline" />
        <span class="text-body-2 font-weight-medium">{{ d('dashboard_storage') }}</span>
        <VSpacer />
        <span class="text-caption text-medium-emphasis">
          {{ bytes(stats.storage.used) }}<template v-if="stats.storage.quota"> / {{ bytes(stats.storage.quota) }}</template>
        </span>
      </VCardText>

      <VCardText class="pt-0">
        <VProgressLinear
          v-if="stats.storage.quota"
          :model-value="storagePercent" :color="storageColour" height="8" rounded
          :aria-label="d('dashboard_storage_used', { percent: storagePercent })"
          class="mb-3"
        />

        <div class="d-flex ga-4 flex-wrap">
          <div v-for="row in stats.storage.breakdown" :key="row.label">
            <div class="text-body-2 font-weight-medium">{{ bytes(row.bytes) }}</div>
            <div class="text-caption text-medium-emphasis">{{ d('storage_' + row.label) }}</div>
          </div>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.stat-grid {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}

.stat-card { text-decoration: none; color: inherit; }

.two-up {
  display: grid;
  gap: 1rem;
  grid-template-columns: minmax(0, 1fr);
  align-items: start;
}

@media (min-width: 960px) {
  .two-up { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

/* Six equal columns whatever the numbers are, so a quiet month is a short bar rather than a
   narrow one — the height carries the meaning and the width must not. */
.trend {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 0.5rem;
  text-align: center;
}

.trend-bar-track {
  block-size: 4.5rem;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.trend-bar {
  inline-size: 60%;
  background: rgb(var(--v-theme-primary));
  border-radius: 3px 3px 0 0;
  min-block-size: 2px;
}
</style>
