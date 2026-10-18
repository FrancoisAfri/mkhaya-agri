<script setup lang="ts">
defineProps<{
  supplier: { id: number; company_name: string; supplier_number?: string }
  scorecard: {
    avg_score: number
    band: string
    open_scars: number
    open_risk_flags: number
    appraisals_count: number
    latest_appraisal_date: string | null
  }
  recentAppraisals: {
    id: number
    total_score: number
    status: string
    appraisal_date: string | null
    template?: { id: number; name: string } | null
  }[]
}>()

const bandColors: Record<string, string> = {
  A: 'success', B: 'info', C: 'warning', D: 'error',
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-1">{{ supplier.company_name }}</h1>
    <div class="text-medium-emphasis mb-4">
      Supplier Scorecard<span v-if="supplier.supplier_number"> · {{ supplier.supplier_number }}</span>
    </div>

    <!-- Header band + score -->
    <VCard border class="mb-4">
      <VCardText class="d-flex align-center flex-wrap gap-6">
        <div class="text-center">
          <VAvatar :color="bandColors[scorecard.band] ?? 'grey'" size="96" class="text-h3 font-weight-bold">
            {{ scorecard.band }}
          </VAvatar>
          <div class="text-caption text-medium-emphasis mt-2">Overall Rating</div>
        </div>
        <div>
          <div class="text-h2 font-weight-bold">{{ scorecard.avg_score }}</div>
          <div class="text-medium-emphasis">Average Appraisal Score</div>
          <VProgressLinear :model-value="scorecard.avg_score" :color="bandColors[scorecard.band] ?? 'grey'"
            height="10" rounded class="mt-2" style="min-width: 220px" />
        </div>
      </VCardText>
    </VCard>

    <!-- Stat cards -->
    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline text-medium-emphasis">Avg Appraisal</div>
            <div class="text-h4">{{ scorecard.avg_score }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline text-medium-emphasis">Open SCARs</div>
            <div class="text-h4" :class="scorecard.open_scars ? 'text-error' : ''">{{ scorecard.open_scars }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline text-medium-emphasis">Open Risk Flags</div>
            <div class="text-h4" :class="scorecard.open_risk_flags ? 'text-warning' : ''">{{ scorecard.open_risk_flags }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline text-medium-emphasis">Appraisals</div>
            <div class="text-h4">{{ scorecard.appraisals_count }}</div>
            <div class="text-caption text-medium-emphasis">Last: {{ scorecard.latest_appraisal_date ?? '—' }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent appraisals -->
    <VCard border>
      <VCardTitle>Recent Appraisals</VCardTitle>
      <VTable>
        <thead>
          <tr><th>Date</th><th>Template</th><th>Score</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-if="!recentAppraisals.length">
            <td colspan="4" class="text-center text-medium-emphasis py-8">No appraisals recorded yet.</td>
          </tr>
          <tr v-for="a in recentAppraisals" :key="a.id">
            <td>{{ a.appraisal_date ?? '—' }}</td>
            <td>{{ a.template?.name ?? '—' }}</td>
            <td><VChip size="small" color="primary" variant="tonal">{{ a.total_score }}</VChip></td>
            <td>{{ a.status ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
