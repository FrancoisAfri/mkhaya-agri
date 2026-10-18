<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Missing = { name: string; category: string; why: string }
type Deviation = {
  clause_name: string
  category: string
  found_text_excerpt: string
  deviation: string
  risk: 'low' | 'medium' | 'high'
}
type Analysis = {
  status: string
  summary: string | null
  missing_required: Missing[] | null
  deviations: Deviation[] | null
  provider: string | null
  model: string | null
  error: string | null
  analyzed_at: string | null
} | null

const props = defineProps<{
  contract: { id: number; contract_number: string | null; title: string | null }
  analysis: Analysis
}>()

const missing = computed(() => props.analysis?.missing_required ?? [])
const deviations = computed(() => props.analysis?.deviations ?? [])

const riskColor: Record<string, string> = { low: 'success', medium: 'warning', high: 'error' }

const analyzing = ref(false)

function label(c: string) {
  return (c ?? '').replace(/_/g, ' ')
}

function rerun() {
  analyzing.value = true
  router.post(route('contracts.analyze-clauses', props.contract.uuid), {}, {
    preserveScroll: true,
    onFinish: () => { analyzing.value = false },
  })
}
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay v-if="analyzing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="warning" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-scale" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('playbook_analysis.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">
            {{ contract.contract_number ?? '—' }} · {{ contract.title ?? 'Contract' }}
          </p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-arrow-left" :disabled="analyzing" @click="router.get(route('contracts.show', contract.uuid))">
          {{ t('playbook_analysis.back_to_contract') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-refresh" :loading="analyzing" :disabled="analyzing" @click="rerun">
          {{ t('playbook_analysis.rerun_analysis') }}
        </VBtn>
      </div>
    </div>

    <!-- No analysis yet -->
    <VCard v-if="!analysis" border>
      <VCardText class="text-center py-10">
        <VIcon icon="tabler-file-search" size="48" class="text-medium-emphasis mb-3" />
        <p class="text-body-1 mb-4">{{ t('playbook_analysis.no_analysis') }}</p>
        <VBtn color="primary" prepend-icon="tabler-player-play" :loading="analyzing" :disabled="analyzing" @click="rerun">
          {{ t('playbook_analysis.run_analysis') }}
        </VBtn>
      </VCardText>
    </VCard>

    <template v-else>
      <VAlert v-if="analysis.status === 'failed'" type="error" variant="tonal" class="mb-4">
        {{ analysis.error ?? t('playbook_analysis.analysis_failed') }}
      </VAlert>

      <!-- Summary -->
      <VCard v-if="analysis.summary" border class="mb-4">
        <VCardText>
          <div class="d-flex align-center justify-space-between mb-2">
            <h6 class="text-h6 mb-0">{{ t('playbook_analysis.summary') }}</h6>
            <VChip size="small" variant="tonal" :color="analysis.status === 'completed' ? 'success' : 'warning'">
              {{ t(`playbook_analysis.statuses.${analysis.status}`) }}
            </VChip>
          </div>
          <p class="text-body-2 mb-0">{{ analysis.summary }}</p>
          <p v-if="analysis.analyzed_at" class="text-caption text-medium-emphasis mt-2 mb-0">
            {{ t('playbook_analysis.analyzed_at', { date: analysis.analyzed_at }) }}
            <span v-if="analysis.provider"> · {{ t('playbook_analysis.provider_model', { provider: analysis.provider, model: analysis.model }) }}</span>
          </p>
        </VCardText>
      </VCard>

      <!-- Missing required clauses -->
      <VCard border class="mb-4">
        <VCardText>
          <h6 class="text-h6 mb-3">{{ t('playbook_analysis.missing_required') }}</h6>
          <div v-if="!missing.length" class="text-success d-flex align-center gap-2">
            <VIcon icon="tabler-circle-check" size="18" />
            <span class="text-body-2">{{ t('playbook_analysis.all_present') }}</span>
          </div>
          <div v-else class="d-flex flex-column gap-3">
            <div v-for="(m, i) in missing" :key="i" class="d-flex flex-column gap-1">
              <div class="d-flex align-center gap-2">
                <VChip color="error" size="small" variant="tonal" prepend-icon="tabler-alert-triangle">{{ m.name }}</VChip>
                <VChip size="x-small" variant="outlined" class="text-capitalize">{{ label(m.category) }}</VChip>
              </div>
              <p v-if="m.why" class="text-body-2 text-medium-emphasis mb-0 ms-1">{{ m.why }}</p>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Deviations -->
      <VCard border>
        <VCardText class="pb-0">
          <h6 class="text-h6 mb-0">{{ t('playbook_analysis.deviations') }}</h6>
        </VCardText>
        <VTable>
          <thead>
            <tr>
              <th>{{ t('playbook_analysis.table.clause') }}</th>
              <th>{{ t('playbook_analysis.table.category') }}</th>
              <th>{{ t('playbook_analysis.table.found_in_contract') }}</th>
              <th>{{ t('playbook_analysis.table.deviation') }}</th>
              <th>{{ t('playbook_analysis.table.risk') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!deviations.length">
              <td colspan="5" class="text-center text-medium-emphasis py-8">{{ t('playbook_analysis.no_deviations') }}</td>
            </tr>
            <tr v-for="(d, i) in deviations" :key="i">
              <td class="font-weight-medium">{{ d.clause_name }}</td>
              <td><VChip size="x-small" variant="outlined" class="text-capitalize">{{ label(d.category) }}</VChip></td>
              <td class="text-body-2 text-medium-emphasis" style="max-width:280px">{{ d.found_text_excerpt || '—' }}</td>
              <td class="text-body-2" style="max-width:320px">{{ d.deviation }}</td>
              <td>
                <VChip size="small" :color="riskColor[d.risk] ?? 'secondary'" variant="tonal" class="text-capitalize">
                  {{ t(`playbook_analysis.risks.${d.risk}`) }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
