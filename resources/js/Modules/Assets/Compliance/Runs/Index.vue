<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Run = {
  uuid: string
  scope: string
  total_checked: number
  pass_count: number
  fail_count: number
  overdue_count: number
  compliance_rate: number
  run_at: string
  runBy: { name: string } | null
}

const props = defineProps<{
  runs: {
    data: Run[]
    meta: { current_page: number; last_page: number; total: number }
    links: { prev: string | null; next: string | null }
  }
}>()

const showRunForm = ref(false)
const runForm = useForm({
  scope: 'all',
  asset_category_id: null as number | null,
})

const scopeOptions = computed(() => [
  { title: t('assets_compliance_runs_index.scopes.all'), value: 'all' },
  { title: t('assets_compliance_runs_index.scopes.category'), value: 'category' },
])

function submitRun() {
  runForm.post(route('assets.compliance-runs.store'), {
    onSuccess: () => { showRunForm.value = false; runForm.reset() },
  })
}

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function rateColor(rate: number) {
  if (rate >= 90) return 'success'
  if (rate >= 70) return 'warning'
  return 'error'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: t('assets_compliance_runs_index.breadcrumbs.compliance'), href: route('assets.compliance-dashboard.index') },
          { title: t('assets_compliance_runs_index.page_title') },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_compliance_runs_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_compliance_runs_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-player-play" @click="showRunForm = true">{{ t('assets_compliance_runs_index.run_check') }}</VBtn>
    </div>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_compliance_runs_index.table.date') }}</th>
            <th>{{ t('assets_compliance_runs_index.table.scope') }}</th>
            <th class="text-center">{{ t('assets_compliance_runs_index.table.rate') }}</th>
            <th class="text-right">{{ t('assets_compliance_runs_index.table.checked') }}</th>
            <th class="text-right">{{ t('assets_compliance_runs_index.table.pass') }}</th>
            <th class="text-right">{{ t('assets_compliance_runs_index.table.fail') }}</th>
            <th class="text-right">{{ t('assets_compliance_runs_index.table.overdue') }}</th>
            <th>{{ t('assets_compliance_runs_index.table.run_by') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in runs.data"
            :key="r.uuid"
            class="cursor-pointer"
            @click="router.visit(route('assets.compliance-runs.show', r.uuid))"
          >
            <td>{{ formatDate(r.run_at) }}</td>
            <td class="text-capitalize">{{ r.scope }}</td>
            <td class="text-center">
              <VChip size="small" :color="rateColor(r.compliance_rate)">{{ r.compliance_rate.toFixed(1) }}%</VChip>
            </td>
            <td class="text-right">{{ r.total_checked }}</td>
            <td class="text-right text-success">{{ r.pass_count }}</td>
            <td class="text-right text-error">{{ r.fail_count }}</td>
            <td class="text-right text-warning">{{ r.overdue_count }}</td>
            <td>{{ r.runBy?.name ?? '—' }}</td>
          </tr>
          <tr v-if="!runs.data.length">
            <td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('assets_compliance_runs_index.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="runs.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!runs.links.prev" @click="router.visit(runs.links.prev!)">{{ t('assets_compliance_runs_index.pagination.prev') }}</VBtn>
        <span class="text-body-2 align-self-center">{{ t('assets_compliance_runs_index.pagination.page') }} {{ runs.meta.current_page }} / {{ runs.meta.last_page }}</span>
        <VBtn size="small" variant="tonal" :disabled="!runs.links.next" @click="router.visit(runs.links.next!)">{{ t('assets_compliance_runs_index.pagination.next') }}</VBtn>
      </VCardText>
    </VCard>

    <VDialog v-model="showRunForm" max-width="440">
      <VCard>
        <VCardTitle class="pa-6 pb-0">{{ t('assets_compliance_runs_index.dialog.title') }}</VCardTitle>
        <VCardText class="pa-6">
          <VForm @submit.prevent="submitRun">
            <VRow>
              <VCol cols="12">
                <VSelect
                  v-model="runForm.scope"
                  :items="scopeOptions"
                  item-title="title"
                  item-value="value"
                  :label="t('assets_compliance_runs_index.dialog.scope')"
                  :error-messages="runForm.errors.scope"
                />
              </VCol>
            </VRow>
            <div class="d-flex gap-3 mt-4">
              <VBtn type="submit" color="primary" :loading="runForm.processing">{{ t('assets_compliance_runs_index.dialog.run') }}</VBtn>
              <VBtn variant="text" @click="showRunForm = false">{{ t('assets_compliance_runs_index.dialog.cancel') }}</VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
