<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Nudge = { id: number; type: string; priority: string; title: string; body: string | null; route: string | null; at: string }

const nudges = ref<Nudge[]>([])
const loading = ref(true)

async function load() {
  try {
    const { data } = await axios.get(route('performance.nudges.index'))
    nudges.value = data.nudges ?? []
  } catch (_) {
    nudges.value = []
  } finally {
    loading.value = false
  }
}

function dismiss(n: Nudge) {
  router.patch(route('performance.nudges.dismiss', n.id), {}, {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => (nudges.value = nudges.value.filter(x => x.id !== n.id)),
  })
}

function go(n: Nudge) {
  if (n.route) window.location.href = n.route
}

const icon: Record<string, string> = {
  checkin_overdue: 'tabler-calendar-exclamation',
  review_ready: 'tabler-clipboard-check',
  goal_at_risk: 'tabler-target-arrow',
  wellness_risk: 'tabler-heartbeat',
  feedback_gap: 'tabler-message',
}
const priorityColor: Record<string, string> = { high: 'error', normal: 'primary', low: 'grey' }

onMounted(load)
</script>

<template>
  <VCard v-if="loading || nudges.length" border class="mb-4">
    <VCardTitle class="text-subtitle-1 d-flex align-center gap-2">
      <VIcon icon="tabler-bulb" color="warning" /> Suggested Actions
      <VChip v-if="nudges.length" size="x-small" color="warning" variant="tonal">{{ nudges.length }}</VChip>
    </VCardTitle>
    <VCardText>
      <div v-if="loading" class="text-caption text-medium-emphasis">Loading…</div>
      <div v-for="n in nudges" :key="n.id" class="d-flex align-center gap-3 py-2 border-b">
        <VAvatar :color="priorityColor[n.priority]" variant="tonal" size="36" rounded>
          <VIcon :icon="icon[n.type] ?? 'tabler-bell'" size="20" />
        </VAvatar>
        <div class="flex-grow-1">
          <div class="text-body-2 font-weight-medium">{{ n.title }}</div>
          <div class="text-caption text-medium-emphasis">{{ n.body }}</div>
        </div>
        <VBtn v-if="n.route" size="x-small" variant="tonal" color="primary" @click="go(n)">Go</VBtn>
        <VBtn size="x-small" variant="text" icon="tabler-x" @click="dismiss(n)" />
      </div>
    </VCardText>
  </VCard>
</template>
