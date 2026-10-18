<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// A dialog capped at 820px is a usable form on a laptop and a letterbox on a phone. The people
// this module is for are frequently holding one, so every dialog here goes full-screen below the
// `sm` breakpoint rather than shrinking a multi-column layout into a column of clipped fields.
const { smAndDown } = useDisplay()

type Signer = {
  id: string; order: number; name: string; email: string | null; role: string | null
  user_id: number | null; status: string; signed_at: string | null; reason: string | null
}

type Doc = {
  id: string; name: string; source: string; size: number | null; mime: string | null
  version?: number; revision?: string | null; can_sign?: boolean
  signatures?: { status: string | null; envelope: string | null; content_changed: boolean; signers: Signer[] }
}

const props = defineProps<{
  project: { id: string; code: string; name: string }
  documents: Doc[]
  store: string
  versioned: boolean
  signers: Array<{ user_id: number | null; name: string; email: string | null; role: string | null }>
  maxBytes: number
  can: { manage: boolean }
}>()

const uploadForm = useForm<{ file: File | null }>({ file: null })

function upload() {
  if (!uploadForm.file) return

  uploadForm.post(route('project-management.documents.store', props.project.id), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => uploadForm.reset(),
  })
}

const confirmer = ref()

function remove(doc: Doc) {
  confirmer.value.open({
    title: t('project_management.documents.confirm_remove_title'),
    message: t('project_management.documents.confirm_remove_named', { name: doc.name }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(
    route('project-management.documents.destroy', [props.project.id, doc.id]),
    { preserveScroll: true },
  ))
}

// ── Revisions ───────────────────────────────────────────────────────────────
const active = ref<Doc | null>(null)
const revisionOpen = ref(false)
const revisionForm = useForm<{ file: File | null; revision: string }>({ file: null, revision: '' })

function openRevision(doc: Doc) {
  active.value = doc
  revisionForm.reset()
  revisionForm.clearErrors()
  revisionOpen.value = true
}

function submitRevision() {
  if (!active.value || !revisionForm.file) return

  revisionForm.post(
    route('project-management.documents.versions.store', [props.project.id, active.value.id]),
    { preserveScroll: true, forceFormData: true, onSuccess: () => { revisionOpen.value = false } },
  )
}

// History is fetched when asked for rather than sent with every row: most visits never open it,
// and a project with forty drawings would otherwise carry forty chains it does not render.
const historyOpen = ref(false)
const historyLoading = ref(false)
const history = ref<Array<Record<string, any>>>([])

async function openHistory(doc: Doc) {
  active.value = doc
  history.value = []
  historyOpen.value = true
  historyLoading.value = true

  try {
    const res = await fetch(
      route('project-management.documents.history', [props.project.id, doc.id]),
      { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
    )
    history.value = res.ok ? (await res.json()).versions ?? [] : []
  } finally {
    historyLoading.value = false
  }
}

// ── Signatures ──────────────────────────────────────────────────────────────
const signatureOpen = ref(false)
const signatureForm = useForm<{ signers: Array<{ name: string; email: string | null; user_id: number | null; role: string | null }> }>({
  signers: [],
})

function openSignature(doc: Doc) {
  active.value = doc
  signatureForm.reset()
  signatureForm.clearErrors()
  signatureForm.signers = []
  signatureOpen.value = true
}

// The picker offers project members; anyone chosen keeps their user id where they have one, so
// they can sign in the application rather than being emailed a link.
const selectedSigners = computed({
  get: () => signatureForm.signers.map(s => s.name),
  set: (names: string[]) => {
    signatureForm.signers = names.map(name => {
      const member = props.signers.find(m => m.name === name)
      return {
        name,
        email: member?.email ?? null,
        user_id: member?.user_id ?? null,
        role: member?.role ?? null,
      }
    })
  },
})

function submitSignature() {
  if (!active.value) return

  signatureForm.post(
    route('project-management.documents.signature.send', [props.project.id, active.value.id]),
    { preserveScroll: true, onSuccess: () => { signatureOpen.value = false } },
  )
}

const signOpen = ref(false)
const signForm = useForm<{ typed_name: string }>({ typed_name: '' })

function openSign(doc: Doc) {
  active.value = doc
  signForm.reset()
  signForm.clearErrors()
  signOpen.value = true
}

function submitSign() {
  if (!active.value) return

  signForm.post(
    route('project-management.documents.sign', [props.project.id, active.value.id]),
    { preserveScroll: true, onSuccess: () => { signOpen.value = false } },
  )
}

const declineOpen = ref(false)
const declineForm = useForm<{ reason: string }>({ reason: '' })

function openDecline(doc: Doc) {
  active.value = doc
  declineForm.reset()
  declineForm.clearErrors()
  declineOpen.value = true
}

function submitDecline() {
  if (!active.value) return

  declineForm.post(
    route('project-management.documents.decline', [props.project.id, active.value.id]),
    { preserveScroll: true, onSuccess: () => { declineOpen.value = false } },
  )
}

function signatureColour(status: string | null | undefined) {
  return status === 'signed' ? 'success'
    : status === 'declined' ? 'error'
      : status === 'pending' ? 'warning' : 'default'
}

function humanSize(bytes: number | null) {
  if (!bytes) return ''
  return bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function humanDate(value: string | null) {
  return value ? new Date(value).toLocaleString() : ''
}

const maxMb = Math.round(props.maxBytes / 1024 / 1024)
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.documents.title') }}</h4>
        <!-- Says plainly where the files are going, so nobody is surprised later. -->
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t(`project_management.documents.stored_${store}`) }}
        </p>
      </div>
    </div>

    <VCard v-if="can.manage" border class="mb-4">
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VFileInput
          v-model="uploadForm.file"
          :label="t('project_management.documents.choose_file')"
          :hint="t('project_management.documents.max_size', { mb: maxMb })" persistent-hint
          :error-messages="uploadForm.errors.file"
          variant="outlined" density="compact" prepend-icon="" prepend-inner-icon="tabler-upload"
          style="min-width:320px"
        />
        <VBtn color="primary" :loading="uploadForm.processing" :disabled="!uploadForm.file" @click="upload">
          {{ t('project_management.documents.upload') }}
        </VBtn>
      </VCardText>
    </VCard>

    <VCard border>
      <VCardItem><VCardTitle class="text-body-1">{{ t('project_management.documents.files') }}</VCardTitle></VCardItem>
      <VDivider />

      <VList v-if="documents.length" density="comfortable">
        <VListItem v-for="doc in documents" :key="doc.id">
          <template #prepend><VIcon icon="tabler-file" class="me-2" /></template>
          <VListItemTitle class="d-flex align-center gap-2 flex-wrap">
            <a :href="route('project-management.documents.show', [project.id, doc.id])" target="_blank">
              {{ doc.name }}
            </a>
            <VChip v-if="versioned && doc.revision" size="x-small" variant="tonal" label>
              {{ t('project_management.documents.revision_short', { revision: doc.revision }) }}
            </VChip>
            <VChip
              v-if="versioned && doc.signatures?.status" size="x-small" label
              :color="signatureColour(doc.signatures.status)"
            >
              {{ t(`project_management.documents.signature_${doc.signatures.status}`) }}
            </VChip>
            <!-- Signed, but not against these bytes. Said out loud rather than shown as a tick. -->
            <VChip v-if="doc.signatures?.content_changed" size="x-small" color="warning" label>
              {{ t('project_management.documents.signature_stale') }}
            </VChip>
          </VListItemTitle>
          <VListItemSubtitle class="text-caption">
            {{ humanSize(doc.size) }}<template v-if="doc.mime"> · {{ doc.mime }}</template>
            <template v-if="versioned && doc.version">
              · {{ t('project_management.documents.version_n', { n: doc.version }) }}
            </template>
          </VListItemSubtitle>

          <!-- Who is on the round and where each of them got to. Shown inline because "sent for
               signature" without the names is the half of the answer nobody needs. -->
          <div v-if="doc.signatures?.signers?.length" class="d-flex align-center gap-1 flex-wrap mt-1">
            <VChip
              v-for="s in doc.signatures.signers" :key="s.id" size="x-small" variant="outlined" label
              :color="signatureColour(s.status)"
            >
              {{ s.order }}. {{ s.name }}
              <template v-if="s.status === 'signed'"> · {{ humanDate(s.signed_at) }}</template>
              <template v-else-if="s.status === 'declined'"> · {{ s.reason }}</template>
            </VChip>
          </div>

          <template #append>
            <VBtn
              v-if="doc.can_sign" color="primary" size="small" variant="tonal" class="me-2"
              prepend-icon="tabler-signature" @click="openSign(doc)"
            >
              {{ t('project_management.documents.sign') }}
            </VBtn>
            <VBtn
              v-if="doc.can_sign" size="small" variant="text" class="me-2" @click="openDecline(doc)"
            >
              {{ t('project_management.documents.decline') }}
            </VBtn>

            <VMenu v-if="versioned">
              <template #activator="{ props: menu }">
                <VBtn
                  v-bind="menu" icon="tabler-dots-vertical" variant="text" size="small"
                  :aria-label="t('project_management.documents.more_for', { name: doc.name })"
                />
              </template>
              <VList density="compact">
                <VListItem prepend-icon="tabler-history" @click="openHistory(doc)">
                  <VListItemTitle>{{ t('project_management.documents.history') }}</VListItemTitle>
                </VListItem>
                <VListItem v-if="can.manage" prepend-icon="tabler-file-upload" @click="openRevision(doc)">
                  <VListItemTitle>{{ t('project_management.documents.new_revision') }}</VListItemTitle>
                </VListItem>
                <VListItem v-if="can.manage" prepend-icon="tabler-signature" @click="openSignature(doc)">
                  <VListItemTitle>{{ t('project_management.documents.send_for_signature') }}</VListItemTitle>
                </VListItem>
                <VDivider v-if="can.manage" />
                <VListItem v-if="can.manage" prepend-icon="tabler-trash" @click="remove(doc)">
                  <VListItemTitle class="text-error">{{ t('project_management.actions.delete') }}</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>

            <VBtn
              v-else-if="can.manage" icon="tabler-trash" variant="text" size="small"
              :aria-label="t('project_management.documents.remove_named', { name: doc.name })"
              @click="remove(doc)"
            />
          </template>
        </VListItem>
      </VList>

      <VCardText v-else class="text-center text-medium-emphasis py-8">
        {{ t('project_management.documents.none') }}
      </VCardText>
    </VCard>

    <!-- ── New revision ──────────────────────────────────────────────────── -->
    <VDialog :fullscreen="smAndDown" v-model="revisionOpen" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.documents.new_revision') }}</VCardTitle>
          <VCardSubtitle>{{ active?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <!-- Why this is not just another upload, in the one place somebody will read it. -->
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.documents.revision_note') }}
          </VAlert>
          <VFileInput
            v-model="revisionForm.file"
            :label="t('project_management.documents.choose_file')"
            :error-messages="revisionForm.errors.file"
            variant="outlined" density="compact" prepend-icon="" prepend-inner-icon="tabler-upload"
            class="mb-3"
          />
          <VTextField
            v-model="revisionForm.revision"
            :label="t('project_management.documents.revision_label')"
            :hint="t('project_management.documents.revision_hint')" persistent-hint
            :error-messages="revisionForm.errors.revision"
            variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="revisionOpen = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="revisionForm.processing" :disabled="!revisionForm.file"
            @click="submitRevision"
          >
            {{ t('project_management.documents.upload') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Revision history ──────────────────────────────────────────────── -->
    <VDialog :fullscreen="smAndDown" v-model="historyOpen" max-width="720">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.documents.history') }}</VCardTitle>
          <VCardSubtitle>{{ active?.name }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <div v-if="historyLoading" class="text-center py-6">
            <VProgressCircular indeterminate color="primary" />
          </div>
          <VTable v-else-if="history.length" density="compact">
            <thead>
              <tr>
                <th>{{ t('project_management.documents.revision_label') }}</th>
                <th>{{ t('project_management.documents.file') }}</th>
                <th class="text-end">{{ t('project_management.documents.size') }}</th>
                <th>{{ t('project_management.documents.uploaded_at') }}</th>
                <th>{{ t('project_management.documents.superseded_at') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in history" :key="v.version">
                <td>
                  {{ v.revision || v.version }}
                  <VChip v-if="v.is_current" size="x-small" color="success" label class="ms-1">
                    {{ t('project_management.documents.current') }}
                  </VChip>
                </td>
                <td>{{ v.name }}</td>
                <td class="text-end">{{ humanSize(v.size) }}</td>
                <td>{{ humanDate(v.uploaded_at) }}</td>
                <td>{{ humanDate(v.superseded_at) || '—' }}</td>
              </tr>
            </tbody>
          </VTable>
          <p v-else class="text-center text-medium-emphasis py-6 mb-0">
            {{ t('project_management.documents.no_history') }}
          </p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="historyOpen = false">{{ t('project_management.actions.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Send for signature ────────────────────────────────────────────── -->
    <VDialog :fullscreen="smAndDown" v-model="signatureOpen" max-width="560">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.documents.send_for_signature') }}</VCardTitle>
          <VCardSubtitle>{{ active?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.documents.signature_note') }}
          </VAlert>
          <VAutocomplete
            v-model="selectedSigners"
            :items="signers.map(s => s.name)"
            :label="t('project_management.documents.signatories')"
            :hint="t('project_management.documents.signatories_hint')" persistent-hint
            :error-messages="signatureForm.errors.signers"
            multiple chips closable-chips variant="outlined" density="compact"
          />

          <VList v-if="signatureForm.signers.length" density="compact" class="mt-3">
            <VListItem v-for="(s, i) in signatureForm.signers" :key="s.name">
              <template #prepend><VAvatar size="24" color="primary" variant="tonal">{{ i + 1 }}</VAvatar></template>
              <VListItemTitle class="ms-2">{{ s.name }}</VListItemTitle>
              <VListItemSubtitle class="ms-2 text-caption">{{ s.email || s.role || '—' }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="signatureOpen = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="signatureForm.processing"
            :disabled="!signatureForm.signers.length" @click="submitSignature"
          >
            {{ t('project_management.documents.send') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Sign ──────────────────────────────────────────────────────────── -->
    <VDialog :fullscreen="smAndDown" v-model="signOpen" max-width="480">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.documents.sign') }}</VCardTitle>
          <VCardSubtitle>{{ active?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <p class="text-body-2 mb-4">{{ t('project_management.documents.sign_declaration') }}</p>
          <VTextField
            v-model="signForm.typed_name"
            :label="t('project_management.documents.typed_name')"
            :hint="t('project_management.documents.typed_name_hint')" persistent-hint
            :error-messages="signForm.errors.typed_name"
            variant="outlined" density="compact" autofocus
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="signOpen = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="primary" :loading="signForm.processing" :disabled="!signForm.typed_name"
            @click="submitSign"
          >
            {{ t('project_management.documents.sign') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Decline ───────────────────────────────────────────────────────── -->
    <VDialog :fullscreen="smAndDown" v-model="declineOpen" max-width="480">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.documents.decline') }}</VCardTitle>
          <VCardSubtitle>{{ active?.name }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextarea
            v-model="declineForm.reason"
            :label="t('project_management.documents.decline_reason')"
            :error-messages="declineForm.errors.reason"
            variant="outlined" density="compact" rows="3" autofocus
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="declineOpen = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn
            color="error" :loading="declineForm.processing" :disabled="!declineForm.reason"
            @click="submitDecline"
          >
            {{ t('project_management.documents.decline') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
