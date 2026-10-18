<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

defineProps<{
  summary: any
  anomalyCount: number
  riskCount: number
  patternCount: number
}>()

const asking     = ref(false)
const question   = ref('')
const aiResponse = ref('')

async function askAI() {
  if (!question.value) return
  asking.value = true
  try {
    const res = await fetch(route('attendance.intelligence.ask'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content ?? '' },
      body: JSON.stringify({ question: question.value }),
    })
    const data = await res.json()
    aiResponse.value = data.answer ?? 'No response.'
  } finally {
    asking.value = false
  }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Attendance Intelligence</h4>
          <p class="text-medium-emphasis mb-0">AI-powered anomaly detection and pattern analysis</p>
        </div>
      </div>
    </div>

    <!-- Summary Cards -->
    <VRow class="mb-6">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('attendance.intelligence.anomalies'))">
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="error" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-alert-triangle" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Anomalies</p>
              <h5 class="text-h5">{{ anomalyCount }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('attendance.intelligence.risk-scores'))">
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="warning" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-flame" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Risk Profiles</p>
              <h5 class="text-h5">{{ riskCount }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('attendance.intelligence.patterns'))">
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="info" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-chart-dots" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Patterns</p>
              <h5 class="text-h5">{{ patternCount }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- AI Chat -->
    <VCard elevation="0" border>
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2 text-body-1 font-weight-semibold">
        <VIcon icon="tabler-robot" size="20" />
        Ask the AI
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VTextField
          v-model="question"
          label="Ask about attendance patterns, risks, or anomalies…"
          variant="outlined"
          density="comfortable"
          hide-details
          class="mb-3"
          @keydown.enter="askAI"
        />
        <VBtn color="primary" :loading="asking" :disabled="!question" @click="askAI">Ask</VBtn>

        <VAlert v-if="aiResponse" type="info" variant="tonal" class="mt-4">
          <template #prepend><VIcon icon="tabler-robot" /></template>
          {{ aiResponse }}
        </VAlert>
      </VCardText>
    </VCard>

    <!-- Quick Navigation -->
    <VRow class="mt-5">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('attendance.intelligence.anomalies'))">
          <VCardText class="pa-5 text-center">
            <VIcon icon="tabler-alert-triangle" size="36" color="error" class="mb-2" />
            <p class="text-body-2 font-weight-semibold mb-0">View Anomalies</p>
            <p class="text-caption text-medium-emphasis">Review detected irregularities</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('attendance.intelligence.risk-scores'))">
          <VCardText class="pa-5 text-center">
            <VIcon icon="tabler-flame" size="36" color="warning" class="mb-2" />
            <p class="text-body-2 font-weight-semibold mb-0">Risk Scores</p>
            <p class="text-caption text-medium-emphasis">Employee attendance risk profiles</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border class="cursor-pointer" @click="router.visit(route('attendance.intelligence.patterns'))">
          <VCardText class="pa-5 text-center">
            <VIcon icon="tabler-chart-dots" size="36" color="info" class="mb-2" />
            <p class="text-body-2 font-weight-semibold mb-0">Behaviour Patterns</p>
            <p class="text-caption text-medium-emphasis">Recurring attendance patterns</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
