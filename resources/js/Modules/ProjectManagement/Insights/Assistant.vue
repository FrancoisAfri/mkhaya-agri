<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute
const { smAndDown } = useDisplay()

type Insight = { key: string; severity: string; title: string; detail: string; evidence: string[] }

const props = defineProps<{
  insights: Insight[]
  canWrite: boolean
  projects: Array<{ id: string; label: string }>
}>()

const high = computed(() => props.insights.filter(i => i.severity === 'high'))

function icon(key: string) {
  return ({
    schedule: 'tabler-clock-exclamation', cost: 'tabler-coin', workload: 'tabler-users',
    evidence: 'tabler-eye-off', risk: 'tabler-alert-triangle', change: 'tabler-git-branch',
    issue: 'tabler-bug',
  } as Record<string, string>)[key] ?? 'tabler-bulb'
}

// ── Drafting ────────────────────────────────────────────────────────────────
const reportDialog = ref(false)
const reportProject = ref<string>('')
const reportDraft = ref<string>('')
const reportLoading = ref(false)
const reportUnavailable = ref(false)

async function draftReport() {
  if (!reportProject.value) return
  reportLoading.value = true
  reportDraft.value = ''
  reportUnavailable.value = false

  try {
    const res = await fetch(route('project-management.assistant.status', reportProject.value), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
      },
    })
    const body = await res.json()
    reportDraft.value = body.draft ?? ''
    reportUnavailable.value = !body.available
  } finally {
    reportLoading.value = false
  }
}

function copyDraft() {
  navigator.clipboard?.writeText(reportDraft.value)
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.assistant.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.assistant.subtitle') }}</p>
    </div>

    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle class="text-body-1">{{ t('project_management.assistant.what_i_found') }}</VCardTitle>
        <VCardSubtitle>
          {{ high.length
            ? t('project_management.assistant.n_need_attention', { n: high.length })
            : t('project_management.assistant.nothing_urgent') }}
        </VCardSubtitle>
        <template #append>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-file-text" @click="reportDialog = true">
            {{ t('project_management.assistant.draft_report') }}
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />

      <VList v-if="insights.length" density="comfortable">
        <VListItem v-for="(i, idx) in insights" :key="idx">
          <template #prepend>
            <VAvatar :color="i.severity === 'high' ? 'error' : 'warning'" variant="tonal">
              <VIcon :icon="icon(i.key)" />
            </VAvatar>
          </template>
          <VListItemTitle>{{ i.title }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">{{ i.detail }}</VListItemSubtitle>
          <!-- Every finding names what it is based on, so it can be checked rather than believed. -->
          <div v-if="i.evidence.length" class="d-flex gap-1 flex-wrap mt-1">
            <VChip v-for="e in i.evidence" :key="e" size="x-small" label variant="outlined">{{ e }}</VChip>
          </div>
        </VListItem>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-10">
        {{ t('project_management.assistant.all_clear') }}
        <div class="text-caption mt-1">{{ t('project_management.assistant.all_clear_hint') }}</div>
      </VCardText>
    </VCard>

    <!-- Said once, plainly, rather than leaving somebody to wonder why a button does nothing. -->
    <VAlert v-if="!canWrite" type="info" variant="tonal" density="compact">
      {{ t('project_management.assistant.no_provider') }}
    </VAlert>

    <VDialog v-model="reportDialog" :fullscreen="smAndDown" max-width="760">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.assistant.draft_report') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.assistant.draft_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAutocomplete v-model="reportProject"
                   :items="projects.map(p => ({ title: p.label, value: p.id }))"
                   :label="t('project_management.assistant.field_project')"
                   variant="outlined" density="compact" class="mb-3" />
          <VBtn color="primary" size="small" :loading="reportLoading"
                :disabled="!reportProject" @click="draftReport">
            {{ t('project_management.assistant.write_it') }}
          </VBtn>

          <VAlert v-if="reportUnavailable" type="warning" variant="tonal" density="compact" class="mt-3">
            {{ t('project_management.assistant.no_provider') }}
          </VAlert>

          <VTextarea v-if="reportDraft" v-model="reportDraft" class="mt-4"
                     :label="t('project_management.assistant.draft')"
                     variant="outlined" rows="14" />
          <p v-if="reportDraft" class="text-caption text-medium-emphasis mb-0">
            {{ t('project_management.assistant.draft_warning') }}
          </p>
        </VCardText>
        <VCardActions>
          <VBtn v-if="reportDraft" variant="text" prepend-icon="tabler-copy" @click="copyDraft">
            {{ t('project_management.assistant.copy') }}
          </VBtn>
          <VSpacer />
          <VBtn variant="text" @click="reportDialog = false">{{ t('project_management.actions.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
