<script setup lang="ts">
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  projects: Array<{ id: string; name: string; code: string }>
  selected: string | null
  result: { intact: boolean; events_checked: number; broken_at: string | null; reason: string | null } | null
}>()

const projectId = ref(props.selected ?? '')

watch(projectId, () => {
  router.get(route('project-management.evidence.audit'),
    { project_id: projectId.value || undefined },
    { preserveState: true, replace: true })
})
</script>

<template>
  <div class="pa-4">
    <h4 class="text-h4">{{ t('project_management.audit.title') }}</h4>
    <p class="text-body-2 text-medium-emphasis">{{ t('project_management.audit.subtitle') }}</p>

    <VCard border class="mb-4">
      <VCardText>
        <VAutocomplete
          v-model="projectId"
          :items="projects.map(p => ({ title: `${p.code} — ${p.name}`, value: p.id }))"
          :label="t('project_management.audit.select_project')"
          variant="outlined" density="compact" hide-details clearable style="max-width:420px"
        />
      </VCardText>
    </VCard>

    <VCard v-if="result" border>
      <VCardText>
        <div class="d-flex align-center gap-4">
          <VAvatar :color="result.intact ? 'success' : 'error'" variant="tonal" size="56" rounded>
            <VIcon :icon="result.intact ? 'tabler-shield-check' : 'tabler-shield-x'" size="30" />
          </VAvatar>
          <div>
            <div class="text-h6">
              {{ result.intact ? t('project_management.audit.intact') : t('project_management.audit.broken') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('project_management.audit.events_checked', { count: result.events_checked }) }}
            </div>
          </div>
        </div>

        <VAlert v-if="!result.intact" type="error" variant="tonal" class="mt-4">
          <div class="font-weight-medium mb-1">{{ t('project_management.audit.first_break') }}</div>
          <div class="text-body-2">{{ result.broken_at }}</div>
          <div class="text-body-2">{{ result.reason }}</div>
        </VAlert>

        <VAlert v-else type="success" variant="tonal" class="mt-4" density="compact">
          {{ t('project_management.audit.intact_explainer') }}
        </VAlert>
      </VCardText>
    </VCard>

    <VCard v-else border>
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ t('project_management.audit.pick_a_project') }}
      </VCardText>
    </VCard>
  </div>
</template>
