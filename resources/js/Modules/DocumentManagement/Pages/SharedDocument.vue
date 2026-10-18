<script setup lang="ts">
/**
 * A shared document, seen by somebody with no account here.
 *
 * Written for a reader who has never used this system and will not use it again: an auditor, a
 * bank, a client. No navigation, no branding they have to interpret, nothing they can click that
 * leads somewhere requiring a login. One document, and either a way to read it or a password field.
 *
 * Behind a password, the page shows nothing about the document at all — not even its title, which
 * is frequently the sensitive part.
 */
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

const props = defineProps<{
  token: string
  locked: boolean
  /** Which gate is being answered: the password, or a code sent to the recipient. */
  gate?: 'password' | 'code'
  recipient?: string | null
  code_sent?: boolean
  document: {
    title: string
    description: string | null
    extension: string | null
    size: number | null
    previewable: boolean
  } | null
  share: {
    allow_download: boolean
    expires_at: string | null
    note: string | null
  } | null
}>()

const form = useForm({ password: '' })
const codeForm = useForm({ code: '' })

function unlock() {
  form.post(route('documents.shared.unlock', props.token), { preserveScroll: true })
}

function sendCode() {
  // A POST rather than a link, so a mail scanner opening the page cannot trigger an email to the
  // recipient before they have read the first one.
  codeForm.post(route('documents.shared.send-code', props.token), { preserveScroll: true })
}

function verifyCode() {
  codeForm.post(route('documents.shared.verify-code', props.token), { preserveScroll: true })
}

const sizeLabel = (bytes: number | null) => {
  if (!bytes) return null

  const units = ['B', 'KB', 'MB', 'GB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++ }

  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`
}
</script>

<template>
  <div class="shared-page">
    <div class="shared-card">
      <!-- ── Locked ──────────────────────────────────────────────────────── -->
      <template v-if="locked && gate === 'code'">
        <VIcon size="40" icon="mdi-email-lock-outline" class="mb-3" />
        <h1 class="text-h6 font-weight-bold mb-1">{{ d('shared_code_title') }}</h1>
        <!--
          The address is masked: enough for the right person to recognise their own and not enough
          for the wrong person to learn it.
        -->
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ code_sent ? d('shared_code_sent_hint', { email: recipient }) : d('shared_code_hint', { email: recipient }) }}
        </p>

        <form v-if="code_sent" @submit.prevent="verifyCode">
          <VTextField
            v-model="codeForm.code" :label="d('shared_code')" density="comfortable" autofocus
            inputmode="numeric" autocomplete="one-time-code" maxlength="6"
            :error-messages="codeForm.errors.code"
          />
          <VBtn type="submit" color="primary" block :loading="codeForm.processing" class="mt-2">
            {{ d('shared_open') }}
          </VBtn>
          <VBtn variant="text" size="small" block class="mt-1" @click="sendCode">
            {{ d('shared_code_resend') }}
          </VBtn>
        </form>

        <VBtn
          v-else color="primary" block :loading="codeForm.processing" @click="sendCode"
        >{{ d('shared_code_send') }}</VBtn>

        <p v-if="!code_sent && codeForm.errors.code" class="text-caption text-error mt-2">
          {{ codeForm.errors.code }}
        </p>
      </template>

      <template v-else-if="locked">
        <VIcon size="40" icon="mdi-lock-outline" class="mb-3" />
        <h1 class="text-h6 font-weight-bold mb-1">{{ d('shared_locked_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-4">{{ d('shared_locked_hint') }}</p>

        <form @submit.prevent="unlock">
          <VTextField
            v-model="form.password" type="password" :label="d('shared_password')"
            density="comfortable" autofocus autocomplete="current-password"
            :error-messages="form.errors.password"
          />
          <VBtn type="submit" color="primary" block :loading="form.processing" class="mt-2">
            {{ d('shared_open') }}
          </VBtn>
        </form>
      </template>

      <!-- ── Open ────────────────────────────────────────────────────────── -->
      <template v-else-if="document">
        <div class="d-flex align-center ga-3 mb-4">
          <VIcon size="32" icon="mdi-file-document-outline" />
          <div class="flex-grow-1" style="min-inline-size:0">
            <h1 class="text-h6 font-weight-bold">{{ document.title }}</h1>
            <div class="text-caption text-medium-emphasis">
              <span v-if="document.extension">{{ document.extension.toUpperCase() }}</span>
              <span v-if="sizeLabel(document.size)"> · {{ sizeLabel(document.size) }}</span>
            </div>
          </div>
        </div>

        <p v-if="document.description" class="text-body-2 mb-4">{{ document.description }}</p>
        <p v-if="share?.note" class="text-body-2 text-medium-emphasis mb-4">{{ share.note }}</p>

        <!-- Rendered in an iframe pointed at the streaming route, which sets a sandbox CSP. The
             file is never inlined into this page's own document. -->
        <iframe
          v-if="document.previewable"
          :src="route('documents.shared.view', token)"
          class="preview" :title="document.title"
        />

        <div class="d-flex ga-2 flex-wrap mt-4">
          <VBtn
            v-if="share?.allow_download" color="primary" prepend-icon="mdi-download"
            :href="route('documents.shared.download', token)"
          >{{ d('shared_download') }}</VBtn>
          <VBtn
            v-else-if="!document.previewable" variant="tonal" prepend-icon="mdi-eye-outline"
            :href="route('documents.shared.view', token)" target="_blank" rel="noopener"
          >{{ d('shared_view') }}</VBtn>
        </div>

        <!-- Said plainly, because a link that stops working without warning reads as a fault. -->
        <p v-if="share?.expires_at" class="text-caption text-medium-emphasis mt-4">
          {{ d('shared_expires', { date: share.expires_at }) }}
        </p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.shared-page {
  display: flex;
  justify-content: center;
  padding: 2rem 1rem;
  min-block-size: 100vh;
}

.shared-card {
  inline-size: 100%;
  max-inline-size: 46rem;
}

.preview {
  inline-size: 100%;
  block-size: min(70vh, 40rem);
  border: 1px solid rgb(var(--v-border-color), 0.2);
  border-radius: 4px;
  background: #fff;
}
</style>
