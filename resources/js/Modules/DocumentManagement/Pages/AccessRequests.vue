<script setup lang="ts">
/**
 * People waiting to be let in.
 *
 * Granting creates the same internal share anybody could have made by hand, so there is still one
 * mechanism by which somebody comes to see a document and one place to look when asking why.
 *
 * A folder request grants what is filed in it now, not a standing rule — somebody asking to see
 * Procurement this week should not silently gain everything filed there next year.
 */
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

interface AccessRequest {
  uuid: string
  subject: string | null
  subject_type: string
  kind: string
  reason: string | null
  asked_at: string | null
  by: string | null
}

const props = defineProps<{
  requests: AccessRequest[]
  may_answer: boolean
  askable: {
    folders: { uuid: string; name: string; depth: number; open: boolean }[]
    documents: { uuid: string; title: string }[]
  }
}>()

// ── Asking, in bulk ────────────────────────────────────────────────────────

const asking = ref(false)

const askForm = useForm({
  folders: [] as string[],
  documents: [] as string[],
  reason: '',
})

/*
 * Folders they cannot already open come first — those are what somebody is here to ask for. The
 * rest stay on the list rather than being hidden, because "I thought I had that one" is worth being
 * able to check, but they are marked so the useful ones are not buried among them.
 */
const folderItems = computed(() => [
  ...props.askable.folders.filter(f => !f.open),
  ...props.askable.folders.filter(f => f.open),
].map(f => ({
  value: f.uuid,
  title: '　'.repeat(f.depth) + f.name,
  subtitle: f.open ? d('access_already_open') : undefined,
})))

const nothingChosen = computed(() => !askForm.folders.length && !askForm.documents.length)

function sendAsk() {
  askForm.post(route('document-management.access-requests.store-many'), {
    preserveScroll: true,
    onSuccess: () => { asking.value = false; askForm.reset() },
  })
}

const deciding = ref<AccessRequest | null>(null)
const decision = ref<'grant' | 'decline'>('grant')
const note = ref('')

function ask(request: AccessRequest, choice: 'grant' | 'decline') {
  deciding.value = request
  decision.value = choice
  note.value = ''
}

function submit() {
  if (!deciding.value) return

  const name = decision.value === 'grant'
    ? 'document-management.access-requests.grant'
    : 'document-management.access-requests.decline'

  router.post(route(name, deciding.value.uuid), { note: note.value }, {
    preserveScroll: true,
    onSuccess: () => { deciding.value = null },
  })
}
</script>

<template>
  <div class="pa-4" style="max-width:1000px;margin:auto">
    <div class="d-flex align-center flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ d('access_requests_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ d('access_requests_subtitle') }}</p>
      </div>
      <VSpacer />
      <!--
        Anybody who can use the module may ask. Requiring the right to *answer* requests before
        being allowed to *make* one would shut out exactly the people this exists for.
      -->
      <VBtn color="primary" prepend-icon="mdi-folder-key-outline" @click="asking = true">
        {{ d('access_ask_for') }}
      </VBtn>
    </div>

    <VCard v-if="may_answer" elevation="0" border>
      <VList v-if="requests.length" density="comfortable">
        <VListItem v-for="request in requests" :key="request.uuid">
          <template #prepend>
            <VIcon size="20" :icon="request.subject_type === 'folder' ? 'mdi-folder-outline' : 'mdi-file-document-outline'" />
          </template>

          <VListItemTitle class="text-body-2">
            <span class="font-weight-medium">{{ request.by }}</span>
            {{ d('access_asked_for') }}
            {{ request.subject }}
            <!--
              The two questions are answered from one queue and look alike in a list, so the one
              that is only asking for a copy says so. Approving them is not the same act.
            -->
            <VChip
              v-if="request.kind === 'download'" size="x-small" variant="tonal" color="primary" class="ms-2"
            >{{ d('access_kind_download') }}</VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ request.asked_at }}
            <template v-if="request.reason"> · {{ request.reason }}</template>
          </VListItemSubtitle>

          <template #append>
            <VBtn size="small" variant="text" @click="ask(request, 'decline')">{{ d('access_decline') }}</VBtn>
            <VBtn size="small" variant="tonal" color="primary" @click="ask(request, 'grant')">
              {{ d('access_grant') }}
            </VBtn>
          </template>
        </VListItem>
      </VList>

      <VCardText v-else class="text-body-2 text-medium-emphasis">{{ d('access_requests_none') }}</VCardText>
    </VCard>

    <VDialog :model-value="deciding !== null" max-width="480" @update:model-value="deciding = null">
      <VCard v-if="deciding">
        <VCardTitle class="text-body-1 font-weight-medium">
          {{ decision === 'grant' ? d('access_grant') : d('access_decline') }}
        </VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3">{{ deciding.subject }}</div>
          <!-- Said before the button: a folder grants what is in it now, and nothing later. -->
          <VAlert
            v-if="decision === 'grant' && deciding.subject_type === 'folder'"
            type="info" variant="tonal" density="compact" class="mb-3"
          >{{ d('access_folder_note') }}</VAlert>

          <VTextarea v-model="note" :label="d('access_note')" rows="2" density="comfortable" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deciding = null">{{ d('cancel') }}</VBtn>
          <VBtn :color="decision === 'grant' ? 'primary' : undefined" @click="submit">
            {{ decision === 'grant' ? d('access_grant') : d('access_decline') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

    <!-- ── Asking for access, several things at once ────────────────────── -->
    <VDialog v-model="asking" max-width="560">
      <VCard>
        <VCardTitle class="text-subtitle-1">{{ d('access_ask_for') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ d('access_ask_hint') }}</p>

          <VSelect
            v-model="askForm.folders" multiple chips closable-chips
            :label="d('access_ask_folders')" :items="folderItems"
            :error-messages="askForm.errors.folders"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
            </template>
          </VSelect>

          <!--
            Only documents already visible are offered. Listing the ones they cannot open would
            disclose the titles, and a title is often the sensitive part on its own.
          -->
          <VAutocomplete
            v-model="askForm.documents" multiple chips closable-chips
            :label="d('access_ask_documents')"
            :items="askable.documents.map(doc => ({ value: doc.uuid, title: doc.title }))"
          />

          <VTextarea
            v-model="askForm.reason" rows="2"
            :label="d('access_ask_reason')" :hint="d('access_ask_reason_hint')" persistent-hint
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="asking = false">{{ d('cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="askForm.processing" :disabled="nothingChosen"
            @click="sendAsk"
          >{{ d('access_ask_send') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>
