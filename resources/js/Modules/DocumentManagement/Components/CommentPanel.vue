<script setup lang="ts">
/**
 * The conversation about a document, next to the document.
 *
 * Open threads first, because the reason somebody opens this panel is to see what is still
 * outstanding — a settled thread from this morning is not it. Settled ones stay, collapsed, since
 * "why does clause 4 say this" is asked long after everybody involved has moved on.
 */
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface Thread {
  uuid: string
  body: string
  by: string | null
  at: string | null
  version: string | null
  resolved: boolean
  resolved_by: string | null
  replies: { uuid: string; body: string; by: string | null; at: string | null }[]
}

const props = defineProps<{
  documentUuid: string
  threads: Thread[]
  people: { id: number; name: string | null }[]
  versions: { uuid: string; label: string }[]
  currentUser: number | null
}>()

const showResolved = ref(false)
const replyingTo = ref<string | null>(null)

const open = computed(() => props.threads.filter(thread => !thread.resolved))
const resolved = computed(() => props.threads.filter(thread => thread.resolved))

const form = useForm({
  body: '',
  parent: null as string | null,
  version: null as string | null,
  mentions: [] as number[],
})

function post() {
  form.post(route('document-management.documents.comments.store', props.documentUuid), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      replyingTo.value = null
    },
  })
}

function reply(thread: Thread) {
  replyingTo.value = replyingTo.value === thread.uuid ? null : thread.uuid
  form.parent = replyingTo.value
  // A reply is about the thread, not about a version somebody picked for the last remark.
  form.version = null
}

function resolve(thread: Thread, reopen = false) {
  router.post(
    route('document-management.documents.comments.resolve', [props.documentUuid, thread.uuid]),
    { reopen },
    { preserveScroll: true },
  )
}
</script>

<template>
  <VCard elevation="0" border>
    <VCardTitle class="text-subtitle-1 d-flex align-center">
      {{ d('comments_title') }}
      <VChip v-if="open.length" size="x-small" variant="tonal" color="primary" class="ms-2">
        {{ open.length }}
      </VChip>
    </VCardTitle>
    <VCardSubtitle class="text-caption pb-2">{{ d('comments_subtitle') }}</VCardSubtitle>

    <VCardText>
      <!-- Saying something new. Kept at the top: it is what the panel is for. -->
      <VTextarea
        v-model="form.body" rows="2" auto-grow density="comfortable"
        :label="replyingTo ? d('comments_reply_label') : d('comments_add')"
        :error-messages="form.errors.body"
      />

      <div class="d-flex flex-wrap ga-2 align-center mb-2">
        <VSelect
          v-if="!replyingTo && versions.length > 1"
          v-model="form.version" density="compact" hide-details style="max-width:220px"
          :label="d('comments_about_version')" clearable
          :items="versions.map(v => ({ value: v.uuid, title: v.label }))"
        />
        <!--
          Naming somebody sends them a notification. Chosen from a list rather than typed, so a
          mention cannot silently fail to match a name and leave the author waiting for an answer.
        -->
        <VSelect
          v-model="form.mentions" density="compact" hide-details multiple chips closable-chips
          style="max-width:320px" :label="d('comments_notify')"
          :items="people.map(p => ({ value: p.id, title: p.name ?? '' }))"
        />
        <VSpacer />
        <VBtn
          v-if="replyingTo" size="small" variant="text"
          @click="replyingTo = null; form.parent = null"
        >{{ d('cancel') }}</VBtn>
        <VBtn
          color="primary" size="small" :loading="form.processing"
          :disabled="!form.body.trim()" @click="post"
        >{{ d('comments_post') }}</VBtn>
      </div>

      <VDivider class="my-3" />

      <p v-if="!threads.length" class="text-body-2 text-medium-emphasis">{{ d('comments_none') }}</p>

      <div v-for="thread in open" :key="thread.uuid" class="mb-4">
        <div class="d-flex align-start ga-2">
          <VIcon icon="mdi-comment-text-outline" size="18" class="mt-1" />
          <div class="flex-grow-1">
            <div class="text-body-2" style="white-space:pre-wrap">{{ thread.body }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ thread.by }} · {{ thread.at }}
              <VChip v-if="thread.version" size="x-small" variant="tonal" class="ms-1">
                {{ d('comments_on_version', { version: thread.version }) }}
              </VChip>
            </div>

            <div v-for="r in thread.replies" :key="r.uuid" class="mt-2 ps-4">
              <div class="text-body-2" style="white-space:pre-wrap">{{ r.body }}</div>
              <div class="text-caption text-medium-emphasis">{{ r.by }} · {{ r.at }}</div>
            </div>

            <div class="mt-1">
              <VBtn size="x-small" variant="text" @click="reply(thread)">{{ d('comments_reply') }}</VBtn>
              <VBtn size="x-small" variant="text" color="success" @click="resolve(thread)">
                {{ d('comments_resolve') }}
              </VBtn>
            </div>
          </div>
        </div>
      </div>

      <!--
        Settled threads are kept and folded away. Deleting them would take the answer to "why does
        this say what it says" with them, and showing them would bury the three that are still live.
      -->
      <template v-if="resolved.length">
        <VBtn size="small" variant="text" @click="showResolved = !showResolved">
          {{ showResolved ? d('comments_hide_resolved') : d('comments_show_resolved', { n: resolved.length }) }}
        </VBtn>

        <div v-if="showResolved" class="mt-2">
          <div v-for="thread in resolved" :key="thread.uuid" class="mb-3 text-medium-emphasis">
            <div class="d-flex align-start ga-2">
              <VIcon icon="mdi-check-circle-outline" size="18" color="success" class="mt-1" />
              <div class="flex-grow-1">
                <div class="text-body-2" style="white-space:pre-wrap">{{ thread.body }}</div>
                <div class="text-caption">
                  {{ thread.by }} · {{ thread.at }}
                  <template v-if="thread.resolved_by"> · {{ d('comments_settled_by', { who: thread.resolved_by }) }}</template>
                </div>
                <div v-for="r in thread.replies" :key="r.uuid" class="mt-1 ps-4">
                  <div class="text-body-2" style="white-space:pre-wrap">{{ r.body }}</div>
                  <div class="text-caption">{{ r.by }} · {{ r.at }}</div>
                </div>
                <VBtn size="x-small" variant="text" @click="resolve(thread, true)">{{ d('comments_reopen') }}</VBtn>
              </div>
            </div>
          </div>
        </div>
      </template>
    </VCardText>
  </VCard>
</template>
