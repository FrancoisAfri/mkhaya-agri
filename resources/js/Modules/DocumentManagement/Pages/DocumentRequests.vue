<script setup lang="ts">
/**
 * Asking people for documents, and answering.
 *
 * Both directions on one screen, because they are the same person's two questions: what do I still
 * owe, and who still owes me. Two pages would mean the first gets checked and the second does not.
 *
 * What I owe comes first. It is the half somebody can act on immediately, and burying it under a
 * list of other people's obligations is how a request goes unanswered for three weeks.
 */
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface Owed {
  uuid: string; title: string; description: string | null; by: string | null
  due_at: string | null; overdue: boolean; folder: string | null; reminders: number
}

interface Asked {
  uuid: string; title: string; from: string | null; status: string
  due_at: string | null; overdue: boolean; reminders: number
  document: string | null; fulfilled_at: string | null
}

defineProps<{
  owed: Owed[]
  asked: Asked[]
  can_ask: boolean
  people: { id: number; name: string | null }[]
  folders: { uuid: string; name: string; depth: number }[]
  categories: { uuid: string; name: string }[]
}>()

const asking = ref(false)
const answering = ref<Owed | null>(null)

const askForm = useForm({
  title: '', description: '', people: [] as number[],
  folder: '', category: '', due_at: '',
})

const answerForm = useForm({ file: null as File | null })

function ask() {
  askForm.post(route('document-management.requests.store'), {
    preserveScroll: true,
    onSuccess: () => { asking.value = false; askForm.reset() },
  })
}

function answer() {
  if (!answering.value) return

  answerForm.post(route('document-management.requests.fulfil', answering.value.uuid), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { answering.value = null; answerForm.reset() },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ d('requests_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ d('requests_subtitle') }}</p>
      </div>
      <VSpacer />
      <VBtn v-if="can_ask" color="primary" prepend-icon="mdi-file-send-outline" @click="asking = true">
        {{ d('requests_ask') }}
      </VBtn>
    </div>

    <!-- ── What I owe ───────────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1 d-flex align-center">
        {{ d('requests_owed') }}
        <VChip v-if="owed.length" size="x-small" variant="tonal" color="primary" class="ms-2">{{ owed.length }}</VChip>
      </VCardTitle>

      <VList v-if="owed.length" density="comfortable">
        <VListItem v-for="request in owed" :key="request.uuid">
          <template #prepend>
            <VIcon
              size="20"
              :icon="request.overdue ? 'mdi-alert-circle-outline' : 'mdi-file-upload-outline'"
              :color="request.overdue ? 'error' : undefined"
            />
          </template>

          <VListItemTitle class="text-body-2 font-weight-medium">{{ request.title }}</VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ d('requests_asked_by', { who: request.by }) }}
            <template v-if="request.due_at">
              · <span :class="request.overdue ? 'text-error' : ''">{{ d('requests_due', { date: request.due_at }) }}</span>
            </template>
            <template v-if="request.description"> · {{ request.description }}</template>
          </VListItemSubtitle>

          <template #append>
            <VBtn size="small" variant="tonal" color="primary" @click="answering = request">
              {{ d('requests_upload') }}
            </VBtn>
          </template>
        </VListItem>
      </VList>

      <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('requests_owe_nothing') }}</VCardText>
    </VCard>

    <!-- ── What I am waiting on ─────────────────────────────────────────── -->
    <VCard v-if="asked.length" elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ d('requests_waiting') }}</VCardTitle>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ d('requests_what') }}</th>
            <th>{{ d('requests_from') }}</th>
            <th>{{ d('requests_state') }}</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="request in asked" :key="request.uuid">
            <td>{{ request.title }}</td>
            <td>{{ request.from }}</td>
            <td>
              <VChip
                size="x-small" variant="tonal"
                :color="request.status === 'fulfilled' ? 'success' : request.overdue ? 'error' : undefined"
              >
                {{ d('requests_status_' + request.status) }}
              </VChip>
              <span v-if="request.due_at && request.status === 'pending'" class="text-caption text-medium-emphasis ms-1">
                {{ d('requests_due', { date: request.due_at }) }}
              </span>
              <!--
                Said out loud rather than counted in somebody's head: "I have asked them three
                times" is the sentence that precedes escalating it.
              -->
              <span v-if="request.reminders" class="text-caption text-medium-emphasis ms-1">
                · {{ d('requests_reminded_n', { n: request.reminders }) }}
              </span>
            </td>
            <td class="text-right">
              <template v-if="request.status === 'pending'">
                <VBtn
                  size="small" variant="text"
                  @click="router.post(route('document-management.requests.remind', request.uuid), {}, { preserveScroll: true })"
                >{{ d('requests_remind') }}</VBtn>
                <VBtn
                  size="small" variant="text" color="error"
                  @click="router.post(route('document-management.requests.cancel', request.uuid), {}, { preserveScroll: true })"
                >{{ d('requests_cancel') }}</VBtn>
              </template>
              <VBtn
                v-else-if="request.document" size="small" variant="text"
                :href="route('document-management.documents.show', request.document)"
              >{{ d('view') }}</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- ── Asking ───────────────────────────────────────────────────────── -->
    <VDialog v-model="asking" max-width="600">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('requests_ask') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <VTextField
            v-model="askForm.title" :label="d('requests_what_field')" autofocus
            :error-messages="askForm.errors.title" :hint="d('requests_what_hint')" persistent-hint
          />
          <VTextarea v-model="askForm.description" rows="2" :label="d('requests_why')" />
          <VSelect
            v-model="askForm.people" multiple chips closable-chips
            :label="d('requests_who')" :error-messages="askForm.errors.people"
            :items="people.map(p => ({ value: p.id, title: p.name ?? '' }))"
          />
          <!--
            Where it should land, decided here rather than by the person answering. They should not
            have to learn the filing structure to reply to a request.
          -->
          <VSelect
            v-model="askForm.folder" clearable :label="d('requests_file_into')"
            :items="folders.map(f => ({ value: f.uuid, title: '　'.repeat(f.depth) + f.name }))"
          />
          <VSelect
            v-model="askForm.category" clearable :label="d('field_category')"
            :items="categories.map(c => ({ value: c.uuid, title: c.name }))"
          />
          <VTextField v-model="askForm.due_at" type="date" :label="d('requests_by_when')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="asking = false">{{ d('cancel') }}</VBtn>
          <VBtn color="primary" :loading="askForm.processing" @click="ask">{{ d('requests_send') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Answering ────────────────────────────────────────────────────── -->
    <VDialog :model-value="answering !== null" max-width="520" @update:model-value="answering = null">
      <VCard v-if="answering">
        <VCardTitle class="text-subtitle-1">{{ answering.title }}</VCardTitle>
        <VCardText>
          <p v-if="answering.description" class="text-body-2 text-medium-emphasis mb-3">
            {{ answering.description }}
          </p>
          <VFileInput
            v-model="answerForm.file" :label="d('requests_choose_file')"
            prepend-icon="mdi-paperclip" :error-messages="answerForm.errors.file"
          />
          <p v-if="answering.folder" class="text-caption text-medium-emphasis mt-2">
            {{ d('requests_will_file_into', { folder: answering.folder }) }}
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="answering = null">{{ d('cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="answerForm.processing" :disabled="!answerForm.file"
            @click="answer"
          >{{ d('requests_send_file') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
