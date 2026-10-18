<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useAuditStore, type AuditLog, type AuditMeta, type ChartData, type AuditStats } from './Store/auditStore'
import Chart from 'chart.js/auto'

const { t } = useI18n()

const props = defineProps<{
  logs:            AuditLog[]
  meta:            AuditMeta
  chartData:       ChartData
  models:          string[]
  availableEvents: string[]
  stats:           AuditStats
}>()

const store = useAuditStore()

watch(() => props.logs,      () => store.setLogs(props.logs, props.meta), { immediate: true })
watch(() => props.chartData, (v) => { if (v) store.setChartData(v) },    { immediate: true })
watch(() => props.stats,     (v) => { if (v) store.setStats(v) },         { immediate: true })

const chartRef = ref<HTMLCanvasElement | null>(null)
let chartInstance: Chart | null = null

const buildChart = () => {
  if (!chartRef.value || !store.chartData) return
  if (chartInstance) chartInstance.destroy()
  chartInstance = new Chart(chartRef.value, {
    type: 'bar',
    data: {
      labels: store.chartData.labels,
      datasets: [{
        label: t('audit_logs_page.activity_logs_chart'),
        data:  store.chartData.counts,
        backgroundColor: 'rgba(99,102,241,0.7)',
        borderColor:     '#6366f1',
        borderWidth: 1,
        borderRadius: 6,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { title: (i) => i[0].label } },
      },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, ticks: { stepSize: 1 } },
      },
    },
  })
}

onMounted(() => buildChart())
watch(() => store.chartData, () => buildChart())

const eventColor: Record<string, string> = {
  created: 'emerald', updated: 'amber', deleted: 'red', login: 'blue',
  lockout: 'slate', submitted: 'blue', approved: 'emerald', rejected: 'red',
  paid: 'purple', cancelled: 'slate', item_added: 'amber', receipt_uploaded: 'indigo',
  ai_risk_scored: 'violet', policy_validated: 'orange',
  category_created: 'emerald', category_updated: 'amber', category_deleted: 'red',
  approval_step_created: 'emerald', approval_step_updated: 'amber', approval_step_deleted: 'red',
  policy_rule_linked: 'teal', policy_rule_unlinked: 'rose',
  delegation_created: 'cyan', delegation_revoked: 'rose',
}
const eventIcon: Record<string, string> = {
  created: 'fa-solid fa-plus', updated: 'fa-solid fa-pen', deleted: 'fa-solid fa-trash',
  login: 'fa-solid fa-right-to-bracket', lockout: 'fa-solid fa-lock',
  submitted: 'fa-solid fa-paper-plane', approved: 'fa-solid fa-check', rejected: 'fa-solid fa-ban',
  paid: 'fa-solid fa-money-bill', cancelled: 'fa-solid fa-xmark',
  item_added: 'fa-solid fa-cart-plus', receipt_uploaded: 'fa-solid fa-file-arrow-up',
  ai_risk_scored: 'fa-solid fa-robot', policy_validated: 'fa-solid fa-shield-halved',
  category_created: 'fa-solid fa-folder-plus', category_updated: 'fa-solid fa-folder-pen', category_deleted: 'fa-solid fa-folder-minus',
  approval_step_created: 'fa-solid fa-stairs', approval_step_updated: 'fa-solid fa-pen-to-square', approval_step_deleted: 'fa-solid fa-trash',
  policy_rule_linked: 'fa-solid fa-link', policy_rule_unlinked: 'fa-solid fa-link-slash',
  delegation_created: 'fa-solid fa-user-group', delegation_revoked: 'fa-solid fa-user-xmark',
}

const tailwindEventClasses: Record<string, string> = {
  created: 'bg-emerald-50 text-emerald-700', updated: 'bg-amber-50 text-amber-700',
  deleted: 'bg-red-50 text-red-600', login: 'bg-blue-50 text-blue-700',
}

const getEventClass = (e: string): string => {
  const key = e?.toLowerCase()
  if (tailwindEventClasses[key]) return tailwindEventClasses[key]
  if (key?.includes('creat') || key?.includes('linked') || key?.includes('approv')) return 'bg-emerald-50 text-emerald-700'
  if (key?.includes('updat')) return 'bg-amber-50 text-amber-700'
  if (key?.includes('delet') || key?.includes('reject') || key?.includes('revok') || key?.includes('cancel') || key?.includes('unlink')) return 'bg-red-50 text-red-600'
  if (key?.includes('paid') || key?.includes('delegat')) return 'bg-violet-50 text-violet-700'
  if (key?.includes('ai_') || key?.includes('risk')) return 'bg-purple-50 text-purple-700'
  if (key?.includes('submit')) return 'bg-blue-50 text-blue-700'
  return 'bg-slate-100 text-slate-600'
}
const getEventIcon = (e: string) => eventIcon[e?.toLowerCase()] ?? 'fa-solid fa-circle'

const drawerOpen = ref(false)
const activeLog  = ref<AuditLog | null>(null)

const openDrawer = (log: AuditLog) => {
  activeLog.value = log
  drawerOpen.value = true
}

const changesRows = computed(() => {
  const p = activeLog.value?.properties
  if (!p) return []
  const attrs = (p.attributes ?? {}) as Record<string, unknown>
  const old   = (p.old ?? {}) as Record<string, unknown>
  const keys  = new Set([...Object.keys(attrs), ...Object.keys(old)])
  return [...keys].map(k => ({
    field: k,
    before: old[k] ?? '—',
    after:  attrs[k] ?? '—',
    changed: JSON.stringify(old[k]) !== JSON.stringify(attrs[k]),
  }))
})

const exportPdf = () => {
  const params = new URLSearchParams({
    log_name:  store.filters.log_name,
    event:     store.filters.event,
    user:      store.filters.user,
    date_from: store.filters.date_from,
    date_to:   store.filters.date_to,
  })
  window.open(`/settings/audits/export-pdf?${params.toString()}`, '_blank')
}

const formatDate = (d: string) => {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-ZA', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

const initials = (log: AuditLog) =>
  ((log.firstname?.[0] ?? '') + (log.lastname?.[0] ?? '')).toUpperCase() || '?'

const fullName = (log: AuditLog) =>
  [log.firstname, log.lastname].filter(Boolean).join(' ') || 'System'

const shortModelName = (s: string) => s?.split('\\').pop() ?? s
</script>

<template>
  <section class="min-h-screen bg-slate-50">
    <div class="max-w-full px-4 sm:px-6 py-6">

      <!-- ══ Header ══ -->
      <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
        <div>
          <h1 class="text-xl font-bold text-slate-800">{{ t('audit_logs_page.title') }}</h1>
          <p class="text-sm text-slate-400 mt-0.5">{{ t('audit_logs_page.subtitle') }}</p>
        </div>
        <nav class="flex items-center gap-1.5 text-xs text-slate-400">
          <a href="/" class="hover:text-slate-600">{{ t('audit_logs_page.breadcrumb_home') }}</a>
          <i class="fa-solid fa-chevron-right text-[9px]" />
          <a href="/settings" class="hover:text-slate-600">{{ t('audit_logs_page.breadcrumb_settings') }}</a>
          <i class="fa-solid fa-chevron-right text-[9px]" />
          <span class="text-slate-600 font-medium">{{ t('audit_logs_page.breadcrumb_audits') }}</span>
        </nav>
      </div>

      <!-- ══ Stats row ══ -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-list-check text-indigo-500" />
          </div>
          <div>
            <p class="text-xl font-bold text-slate-800">{{ (stats?.total ?? 0).toLocaleString() }}</p>
            <p class="text-xs text-slate-400">{{ t('audit_logs_page.total') }}</p>
          </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-plus text-emerald-500" />
          </div>
          <div>
            <p class="text-xl font-bold text-slate-800">{{ (stats?.created ?? 0).toLocaleString() }}</p>
            <p class="text-xs text-slate-400">{{ t('audit_logs_page.created') }}</p>
          </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-pen text-amber-500" />
          </div>
          <div>
            <p class="text-xl font-bold text-slate-800">{{ (stats?.updated ?? 0).toLocaleString() }}</p>
            <p class="text-xs text-slate-400">{{ t('audit_logs_page.updated') }}</p>
          </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 flex items-center gap-3">
          <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-trash text-red-400" />
          </div>
          <div>
            <p class="text-xl font-bold text-slate-800">{{ (stats?.deleted ?? 0).toLocaleString() }}</p>
            <p class="text-xs text-slate-400">{{ t('audit_logs_page.deleted') }}</p>
          </div>
        </div>
      </div>

      <!-- ══ Chart ══ -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-5">
        <div class="flex items-center justify-between mb-4">
          <div>
            <p class="text-sm font-semibold text-slate-700">{{ t('audit_logs_page.activity_overview') }}</p>
            <p class="text-xs text-slate-400">{{ t('audit_logs_page.last_30_days') }}</p>
          </div>
          <i class="fa-solid fa-chart-column text-indigo-300 text-xl" />
        </div>
        <div style="height:180px">
          <canvas ref="chartRef" />
        </div>
      </div>

      <!-- ══ Filters ══ -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 mb-5">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">

          <div class="relative col-span-2 sm:col-span-1">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs" />
            <input
              :value="store.filters.user"
              type="text"
              :placeholder="t('audit_logs_page.search_user_placeholder')"
              class="w-full pl-8 pr-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-300"
              @input="store.setFilter('user', ($event.target as HTMLInputElement).value)"
            />
          </div>

          <select
            :value="store.filters.log_name"
            class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-300 bg-white"
            @change="store.setFilter('log_name', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">{{ t('audit_logs_page.all_modules') }}</option>
            <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
          </select>

          <select
            :value="store.filters.event"
            class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-300 bg-white"
            @change="store.setFilter('event', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">{{ t('audit_logs_page.all_events') }}</option>
            <option v-for="e in availableEvents" :key="e" :value="e">{{ e.replace(/_/g, ' ') }}</option>
          </select>

          <input
            :value="store.filters.date_from"
            type="date"
            class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-300"
            @change="store.setFilter('date_from', ($event.target as HTMLInputElement).value)"
          />

          <input
            :value="store.filters.date_to"
            type="date"
            class="w-full px-3 py-2 text-sm border border-slate-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-300"
            @change="store.setFilter('date_to', ($event.target as HTMLInputElement).value)"
          />

          <div class="flex gap-2">
            <button
              class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-semibold rounded-xl transition-colors"
              @click="store.resetFilters()"
            >
              <i class="fa-solid fa-rotate-left text-[10px]" /> {{ t('audit_logs_page.reset') }}
            </button>
            <button
              class="flex-1 flex items-center justify-center gap-1.5 px-3 py-2 bg-red-500 hover:bg-red-600 text-white text-xs font-semibold rounded-xl transition-colors"
              @click="exportPdf"
            >
              <i class="fa-solid fa-file-pdf text-[10px]" /> {{ t('audit_logs_page.pdf') }}
            </button>
          </div>

        </div>
      </div>

      <!-- ══ Table ══ -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">

        <div class="flex items-center justify-between px-5 py-3 border-b border-slate-100">
          <p class="text-xs text-slate-400 font-medium">
            {{ t('audit_logs_page.total_logs', { count: meta.total }) }}
          </p>
          <p class="text-xs text-slate-400">{{ t('audit_logs_page.page_of', { current: meta.current_page, last: meta.last_page }) }}</p>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-slate-50 border-b border-slate-100">
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide w-8">{{ t('audit_logs_page.table_number') }}</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('audit_logs_page.table_module') }}</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('audit_logs_page.table_event') }}</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide hidden md:table-cell">{{ t('audit_logs_page.table_description') }}</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide hidden sm:table-cell">{{ t('audit_logs_page.table_user') }}</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('audit_logs_page.table_date') }}</th>
                <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ t('audit_logs_page.table_changes') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(log, idx) in logs"
                :key="log.id"
                class="border-b border-slate-50 hover:bg-slate-50/60 transition-colors"
              >
                <td class="px-4 py-3 text-xs text-slate-400 font-mono">
                  {{ (meta.current_page - 1) * meta.per_page + idx + 1 }}
                </td>
                <td class="px-4 py-3">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-indigo-50 text-indigo-700 text-xs font-semibold rounded-lg">
                    <i class="fa-solid fa-database text-[9px]" />
                    {{ log.log_name }}
                  </span>
                </td>
                <td class="px-4 py-3">
                  <span :class="['inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold', getEventClass(log.event)]">
                    <i :class="[getEventIcon(log.event), 'text-[9px]']" />
                    {{ log.event?.replace(/_/g, ' ') || '—' }}
                  </span>
                </td>
                <td class="px-4 py-3 hidden md:table-cell text-slate-500 max-w-xs truncate text-xs">
                  {{ log.description }}
                </td>
                <td class="px-4 py-3 hidden sm:table-cell">
                  <div class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-[10px] font-bold flex-shrink-0">
                      {{ initials(log) }}
                    </div>
                    <span class="text-xs text-slate-600">{{ fullName(log) }}</span>
                  </div>
                </td>
                <td class="px-4 py-3 text-xs text-slate-500 whitespace-nowrap">
                  {{ formatDate(log.created_at) }}
                </td>
                <td class="px-4 py-3">
                  <button
                    v-if="log.properties && (log.properties.attributes || log.properties.old)"
                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors"
                    @click="openDrawer(log)"
                  >
                    <i class="fa-solid fa-code-compare text-[10px]" />
                    {{ t('audit_logs_page.diff') }}
                  </button>
                  <span v-else class="text-xs text-slate-300">—</span>
                </td>
              </tr>

              <tr v-if="!logs.length">
                <td colspan="7" class="text-center py-16 text-slate-400">
                  <i class="fa-solid fa-magnifying-glass text-4xl opacity-20 block mb-3" />
                  <p class="text-sm font-medium">{{ t('audit_logs_page.no_logs') }}</p>
                  <p class="text-xs mt-1">{{ t('audit_logs_page.no_logs_sub') }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div v-if="meta.last_page > 1" class="flex items-center justify-between px-5 py-3 border-t border-slate-100">
          <p class="text-xs text-slate-400">
            {{ t('audit_logs_page.showing', {
              from: (meta.current_page - 1) * meta.per_page + 1,
              to: Math.min(meta.current_page * meta.per_page, meta.total),
              total: meta.total.toLocaleString()
            }) }}
          </p>
          <div class="flex items-center gap-1">
            <button
              :disabled="meta.current_page === 1"
              :class="['w-8 h-8 rounded-lg flex items-center justify-center text-xs transition-colors',
                meta.current_page === 1 ? 'text-slate-300 cursor-not-allowed' : 'text-slate-600 hover:bg-slate-100']"
              @click="store.setFilter('page', meta.current_page - 1)"
            >
              <i class="fa-solid fa-chevron-left" />
            </button>

            <template v-for="p in meta.last_page" :key="p">
              <button
                v-if="p === 1 || p === meta.last_page || Math.abs(p - meta.current_page) <= 1"
                :class="['w-8 h-8 rounded-lg flex items-center justify-center text-xs font-medium transition-colors',
                  p === meta.current_page ? 'bg-indigo-500 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100']"
                @click="store.setFilter('page', p)"
              >{{ p }}</button>
              <span
                v-else-if="Math.abs(p - meta.current_page) === 2"
                class="w-8 h-8 flex items-center justify-center text-slate-300 text-xs"
              >…</span>
            </template>

            <button
              :disabled="meta.current_page === meta.last_page"
              :class="['w-8 h-8 rounded-lg flex items-center justify-center text-xs transition-colors',
                meta.current_page === meta.last_page ? 'text-slate-300 cursor-not-allowed' : 'text-slate-600 hover:bg-slate-100']"
              @click="store.setFilter('page', meta.current_page + 1)"
            >
              <i class="fa-solid fa-chevron-right" />
            </button>
          </div>
        </div>

      </div>
    </div>

    <!-- ══ Changes Drawer ══ -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="drawerOpen"
          class="fixed inset-0 bg-slate-900/40 z-40 backdrop-blur-sm"
          @click.self="drawerOpen = false"
        />
      </Transition>

      <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="translate-x-full"
        enter-to-class="translate-x-0"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-x-0"
        leave-to-class="translate-x-full"
      >
        <div
          v-if="drawerOpen && activeLog"
          class="fixed top-0 right-0 h-full w-full sm:w-[480px] bg-white shadow-2xl z-50 flex flex-col"
        >
          <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
              <p class="text-sm font-bold text-slate-800">{{ t('audit_logs_page.change_details') }}</p>
              <p class="text-xs text-slate-400 mt-0.5">{{ activeLog.log_name }} · {{ activeLog.event }} · {{ formatDate(activeLog.created_at) }}</p>
            </div>
            <button
              class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:bg-slate-100 transition-colors"
              @click="drawerOpen = false"
            >
              <i class="fa-solid fa-xmark" />
            </button>
          </div>

          <div class="px-6 py-4 bg-slate-50 border-b border-slate-100">
            <p class="text-xs text-slate-500 leading-relaxed">{{ activeLog.description }}</p>
            <div class="flex items-center gap-2 mt-3">
              <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-[10px] font-bold flex-shrink-0">
                {{ initials(activeLog) }}
              </div>
              <span class="text-xs font-medium text-slate-700">{{ fullName(activeLog) }}</span>
              <span :class="['ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold', getEventClass(activeLog.event)]">
                <i :class="[getEventIcon(activeLog.event), 'text-[8px]']" />
                {{ activeLog.event?.replace(/_/g, ' ') }}
              </span>
            </div>
          </div>

          <div class="flex-1 overflow-y-auto px-6 py-4">
            <div v-if="changesRows.length">
              <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">{{ t('audit_logs_page.field_changes') }}</p>
              <div class="space-y-2">
                <div
                  v-for="row in changesRows"
                  :key="row.field"
                  :class="['rounded-xl border p-3', row.changed ? 'border-amber-200 bg-amber-50/50' : 'border-slate-100 bg-white']"
                >
                  <p class="text-xs font-semibold text-slate-600 mb-2">
                    <i :class="['mr-1', row.changed ? 'fa-solid fa-arrows-rotate text-amber-500' : 'fa-solid fa-minus text-slate-300']" />
                    {{ row.field }}
                  </p>
                  <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-lg bg-red-50 border border-red-100 px-2.5 py-2">
                      <p class="text-[10px] font-semibold text-red-400 mb-1">{{ t('audit_logs_page.before') }}</p>
                      <p class="text-xs text-slate-700 break-all font-mono">
                        {{ row.before === null || row.before === undefined ? t('audit_logs_page.null_value') : String(row.before) || t('audit_logs_page.empty_value') }}
                      </p>
                    </div>
                    <div class="rounded-lg bg-emerald-50 border border-emerald-100 px-2.5 py-2">
                      <p class="text-[10px] font-semibold text-emerald-500 mb-1">{{ t('audit_logs_page.after') }}</p>
                      <p class="text-xs text-slate-700 break-all font-mono">
                        {{ row.after === null || row.after === undefined ? t('audit_logs_page.null_value') : String(row.after) || t('audit_logs_page.empty_value') }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="flex flex-col items-center justify-center h-32 text-slate-400">
              <i class="fa-solid fa-circle-info text-2xl mb-2 opacity-30" />
              <p class="text-xs">{{ t('audit_logs_page.no_field_changes') }}</p>
            </div>

            <details v-if="activeLog.properties" class="mt-6">
              <summary class="text-xs text-slate-400 cursor-pointer hover:text-slate-600">{{ t('audit_logs_page.raw_properties') }}</summary>
              <pre class="mt-2 text-[10px] bg-slate-50 border border-slate-200 rounded-xl p-3 overflow-x-auto text-slate-600 leading-relaxed">{{ JSON.stringify(activeLog.properties, null, 2) }}</pre>
            </details>
          </div>
        </div>
      </Transition>
    </Teleport>

  </section>
</template>
