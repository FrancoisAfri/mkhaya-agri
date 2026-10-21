<script setup lang="ts">
/** One strip across the workforce screens, so a supervisor moves between them without the menu. */
import { Link } from '@inertiajs/vue3'
import { route } from 'ziggy-js'

defineProps<{ current: string }>()
const tabs = [
  { key: 'workforce', title: 'People & crews', route: 'attendance.people.index', icon: 'tabler-users' },
  { key: 'roster', title: 'Roster', route: 'attendance.roster.index', icon: 'tabler-calendar-time' },
  { key: 'attendance', title: 'Timesheets', route: 'attendance.timesheets.index', icon: 'tabler-clock' },
  { key: 'piecework', title: 'Piecework', route: 'attendance.piecework.index', icon: 'tabler-scale' },
  { key: 'analytics', title: 'Analytics', route: 'agri.workforce.analytics', icon: 'tabler-chart-bar' },
  { key: 'payroll', title: 'Payroll handoff', route: 'attendance.payroll-handoff.index', icon: 'tabler-receipt' },
  { key: 'settings', title: 'Time settings', route: 'attendance.time-settings', icon: 'tabler-adjustments' },
]
</script>

<template>
  <div class="d-flex flex-wrap gap-2 mb-5">
    <Link v-for="t in tabs" :key="t.key" :href="route(t.route)">
      <VChip :color="current === t.key ? 'primary' : undefined" :variant="current === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon">{{ t.title }}</VChip>
    </Link>
  </div>
</template>
