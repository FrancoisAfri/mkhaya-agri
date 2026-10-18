<script setup lang="ts">
/**
 * Issuing and withdrawing external links for one document.
 *
 * The link is shown once, on the flash that comes back from creating it, and never again. That is
 * the honest presentation of how it is stored — only its hash is kept — and it also stops the panel
 * from becoming a place where every link ever issued can be copied by anybody who can open the
 * document.
 *
 * Everything about a live link that somebody might need in order to decide whether to withdraw it is
 * shown instead: who it went to, when it lapses, whether it can be downloaded, and how often it has
 * been opened.
 */
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmAction from './ConfirmAction.vue'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface Grant {
  uuid: string
  subject_type: string
  subject_name: string | null
  can_download: boolean
  expires_at: string | null
  expired: boolean
  note: string | null
  granted_by: string | null
}

export interface Share {
  uuid: string
  recipient_email: string | null
  note: string | null
  expires_at: string | null
  allow_download: boolean
  max_downloads: number | null
  download_count: number
  view_count: number
  last_accessed_at: string | null
  state: string
  created_by: string | null
}

const props = defineProps<{
  documentUuid: string
  shares: Share[]
  grants: Grant[]
  subjects: { roles: string[]; departments: { id: number; name: string }[] }
  people: { id: number; name: string }[]
  canShare: boolean
  sharingEnabled: boolean
  maxDays: number
}>()

const page = usePage()

/** Shown once, straight after creating a link. Read from the flash, never from a prop. */
const issuedLink = computed(() => (page.props.flash as Record<string, string> | undefined)?.share_link ?? null)

const creating = ref(false)
const copied = ref(false)

const form = useForm({
  recipient_email: '',
  note: '',
  expires_days: props.maxDays,
  password: '',
  allow_download: false,
  max_downloads: '' as number | string,
  require_email_code: false,
})

// ── Internally ─────────────────────────────────────────────────────────────
const sharingInternally = ref(false)

const internalForm = useForm({
  subject_type: 'user',
  subject_id: null as number | null,
  subject_name: '' as string,
  can_download: false,
  expires_days: '' as number | string,
  note: '',
})

function shareInternally() {
  internalForm.post(route('document-management.grants.store', props.documentUuid), {
    preserveScroll: true,
    onSuccess: () => { sharingInternally.value = false; internalForm.reset() },
  })
}

function revokeGrant(grant: Grant) {
  router.delete(route('document-management.grants.destroy', grant.uuid), { preserveScroll: true })
}

/** The icon says what kind of audience a share reaches, which is the thing scanned for. */
const subjectIcon = (type: string) => ({
  user: 'mdi-account-outline',
  role: 'mdi-account-key-outline',
  department: 'mdi-domain',
  company: 'mdi-earth',
}[type] ?? 'mdi-account-outline')

function create() {
  form.post(route('document-management.shares.store', props.documentUuid), {
    preserveScroll: true,
    onSuccess: () => { creating.value = false; form.reset(); form.expires_days = props.maxDays },
  })
}

function revoke(share: Share) {
  router.delete(route('document-management.shares.destroy', share.uuid), { preserveScroll: true })
}

async function copy() {
  if (!issuedLink.value) return

  try {
    await navigator.clipboard.writeText(issuedLink.value)
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  } catch {
    // Clipboard access is refused in plenty of ordinary situations — an insecure origin, a
    // permission prompt declined. The link is on screen and selectable either way, so this is not
    // worth an error message.
  }
}

const look = (state: string) => ({
  active: { colour: 'success', label: d('share_state_active') },
  expired: { colour: 'warning', label: d('share_state_expired') },
  revoked: { colour: undefined, label: d('share_state_revoked') },
  exhausted: { colour: 'warning', label: d('share_state_exhausted') },
}[state] ?? { colour: undefined, label: state })
</script>

<template>
  <!-- ── Inside the organisation ──────────────────────────────────────────── -->
  <VCard elevation="0" border class="mb-4">
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-account-multiple-outline" />
      <span class="text-body-2 font-weight-medium">{{ d('sharing_internal_title') }}</span>
      <VSpacer />
      <VBtn
        v-if="canShare" size="small" variant="tonal" color="primary"
        prepend-icon="mdi-plus" @click="sharingInternally = true"
      >{{ d('share_internal_new') }}</VBtn>
    </VCardText>

    <VDivider />

    <VList v-if="grants.length" density="compact">
      <VListItem v-for="grant in grants" :key="grant.uuid">
        <template #prepend>
          <VIcon size="18" :icon="subjectIcon(grant.subject_type)" />
        </template>

        <VListItemTitle class="text-body-2">
          {{ grant.subject_name }}
          <VChip v-if="grant.expired" size="x-small" variant="tonal" class="ms-1">
            {{ d('share_state_expired') }}
          </VChip>
        </VListItemTitle>

        <VListItemSubtitle class="text-caption">
          {{ grant.can_download ? d('share_can_download') : d('share_view_only') }}
          <template v-if="grant.expires_at"> · {{ d('share_expires', { date: grant.expires_at }) }}</template>
          <template v-if="grant.note"> · {{ grant.note }}</template>
        </VListItemSubtitle>

        <template #append>
          <ConfirmAction
            v-if="canShare"
            :title="d('share_revoke_internal_title')"
            :body="d('share_revoke_internal_body', { who: grant.subject_name })"
            :consequence="d('share_revoke_internal_consequence')"
            :confirm-label="d('share_revoke')"
            @confirm="revokeGrant(grant)"
          >
            <template #activator="{ open }">
              <VBtn size="small" variant="text" @click="open">{{ d('share_revoke') }}</VBtn>
            </template>
          </ConfirmAction>
        </template>
      </VListItem>
    </VList>

    <VCardText v-else class="text-body-2 text-medium-emphasis">
      {{ d('share_internal_none') }}
    </VCardText>
  </VCard>

  <!-- ── Outside it ───────────────────────────────────────────────────────── -->
  <VCard elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-link-variant" />
      <span class="text-body-2 font-weight-medium">{{ d('sharing_title') }}</span>
      <VSpacer />
      <VBtn
        v-if="canShare && sharingEnabled" size="small" variant="tonal" color="primary"
        prepend-icon="mdi-plus" @click="creating = true"
      >{{ d('share_new') }}</VBtn>
    </VCardText>

    <VDivider />

    <!-- Shown once. Said plainly, so nobody goes looking for it later. -->
    <VCardText v-if="issuedLink" class="pb-2">
      <VAlert type="success" variant="tonal" density="compact">
        <div class="text-body-2 font-weight-medium mb-1">{{ d('share_link_once') }}</div>
        <div class="d-flex align-center ga-2">
          <code class="share-link">{{ issuedLink }}</code>
          <VBtn size="small" variant="text" :icon="copied ? 'mdi-check' : 'mdi-content-copy'"
            :aria-label="d('share_copy')" @click="copy" />
        </div>
      </VAlert>
    </VCardText>

    <VCardText v-if="!sharingEnabled" class="text-body-2 text-medium-emphasis">
      {{ d('sharing_off') }}
    </VCardText>

    <template v-else-if="shares.length">
      <VList density="compact">
        <VListItem v-for="share in shares" :key="share.uuid">
          <template #prepend>
            <VIcon size="18" :icon="share.allow_download ? 'mdi-download-outline' : 'mdi-eye-outline'" />
          </template>

          <VListItemTitle class="text-body-2">
            {{ share.recipient_email || d('share_no_recipient') }}
            <VChip size="x-small" variant="tonal" :color="look(share.state).colour" class="ms-1">
              {{ look(share.state).label }}
            </VChip>
          </VListItemTitle>

          <VListItemSubtitle class="text-caption">
            <span v-if="share.expires_at">{{ d('share_expires', { date: share.expires_at }) }}</span>
            <span> · {{ d('share_opened', { views: share.view_count, downloads: share.download_count }) }}</span>
            <span v-if="share.note"> · {{ share.note }}</span>
          </VListItemSubtitle>

          <template #append>
            <ConfirmAction
              v-if="canShare && share.state === 'active'"
              :title="d('share_revoke_link_title')"
              :body="d('share_revoke_link_body', { who: share.recipient_email })"
              :consequence="d('share_revoke_link_consequence')"
              :confirm-label="d('share_revoke')"
              @confirm="revoke(share)"
            >
              <template #activator="{ open }">
                <VBtn size="small" variant="text" @click="open">{{ d('share_revoke') }}</VBtn>
              </template>
            </ConfirmAction>
          </template>
        </VListItem>
      </VList>
    </template>

    <VCardText v-else class="text-body-2 text-medium-emphasis">
      {{ d('share_none') }}
    </VCardText>
  </VCard>

  <!-- ── Share internally ───────────────────────────────────────────────── -->
  <VDialog v-model="sharingInternally" max-width="520">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('share_internal_new') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <!-- Said before the picker: this opens one document to somebody who cannot otherwise open
             it, which is the point and also the risk. -->
        <div class="text-caption text-medium-emphasis">{{ d('share_internal_hint') }}</div>

        <VSelect
          v-model="internalForm.subject_type" density="comfortable" :label="d('share_with')"
          :items="[
            { value: 'user', title: d('share_with_user') },
            { value: 'department', title: d('share_with_department') },
            { value: 'role', title: d('share_with_role') },
            { value: 'company', title: d('share_with_company') },
          ]"
        />

        <VAutocomplete
          v-if="internalForm.subject_type === 'user'"
          v-model="internalForm.subject_id" :items="people" item-title="name" item-value="id"
          density="comfortable" :label="d('share_with_user')"
          :error-messages="internalForm.errors.subject_id"
        />

        <VSelect
          v-else-if="internalForm.subject_type === 'department'"
          v-model="internalForm.subject_id" :items="subjects.departments" item-title="name" item-value="id"
          density="comfortable" :label="d('share_with_department')"
        />

        <VSelect
          v-else-if="internalForm.subject_type === 'role'"
          v-model="internalForm.subject_name" :items="subjects.roles"
          density="comfortable" :label="d('share_with_role')"
        />

        <VTextField
          v-model="internalForm.expires_days" type="number" min="1" max="365"
          :label="d('share_expires_days')" density="comfortable" style="max-width:11rem"
          :hint="d('share_internal_expiry_hint')" persistent-hint
        />

        <VSwitch
          v-model="internalForm.can_download" :label="d('share_allow_download')"
          density="compact" hide-details color="primary"
        />

        <VTextField v-model="internalForm.note" :label="d('share_note')" density="comfortable" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="sharingInternally = false">{{ d('cancel') }}</VBtn>
        <VBtn
          color="primary" :loading="internalForm.processing"
          :disabled="internalForm.subject_type === 'user' && !internalForm.subject_id"
          @click="shareInternally"
        >{{ d('share_internal_confirm') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Issue a link ───────────────────────────────────────────────────── -->
  <VDialog v-model="creating" max-width="560">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('share_new') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VAlert type="warning" variant="tonal" density="compact">
          {{ d('share_warning') }}
        </VAlert>

        <VTextField
          v-model="form.recipient_email" type="email" :label="d('share_recipient')"
          density="comfortable" :hint="d('share_recipient_hint')" persistent-hint
          :error-messages="form.errors.recipient_email"
        />

        <div class="d-flex ga-3 flex-wrap">
          <VTextField
            v-model.number="form.expires_days" type="number" min="1" :max="maxDays"
            :label="d('share_expires_days')" density="comfortable" style="max-width:10rem"
            :hint="d('share_expires_hint', { max: maxDays })" persistent-hint
            :error-messages="form.errors.expires_days"
          />
          <VTextField
            v-model="form.max_downloads" type="number" min="1" :label="d('share_max_downloads')"
            density="comfortable" style="max-width:11rem"
            :hint="d('share_max_downloads_hint')" persistent-hint
            :disabled="!form.allow_download"
          />
        </div>

        <VTextField
          v-model="form.password" type="password" :label="d('share_password')"
          density="comfortable" :hint="d('share_password_hint')" persistent-hint
          :error-messages="form.errors.password"
          autocomplete="new-password"
        />

        <!--
          A password travels in the same email as the link, so whoever has the email has both. A
          code sent to the recorded address is a different claim: it proves control of that mailbox
          at the moment of opening. Only offered with an address to send it to.
        -->
        <VSwitch
          v-model="form.require_email_code" :label="d('share_require_code')"
          density="compact" hide-details color="primary"
          :disabled="!form.recipient_email"
        />
        <div class="text-caption text-medium-emphasis">
          {{ form.recipient_email ? d('share_require_code_hint') : d('share_require_code_needs_email') }}
        </div>

        <VSwitch
          v-model="form.allow_download" :label="d('share_allow_download')"
          density="compact" hide-details color="primary"
        />
        <div class="text-caption text-medium-emphasis">{{ d('share_allow_download_hint') }}</div>

        <VTextField v-model="form.note" :label="d('share_note')" density="comfortable" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="creating = false">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" :loading="form.processing" @click="create">{{ d('share_create') }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.share-link {
  font-size: 0.75rem;
  word-break: break-all;
  flex: 1 1 auto;
  min-inline-size: 0;
}
</style>
