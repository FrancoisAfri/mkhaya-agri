<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  stats: {
    total_users: number
    active_users: number
    inactive_users: number
    pending_approvals: number
    approved_changes: number
    rejected_changes: number
    admin_users: number
    manager_users: number
    employee_users: number
  }
  recentActivities: {
    id: number
    type: string
    description: string
    user: string
    created_at: string
  }[]
  usersByRole: {
    labels: string[]
    counts: number[]
  }
  approvalsByMonth: {
    labels: string[]
    counts: number[]
  }
}>()

const activeTab = ref<'overview' | 'approvals' | 'access'>('overview')

const statCards = computed(() => [
  {
    title: t('user_report_page.total_users'),
    value: props.stats.total_users.toLocaleString(),
    icon: 'fa-users',
    color: 'bg-indigo-50 text-indigo-600',
    trend: '+12%',
  },
  {
    title: t('user_report_page.active_users'),
    value: props.stats.active_users.toLocaleString(),
    icon: 'fa-user-check',
    color: 'bg-emerald-50 text-emerald-600',
    trend: `${Math.round((props.stats.active_users / props.stats.total_users) * 100)}%`,
  },
  {
    title: t('user_report_page.pending_approvals'),
    value: props.stats.pending_approvals.toLocaleString(),
    icon: 'fa-clock',
    color: 'bg-amber-50 text-amber-600',
    highlight: props.stats.pending_approvals > 0,
  },
  {
    title: t('user_report_page.admin_users'),
    value: props.stats.admin_users.toLocaleString(),
    icon: 'fa-shield-halved',
    color: 'bg-purple-50 text-purple-600',
  },
])

const tabLabels: Record<string, string> = {
  overview: t('user_report_page.tab_overview'),
  approvals: t('user_report_page.tab_approvals'),
  access: t('user_report_page.tab_access'),
}

const formatDate = (d: string) => {
  if (!d) return '—'
  return new Date(d).toLocaleString('en-ZA', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

const getActivityIcon = (type: string): string => {
  const icons: Record<string, string> = {
    user_created: 'fa-user-plus',
    user_updated: 'fa-pen-to-square',
    user_deleted: 'fa-user-minus',
    approval_requested: 'fa-paper-plane',
    approval_approved: 'fa-check-circle',
    approval_rejected: 'fa-times-circle',
    access_changed: 'fa-lock',
  }
  return icons[type] || 'fa-circle'
}

const getActivityColor = (type: string): string => {
  const colors: Record<string, string> = {
    user_created: 'text-emerald-600 bg-emerald-50',
    user_updated: 'text-blue-600 bg-blue-50',
    user_deleted: 'text-red-600 bg-red-50',
    approval_requested: 'text-amber-600 bg-amber-50',
    approval_approved: 'text-emerald-600 bg-emerald-50',
    approval_rejected: 'text-red-600 bg-red-50',
    access_changed: 'text-purple-600 bg-purple-50',
  }
  return colors[type] || 'text-slate-600 bg-slate-50'
}
</script>

<template>
  <section class="min-h-screen bg-slate-50">
    <div class="max-w-full px-4 sm:px-6 py-6">

      <!-- ══ Header ══ -->
      <div class="flex items-end justify-between flex-wrap gap-3 mb-6">
        <div>
          <h1 class="text-xl font-bold text-slate-800">{{ t('user_report_page.title') }}</h1>
          <p class="text-sm text-slate-400 mt-0.5">{{ t('user_report_page.subtitle') }}</p>
        </div>
        <nav class="flex items-center gap-1.5 text-xs text-slate-400">
          <a href="/" class="hover:text-slate-600">{{ t('user_report_page.breadcrumb_home') }}</a>
          <i class="fa-solid fa-chevron-right text-[9px]" />
          <a href="/settings" class="hover:text-slate-600">{{ t('user_report_page.breadcrumb_settings') }}</a>
          <i class="fa-solid fa-chevron-right text-[9px]" />
          <span class="text-slate-600 font-medium">{{ t('user_report_page.breadcrumb_report') }}</span>
        </nav>
      </div>

      <!-- ══ Tabs ══ -->
      <div class="flex gap-1 mb-5 border-b border-slate-200">
        <button
          v-for="tab in (['overview', 'approvals', 'access'] as const)"
          :key="tab"
          :class="[
            'px-4 py-2.5 text-sm font-medium capitalize transition-all rounded-t-lg',
            activeTab === tab
              ? 'bg-white text-indigo-600 shadow-sm border border-slate-200 border-b-white -mb-px'
              : 'text-slate-500 hover:text-slate-700 hover:bg-slate-100'
          ]"
          @click="activeTab = tab"
        >
          <i
            :class="[
              'mr-1.5 text-xs',
              tab === 'overview' ? 'fa-solid fa-chart-pie' :
              tab === 'approvals' ? 'fa-solid fa-clipboard-list' : 'fa-solid fa-shield'
            ]"
          />
          {{ tabLabels[tab] }}
        </button>
      </div>

      <!-- ══ Overview Tab ══ -->
      <div v-if="activeTab === 'overview'">

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
          <div
            v-for="stat in statCards"
            :key="stat.title"
            class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5"
          >
            <div class="flex items-start justify-between">
              <div>
                <p class="text-xs text-slate-400 uppercase tracking-wide mb-1">{{ stat.title }}</p>
                <p class="text-2xl font-bold text-slate-800">{{ stat.value }}</p>
                <p v-if="stat.trend" class="text-xs text-emerald-500 mt-1">
                  <i class="fa-solid fa-arrow-up text-[9px] mr-0.5" />{{ stat.trend }}
                </p>
              </div>
              <div :class="['w-10 h-10 rounded-xl flex items-center justify-center', stat.color]">
                <i :class="`fa-solid ${stat.icon} text-lg`" />
              </div>
            </div>
            <div v-if="stat.highlight" class="mt-3">
              <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-100 text-amber-700 text-xs font-semibold rounded-full">
                <i class="fa-solid fa-exclamation-circle text-[9px]" />
                {{ t('user_report_page.requires_attention') }}
              </span>
            </div>
          </div>
        </div>

        <!-- User Distribution -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

          <!-- By Role -->
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">{{ t('user_report_page.users_by_role') }}</h3>
            <div class="space-y-3">
              <div
                v-for="(label, idx) in usersByRole.labels"
                :key="label"
                class="flex items-center justify-between"
              >
                <span class="text-sm text-slate-600">{{ label }}</span>
                <div class="flex items-center gap-3">
                  <span class="text-sm font-semibold text-slate-800">{{ usersByRole.counts[idx] }}</span>
                  <div class="w-24 h-2 bg-slate-100 rounded-full overflow-hidden">
                    <div
                      class="h-full rounded-full"
                      :class="{
                        'bg-indigo-500': label === 'Admin',
                        'bg-emerald-500': label === 'Manager',
                        'bg-amber-500': label === 'Employee',
                        'bg-slate-400': label === 'Contact'
                      }"
                      :style="{ width: `${(usersByRole.counts[idx] / stats.total_users) * 100}%` }"
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Status Distribution -->
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-700 mb-4">{{ t('user_report_page.account_status') }}</h3>
            <div class="flex items-center justify-center h-32">
              <div class="relative w-32 h-32">
                <svg viewBox="0 0 36 36" class="w-full h-full">
                  <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f1f5f9" stroke-width="3" />
                  <circle
                    cx="18" cy="18" r="15.9" fill="none"
                    stroke="#10b981" stroke-width="3"
                    :stroke-dasharray="`${(stats.active_users / stats.total_users) * 100}, 100`"
                    stroke-linecap="round"
                    transform="rotate(-90 18 18)"
                  />
                </svg>
                <div class="absolute inset-0 flex items-center justify-center">
                  <div class="text-center">
                    <p class="text-xl font-bold text-slate-800">{{ stats.active_users }}</p>
                    <p class="text-xs text-slate-400">{{ t('user_report_page.active_label') }}</p>
                  </div>
                </div>
              </div>
            </div>
            <div class="flex justify-center gap-6 mt-2">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
                <span class="text-xs text-slate-600">{{ t('user_report_page.active_with_count', { count: stats.active_users }) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-slate-300"></span>
                <span class="text-xs text-slate-600">{{ t('user_report_page.inactive_with_count', { count: stats.inactive_users }) }}</span>
              </div>
            </div>
          </div>

        </div>

      </div>

      <!-- ══ Approvals Tab ══ -->
      <div v-if="activeTab === 'approvals'">

        <!-- Approval Stats -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                <i class="fa-solid fa-clock text-amber-500" />
              </div>
              <div>
                <p class="text-2xl font-bold text-slate-800">{{ stats.pending_approvals }}</p>
                <p class="text-xs text-slate-400">{{ t('user_report_page.approval_stats_pending') }}</p>
              </div>
            </div>
          </div>
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center">
                <i class="fa-solid fa-check-circle text-emerald-500" />
              </div>
              <div>
                <p class="text-2xl font-bold text-slate-800">{{ stats.approved_changes }}</p>
                <p class="text-xs text-slate-400">{{ t('user_report_page.approval_stats_approved') }}</p>
              </div>
            </div>
          </div>
          <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center">
                <i class="fa-solid fa-times-circle text-red-500" />
              </div>
              <div>
                <p class="text-2xl font-bold text-slate-800">{{ stats.rejected_changes }}</p>
                <p class="text-xs text-slate-400">{{ t('user_report_page.approval_stats_rejected') }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Quick Links -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6">
          <h3 class="text-sm font-semibold text-slate-700 mb-3">{{ t('user_report_page.quick_actions') }}</h3>
          <div class="flex flex-wrap gap-3">
            <a
              href="/settings/approvals"
              class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-50 text-indigo-700 text-sm font-medium rounded-xl hover:bg-indigo-100 transition-colors"
            >
              <i class="fa-solid fa-clipboard-list" />
              {{ t('user_report_page.view_pending_approvals') }}
              <span v-if="stats.pending_approvals > 0" class="ml-1 px-1.5 py-0.5 bg-indigo-200 text-indigo-800 text-xs rounded-full">
                {{ stats.pending_approvals }}
              </span>
            </a>
            <a
              href="/settings/approvals?status=approved"
              class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-700 text-sm font-medium rounded-xl hover:bg-emerald-100 transition-colors"
            >
              <i class="fa-solid fa-check-circle" />
              {{ t('user_report_page.view_approved_changes') }}
            </a>
          </div>
        </div>

      </div>

      <!-- ══ Access Tab ══ -->
      <div v-if="activeTab === 'access'">

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 mb-6">
          <h3 class="text-sm font-semibold text-slate-700 mb-3">{{ t('user_report_page.access_control_overview') }}</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-4 bg-slate-50 rounded-xl">
              <p class="text-xs text-slate-400 mb-2">{{ t('user_report_page.role_distribution') }}</p>
              <div class="space-y-2">
                <div class="flex justify-between text-sm">
                  <span>{{ t('user_report_page.administrators') }}</span>
                  <span class="font-semibold">{{ stats.admin_users }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span>{{ t('user_report_page.managers') }}</span>
                  <span class="font-semibold">{{ stats.manager_users }}</span>
                </div>
                <div class="flex justify-between text-sm">
                  <span>{{ t('user_report_page.employees') }}</span>
                  <span class="font-semibold">{{ stats.employee_users }}</span>
                </div>
              </div>
            </div>
            <div class="p-4 bg-slate-50 rounded-xl">
              <p class="text-xs text-slate-400 mb-2">{{ t('user_report_page.quick_links') }}</p>
              <div class="space-y-2">
                <a href="/settings/user-access" class="flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-800">
                  <i class="fa-solid fa-shield text-xs" />
                  {{ t('user_report_page.manage_user_access') }}
                </a>
                <a href="/settings/roles" class="flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-800">
                  <i class="fa-solid fa-tags text-xs" />
                  {{ t('user_report_page.manage_roles') }}
                </a>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- ══ Recent Activities (Common) ══ -->
      <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden mt-6">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-slate-700">{{ t('user_report_page.recent_activities') }}</h3>
          <span class="text-xs text-slate-400">{{ t('user_report_page.activities_count', { count: recentActivities.length }) }}</span>
        </div>
        <div class="divide-y divide-slate-50">
          <div
            v-for="activity in recentActivities.slice(0, 8)"
            :key="activity.id"
            class="flex items-start gap-3 px-5 py-3 hover:bg-slate-50/60 transition-colors"
          >
            <div :class="['w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0', getActivityColor(activity.type)]">
              <i :class="`fa-solid ${getActivityIcon(activity.type)} text-xs`" />
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm text-slate-700">{{ activity.description }}</p>
              <p class="text-xs text-slate-400 mt-0.5">
                {{ t('user_report_page.by_user', { user: activity.user }) }} · {{ formatDate(activity.created_at) }}
              </p>
            </div>
          </div>
        </div>
        <div v-if="recentActivities.length === 0" class="text-center py-10 text-slate-400">
          <i class="fa-solid fa-list-check text-3xl opacity-20 mb-2" />
          <p class="text-sm">{{ t('user_report_page.no_recent_activities') }}</p>
        </div>
      </div>

    </div>
  </section>
</template>
