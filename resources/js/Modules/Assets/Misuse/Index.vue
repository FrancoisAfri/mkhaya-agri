<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Flag = {
  id: number
  uuid: string
  flag_type: { value: string; label: string }
  severity: { value: string; label: string; color: string }
  status: { value: string; label: string; color: string }
  description: string
  asset: { uuid: string; name: string; asset_tag: string | null } | null
  flaggedBy: { name: string } | null
  investigator: { name: string } | null
  detected_at: string
}

const props = defineProps<{
  flags: {
    data: Flag[]
    meta: { current_page: number; last_page: number; total: number }
    links: { prev: string | null; next: string | null }
  }
  stats: { open: number; investigating: number; confirmed: number }
  filters: { status?: string; flag_type?: string; severity?: string }
}>()

const statusFilter   = ref(props.filters.status ?? '')
const typeFilter     = ref(props.filters.flag_type ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const runDetecting   = ref(false)

const statusOptions = computed(() => [
  { title: t('assets_misuse_flags_index.statuses.all'), value: '' },
  { title: t('assets_misuse_flags_index.statuses.open'), value: 'open' },
  { title: t('assets_misuse_flags_index.statuses.investigating'), value: 'investigating' },
  { title: t('assets_misuse_flags_index.statuses.confirmed'), value: 'confirmed' },
  { title: t('assets_misuse_flags_index.statuses.dismissed'), value: 'dismissed' },
])

const typeOptions = computed(() => [
  { title: t('assets_misuse_flags_index.types.all'), value: '' },
  { title: t('assets_misuse_flags_index.types.after_hours_usage'), value: 'after_hours_usage' },
  { title: t('assets_misuse_flags_index.types.unauthorized_location'), value: 'unauthorized_location' },
  { title: t('assets_misuse_flags_index.types.rapid_movement'), value: 'rapid_movement' },
  { title: t('assets_misuse_flags_index.types.repeated_incidents'), value: 'repeated_incidents' },
  { title: t('assets_misuse_flags_index.types.other'), value: 'other' },
])

const severityOptions = computed(() => [
  { title: t('assets_misuse_flags_index.severities.all'), value: '' },
  { title: t('assets_misuse_flags_index.severities.low'), value: 'low' },
  { title: t('assets_misuse_flags_index.severities.medium'), value: 'medium' },
  { title: t('assets_misuse_flags_index.severities.high'), value: 'high' },
  { title: t('assets_misuse_flags_index.severities.critical'), value: 'critical' },
])

function applyFilters() {
  router.get(route('assets.misuse-flags.index'), {
    status:    statusFilter.value || undefined,
    flag_type: typeFilter.value || undefined,
    severity:  severityFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function runDetection() {
  runDetecting.value = true
  router.post(route('assets.misuse-detection.run'), {}, {
    onFinish: () => { runDetecting.value = false },
  })
}

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_misuse_flags_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_misuse_flags_index.description') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-radar" :loading="runDetecting" @click="runDetection">
          {{ t('assets_misuse_flags_index.run_detection') }}
        </VBtn>
        <VBtn :href="route('assets.misuse-detection-rules.index')" variant="tonal" prepend-icon="tabler-settings">
          {{ t('assets_misuse_flags_index.rules') }}
        </VBtn>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol cols="4">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_misuse_flags_index.stats.open') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.open }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="4">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_misuse_flags_index.stats.investigating') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.investigating }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="4">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_misuse_flags_index.stats.confirmed') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ stats.confirmed }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4 d-flex gap-3 flex-wrap">
        <VSelect v-model="statusFilter" :items="statusOptions" item-title="title" item-value="value" :label="t('assets_misuse_flags_index.table.status')" density="compact" hide-details style="max-width:160px" @update:model-value="applyFilters" />
        <VSelect v-model="typeFilter" :items="typeOptions" item-title="title" item-value="value" :label="t('assets_misuse_flags_index.table.type')" density="compact" hide-details style="max-width:200px" @update:model-value="applyFilters" />
        <VSelect v-model="severityFilter" :items="severityOptions" item-title="title" item-value="value" :label="t('assets_misuse_flags_index.table.severity')" density="compact" hide-details style="max-width:140px" @update:model-value="applyFilters" />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_misuse_flags_index.table.asset') }}</th>
            <th>{{ t('assets_misuse_flags_index.table.type') }}</th>
            <th>{{ t('assets_misuse_flags_index.table.severity') }}</th>
            <th>{{ t('assets_misuse_flags_index.table.status') }}</th>
            <th>{{ t('assets_misuse_flags_index.table.flagged_by') }}</th>
            <th>{{ t('assets_misuse_flags_index.table.investigator') }}</th>
            <th>{{ t('assets_misuse_flags_index.table.detected') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="f in flags.data"
            :key="f.uuid"
            class="cursor-pointer"
            @click="router.visit(route('assets.misuse-flags.show', f.uuid))"
          >
            <td>
              <p class="mb-0 font-weight-medium">{{ f.asset?.name ?? '—' }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ f.asset?.asset_tag }}</p>
            </td>
            <td class="text-capitalize">{{ f.flag_type.label }}</td>
            <td><VChip size="small" :color="f.severity.color">{{ f.severity.label }}</VChip></td>
            <td><VChip size="small" :color="f.status.color">{{ f.status.label }}</VChip></td>
            <td>{{ f.flaggedBy?.name ?? 'System' }}</td>
            <td>{{ f.investigator?.name ?? '—' }}</td>
            <td>{{ formatDate(f.detected_at) }}</td>
          </tr>
          <tr v-if="!flags.data.length">
            <td colspan="7" class="text-center text-medium-emphasis py-8">{{ t('assets_misuse_flags_index.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="flags.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!flags.links.prev" @click="router.visit(flags.links.prev!)">{{ t('assets_misuse_flags_index.pagination.prev') }}</VBtn>
        <span class="text-body-2 align-self-center">{{ t('assets_misuse_flags_index.pagination.page') }} {{ flags.meta.current_page }} / {{ flags.meta.last_page }}</span>
        <VBtn size="small" variant="tonal" :disabled="!flags.links.next" @click="router.visit(flags.links.next!)">{{ t('assets_misuse_flags_index.pagination.next') }}</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
