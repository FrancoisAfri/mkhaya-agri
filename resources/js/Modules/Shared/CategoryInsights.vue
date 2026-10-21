<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { hubCardIcon } from '@/utils/faToMdi'

/**
 * Insights for one menu category: every report, analytics/BI view and report schedule its modules
 * offer (each card opens the module's own page), shared-catalogue reports sent on a schedule, and
 * the datasets the organisation's own BI tools can read.
 */
const { t } = useI18n()

interface Card { module: string; module_code: string; title: string; desc: string; icon: string; path: string; color: string }
const props = defineProps<{
  category: { id: number; slug: string; name: string }
  groups: { reports: Card[]; analytics: Card[]; scheduled: Card[] }
  catalogue: { key: string; title: string; description: string; module: string; params: Record<string, string> }[]
  schedules: any[]
  farms: { value: string; title: string }[]
  bi: { datasets: { module: string; name: string; datasets: { key: string; name: string; table: string; columns: string[] }[] }[]; settings: string | null }
  options: { frequencies: string[]; periods: string[] }
}>()

const search = ref('')
const match = (c: Card) => !search.value || `${t(c.title)} ${t(c.desc)} ${c.module}`.toLowerCase().includes(search.value.toLowerCase())
const sections = computed(() => [
  { key: 'reports', title: 'Reports', icon: 'mdi-file-chart-outline', cards: props.groups.reports.filter(match) },
  { key: 'analytics', title: 'Analytics & BI dashboards', icon: 'mdi-chart-box-outline', cards: props.groups.analytics.filter(match) },
  { key: 'scheduled', title: 'Scheduled reports in each module', icon: 'mdi-calendar-clock-outline', cards: props.groups.scheduled.filter(match) },
])
const total = computed(() => props.groups.reports.length + props.groups.analytics.length + props.groups.scheduled.length)

const human = (s: string) => (s ?? '').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const when = (s: any) => s.frequency === 'daily' ? `Daily at ${s.send_at}` : s.frequency === 'weekly' ? `${WEEKDAYS[(s.weekday ?? 1) - 1]}s at ${s.send_at}` : `Day ${s.month_day} monthly at ${s.send_at}`
const at = (iso: string | null) => iso ? new Date(iso).toLocaleString() : '—'
const reportTitle = (key: string) => props.catalogue.find(r => r.key === key)?.title ?? key

// The schedule form.
const dialog = ref(false)
const form = ref<any>(null)
const blank = () => ({ id: null, report_key: props.catalogue[0]?.key ?? '', title: '', params: { farm: null }, period: 'last_month', format: 'xlsx',
  frequency: 'weekly', weekday: 1, month_day: 1, send_at: '07:00', recipients: [], active: true })
const openForm = (s: any = null) => { form.value = s ? { ...JSON.parse(JSON.stringify(s)), params: { farm: null, ...(s.params ?? {}) } } : blank(); dialog.value = true }
const chosen = computed(() => props.catalogue.find(r => r.key === form.value?.report_key))
const base = `/insights/${props.category.slug}/schedules`
const keep = { preserveScroll: true }
function save() {
  const body = { ...form.value, title: form.value.title || null }
  const done = { ...keep, onSuccess: () => { dialog.value = false } }
  form.value.id ? router.patch(`${base}/${form.value.id}`, body, done) : router.post(base, body, done)
}
const toggle = (s: any) => router.patch(`${base}/${s.id}`, { ...s, active: !s.active }, keep)
const sendNow = (s: any) => router.post(`${base}/${s.id}/send`, {}, keep)
const remove = (s: any) => { if (confirm(`Remove the schedule “${s.title}”?`)) router.delete(`${base}/${s.id}`, keep) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ category.name }}: Insights</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">Every report, analytics view and schedule in {{ category.name }} that you can open, in one place. Each opens in its own module.</p>
      </div>
      <VTextField v-model="search" prepend-inner-icon="mdi-magnify" placeholder="Find a report" density="compact" variant="outlined" hide-details clearable style="max-width:280px" />
    </div>

    <template v-for="s in sections" :key="s.key">
      <div v-if="s.cards.length" class="mb-6">
        <div class="d-flex align-center gap-2 mb-3"><VIcon size="20">{{ s.icon }}</VIcon><h2 class="text-subtitle-1 font-weight-bold">{{ s.title }}</h2>
          <VChip size="x-small" variant="tonal">{{ s.cards.length }}</VChip></div>
        <VRow>
          <VCol v-for="c in s.cards" :key="c.path" cols="12" sm="6" md="4" lg="3">
            <VCard elevation="0" border rounded="lg" class="hub-card h-100 cursor-pointer" @click="router.visit(c.path)">
              <VCardText class="d-flex flex-column h-100">
                <div class="d-flex align-center gap-3 mb-2">
                  <VAvatar :color="c.color" variant="tonal" size="40"><VIcon size="22">{{ hubCardIcon(c.icon) }}</VIcon></VAvatar>
                  <span class="text-caption text-medium-emphasis">{{ c.module }}</span>
                </div>
                <div class="text-body-1 font-weight-semibold mb-1">{{ t(c.title) }}</div>
                <p class="text-caption text-medium-emphasis mb-0 flex-grow-1" style="white-space:normal">{{ t(c.desc) }}</p>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </div>
    </template>
    <VCard v-if="!total" elevation="0" border rounded="lg" class="mb-6">
      <VCardText class="text-center py-8 text-medium-emphasis">{{ t('hub_ui.no_access') }}</VCardText>
    </VCard>

    <VCard elevation="0" border rounded="lg" class="mb-6">
      <VCardItem>
        <VCardTitle class="d-flex align-center gap-2"><VIcon size="20">mdi-email-fast-outline</VIcon>Send a report on a schedule</VCardTitle>
        <VCardSubtitle style="white-space:normal">Runs as you, so it shows only what you may see, and is emailed as Excel or CSV.</VCardSubtitle>
        <template #append><VBtn color="primary" prepend-icon="mdi-plus" :disabled="!catalogue.length" @click="openForm()">New schedule</VBtn></template>
      </VCardItem>
      <VTable density="compact">
        <thead><tr><th>Report</th><th>When</th><th>Period</th><th>To</th><th>Last sent</th><th>Next</th><th /></tr></thead>
        <tbody>
          <tr v-for="s in schedules" :key="s.id" :class="s.active ? '' : 'text-medium-emphasis'">
            <td>{{ s.title }}<div class="text-caption">{{ reportTitle(s.report_key) }} · {{ s.format.toUpperCase() }}</div></td>
            <td>{{ when(s) }}<VChip v-if="!s.active" size="x-small" class="ms-1">Paused</VChip></td>
            <td>{{ human(s.period) }}</td>
            <td class="text-caption">{{ s.recipients.join(', ') }}</td>
            <td class="text-caption">{{ at(s.last_run_at) }}<div v-if="s.last_status === 'failed'" class="text-error">{{ s.last_error }}</div></td>
            <td class="text-caption">{{ s.active ? at(s.next_run_at) : '—' }}</td>
            <td class="text-end text-no-wrap">
              <VBtn size="x-small" variant="text" @click="sendNow(s)">Send now</VBtn>
              <VBtn size="x-small" variant="text" @click="openForm(s)">Edit</VBtn>
              <VBtn size="x-small" variant="text" @click="toggle(s)">{{ s.active ? 'Pause' : 'Resume' }}</VBtn>
              <VBtn size="x-small" variant="text" color="error" @click="remove(s)">Remove</VBtn>
            </td>
          </tr>
          <tr v-if="!schedules.length"><td colspan="7" class="text-center text-medium-emphasis py-4">
            {{ catalogue.length ? 'Nothing scheduled yet.' : 'No report here can be scheduled from Insights yet; use the module’s own scheduled reports above.' }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard v-if="bi.datasets.length" elevation="0" border rounded="lg">
      <VCardItem>
        <VCardTitle class="d-flex align-center gap-2"><VIcon size="20">mdi-database-export-outline</VIcon>Data for your own BI tools</VCardTitle>
        <VCardSubtitle style="white-space:normal">These {{ category.name }} datasets can be exported to your organisation's own data warehouse, for Power BI, Tableau or Databricks.</VCardSubtitle>
        <template #append><VBtn v-if="bi.settings" variant="tonal" :href="bi.settings" prepend-icon="mdi-cog-outline">Export settings</VBtn></template>
      </VCardItem>
      <VCardText>
        <div v-for="g in bi.datasets" :key="g.module" class="mb-2"><span class="font-weight-medium">{{ g.name }}:</span>
          <VChip v-for="d in g.datasets" :key="d.key" size="small" variant="tonal" class="ms-1 mb-1" :title="d.columns.join(', ')">{{ d.table }}</VChip></div>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="640">
      <VCard v-if="form" :title="form.id ? 'Edit schedule' : 'New schedule'">
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VSelect v-model="form.report_key" :items="catalogue.map(r => ({ title: r.title, value: r.key }))" label="Report" /></VCol>
            <VCol v-if="chosen" cols="12" class="text-caption text-medium-emphasis mt-n2 mb-2">{{ chosen.description }}</VCol>
            <VCol cols="12"><VTextField v-model="form.title" label="Email subject" :placeholder="chosen?.title" /></VCol>
            <VCol v-if="chosen?.params?.farm && farms.length" cols="12"><VSelect v-model="form.params.farm" :items="farms" label="Farm" clearable hint="All the farms you can see when empty" persistent-hint /></VCol>
            <VCol cols="6"><VSelect v-model="form.period" :items="options.periods.map(p => ({ title: human(p), value: p }))" label="Period covered" /></VCol>
            <VCol cols="6"><VSelect v-model="form.format" :items="[{ title: 'Excel', value: 'xlsx' }, { title: 'CSV', value: 'csv' }]" label="File" /></VCol>
            <VCol cols="4"><VSelect v-model="form.frequency" :items="options.frequencies.map(f => ({ title: human(f), value: f }))" label="Send" /></VCol>
            <VCol v-if="form.frequency === 'weekly'" cols="4"><VSelect v-model="form.weekday" :items="WEEKDAYS.map((d, i) => ({ title: d, value: i + 1 }))" label="On" /></VCol>
            <VCol v-if="form.frequency === 'monthly'" cols="4"><VTextField v-model.number="form.month_day" type="number" min="1" max="28" label="Day of month" /></VCol>
            <VCol cols="4"><VTextField v-model="form.send_at" type="time" label="At" /></VCol>
            <VCol cols="12"><VCombobox v-model="form.recipients" multiple chips closable-chips label="Email to" hint="Press Enter after each address" persistent-hint /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">Cancel</VBtn><VBtn color="primary" @click="save">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.hub-card { transition: box-shadow .15s, transform .15s; }
.hub-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.10) !important; transform: translateY(-2px); }
</style>
