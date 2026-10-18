<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

/**
 * The new hire's own page, opened from a link rather than a login.
 *
 * Deliberately plain: the person reading it has never seen this system, may be on a phone, and is
 * being asked for an ID copy. Anything that looks like an internal admin screen invites them to
 * wonder whether the link is genuine.
 */

type Task = { id: number; title: string; description: string | null; due_date: string | null; status: string }
type Doc = { id: number; label: string; required: boolean; status: string; submitted: boolean; rejection_reason: string | null }

const props = defineProps<{
  token: string
  person: { name: string; job_title: string | null; department: string | null; starts_on: string | null }
  tasks: Task[]
  documents: Doc[]
}>()

const { t } = useI18n()
const route = ziggyRoute

const uploadingFor = ref<number | null>(null)

function upload(doc: Doc, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) return

  uploadingFor.value = doc.id

  router.post(route('onboarding.portal.documents.upload', props.token), {
    document_id: doc.id,
    file,
  }, {
    forceFormData: true,
    preserveScroll: true,
    onFinish: () => { uploadingFor.value = null; input.value = '' },
  })
}

const statusColour = (status: string) =>
  status === 'approved' ? 'success' : status === 'rejected' ? 'error' : status === 'submitted' ? 'info' : 'default'
</script>

<template>
  <VContainer class="py-8" style="max-inline-size: 820px">
    <div class="mb-6">
      <h4 class="text-h4 mb-1">{{ t('onboarding_portal.welcome', { name: person.name }) }}</h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('onboarding_portal.intro') }}
      </p>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <VChip v-if="person.job_title" size="small" label>{{ person.job_title }}</VChip>
        <VChip v-if="person.department" size="small" label>{{ person.department }}</VChip>
        <VChip v-if="person.starts_on" size="small" label color="primary">
          {{ t('onboarding_portal.starts_on', { date: person.starts_on }) }}
        </VChip>
      </div>
    </div>

    <!-- Documents first: it is the only thing on this page the hire is actually blocked on. -->
    <VCard border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('onboarding_portal.documents_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('onboarding_portal.documents_sub') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VList v-if="documents.length" lines="two">
        <VListItem v-for="doc in documents" :key="doc.id">
          <VListItemTitle>
            {{ doc.label }}
            <VChip v-if="doc.required" size="x-small" label color="primary" class="ms-2">
              {{ t('onboarding_portal.required') }}
            </VChip>
          </VListItemTitle>
          <VListItemSubtitle>
            <span v-if="doc.rejection_reason" class="text-error">{{ doc.rejection_reason }}</span>
            <span v-else>{{ t(`onboarding_portal.status_${doc.status}`) }}</span>
          </VListItemSubtitle>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip size="small" label :color="statusColour(doc.status)">
                {{ t(`onboarding_portal.status_${doc.status}`) }}
              </VChip>
              <!-- A plain file input: the hire is likely on a phone, where the native picker is
                   the one control they already understand. -->
              <VBtn
                size="small" variant="tonal"
                :loading="uploadingFor === doc.id"
                @click="($refs['file' + doc.id] as any[])?.[0]?.click()"
              >
                {{ doc.submitted ? t('onboarding_portal.replace') : t('onboarding_portal.upload') }}
              </VBtn>
              <input
                :ref="'file' + doc.id" type="file" class="d-none"
                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                @change="upload(doc, $event)"
              >
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-medium-emphasis">
        {{ t('onboarding_portal.no_documents') }}
      </VCardText>
    </VCard>

    <VCard v-if="tasks.length" border>
      <VCardItem>
        <VCardTitle>{{ t('onboarding_portal.tasks_title') }}</VCardTitle>
        <VCardSubtitle>{{ t('onboarding_portal.tasks_sub') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VList lines="two">
        <VListItem v-for="task in tasks" :key="task.id">
          <VListItemTitle>{{ task.title }}</VListItemTitle>
          <VListItemSubtitle v-if="task.due_date">
            {{ t('onboarding_portal.by_date', { date: task.due_date }) }}
          </VListItemSubtitle>
          <template #append>
            <VChip size="small" label :color="task.status === 'completed' ? 'success' : 'default'">
              {{ t(`onboarding_portal.status_${task.status}`) }}
            </VChip>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <p class="text-caption text-medium-emphasis text-center mt-6 mb-0">
      {{ t('onboarding_portal.footer') }}
    </p>
  </VContainer>
</template>
