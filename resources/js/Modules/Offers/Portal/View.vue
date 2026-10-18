<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// ─── Props ────────────────────────────────────────────────────────────────────
type Props = {
  offer: {
    uuid: string
    status: string  // 'sent' | 'viewed' | 'accepted' | 'declined' | 'expired'
    job_title: string | null
    salary: number | null
    currency: string
    start_date: string | null
    expires_at: string | null
    notes: string | null
  }
  candidate: {
    full_name: string | null
    email: string | null
  }
  token: string
}

const props = defineProps<Props>()

// ─── State ────────────────────────────────────────────────────────────────────
const otpCode   = ref('')
const otpSent   = ref(false)
const loading   = ref<'otp' | 'accept' | 'decline' | null>(null)
const message   = ref<{ type: 'success' | 'error'; text: string } | null>(null)

// ─── Helpers ──────────────────────────────────────────────────────────────────
function getBaseUrl(): string {
  return `/portal/offers/${props.offer.uuid}`
}

function getCsrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
}

function formatSalary(amount: number | null, currency: string): string | null {
  if (amount === null) return null
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: currency ?? 'ZAR' }).format(amount)
}

const canAct = props.offer.status === 'sent' || props.offer.status === 'viewed'

// ─── Actions ──────────────────────────────────────────────────────────────────
async function sendOtp(): Promise<void> {
  loading.value = 'otp'
  message.value = null
  try {
    const res = await fetch(`${getBaseUrl()}/otp?token=${props.token}`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': getCsrfToken(),
        'Accept': 'application/json',
      },
    })
    const data = await res.json()
    if (res.ok) {
      otpSent.value = true
      message.value = { type: 'success', text: data.message ?? t('offers_pages.portal.messages.otp_sent') }
    } else {
      message.value = { type: 'error', text: data.message ?? t('offers_pages.portal.messages.error_generic') }
    }
  } catch {
    message.value = { type: 'error', text: t('offers_pages.portal.messages.network_error') }
  } finally {
    loading.value = null
  }
}

async function acceptOffer(): Promise<void> {
  if (!otpCode.value) return
  loading.value = 'accept'
  message.value = null
  try {
    const res = await fetch(`${getBaseUrl()}/accept?token=${props.token}`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': getCsrfToken(),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ otp: otpCode.value }),
    })
    const data = await res.json()
    if (res.ok) {
      message.value = { type: 'success', text: t('offers_pages.portal.messages.offer_accepted') }
      setTimeout(() => window.location.reload(), 1500)
    } else {
      message.value = { type: 'error', text: data.message ?? t('offers_pages.portal.messages.invalid_otp') }
    }
  } catch {
    message.value = { type: 'error', text: t('offers_pages.portal.messages.network_error') }
  } finally {
    loading.value = null
  }
}

async function declineOffer(): Promise<void> {
  loading.value = 'decline'
  message.value = null
  try {
    const res = await fetch(`${getBaseUrl()}/decline?token=${props.token}`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': getCsrfToken(),
        'Accept': 'application/json',
      },
    })
    const data = await res.json()
    if (res.ok) {
      message.value = { type: 'success', text: t('offers_pages.portal.messages.offer_declined') }
      setTimeout(() => window.location.reload(), 1500)
    } else {
      message.value = { type: 'error', text: data.message ?? t('offers_pages.portal.messages.error_generic') }
    }
  } catch {
    message.value = { type: 'error', text: t('offers_pages.portal.messages.network_error') }
  } finally {
    loading.value = null
  }
}
</script>

<template>
  <!-- Full-page centred wrapper — no app shell/layout -->
  <div
    style="
      min-height: 100vh;
      background: #f5f5f5;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    "
  >
    <VCard
      elevation="0"
      border
      style="max-width: 600px; width: 100%;"
    >
      <!-- ── Header ──────────────────────────────────────────────────────── -->
      <VCardText class="pa-8 pb-4 text-center">
        <VIcon
          icon="tabler-briefcase"
          size="48"
          color="primary"
          class="mb-4"
        />
        <h5 class="text-h5 font-weight-bold mb-1">{{ t('offers_pages.portal.title') }}</h5>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ offer.job_title ?? t('offers_pages.portal.subtitle') }}
        </p>
        <p v-if="candidate.full_name" class="text-caption text-medium-emphasis mt-1 mb-0">
          {{ t('offers_pages.portal.for_candidate', { name: candidate.full_name }) }}
        </p>
      </VCardText>

      <VDivider />

      <!-- ── Feedback alert ──────────────────────────────────────────────── -->
      <div v-if="message" class="pa-4 pb-0">
        <VAlert
          :type="message.type"
          variant="tonal"
          density="comfortable"
          closable
          @click:close="message = null"
        >
          {{ message.text }}
        </VAlert>
      </div>

      <!-- ── Status banners ──────────────────────────────────────────────── -->
      <div v-if="offer.status === 'accepted'" class="pa-4 pb-0">
        <VAlert
          type="success"
          variant="tonal"
          density="comfortable"
          icon="tabler-circle-check"
        >
          {{ t('offers_pages.portal.status.accepted') }}
        </VAlert>
      </div>

      <div v-else-if="offer.status === 'declined'" class="pa-4 pb-0">
        <VAlert
          color="error"
          variant="tonal"
          density="comfortable"
          icon="tabler-circle-x"
        >
          {{ t('offers_pages.portal.status.declined') }}
        </VAlert>
      </div>

      <div v-else-if="offer.status === 'expired'" class="pa-4 pb-0">
        <VAlert
          color="warning"
          variant="tonal"
          density="comfortable"
          icon="tabler-clock-off"
        >
          {{ t('offers_pages.portal.status.expired') }}
        </VAlert>
      </div>

      <!-- ── Offer details ───────────────────────────────────────────────── -->
      <VCardText class="pa-6 pb-2">
        <p class="text-caption text-uppercase text-medium-emphasis font-weight-bold mb-3 letter-spacing-widest">
          {{ t('offers_pages.portal.sections.offer_details') }}
        </p>
        <div class="d-flex flex-column gap-3">

          <div
            v-if="formatSalary(offer.salary, offer.currency) !== null"
            class="d-flex align-center justify-space-between"
          >
            <span class="text-body-2 text-medium-emphasis d-flex align-center gap-2">
              <VIcon icon="tabler-currency-dollar" size="16" />
              {{ t('offers_pages.portal.fields.salary') }}
            </span>
            <span class="text-body-2 font-weight-semibold">
              {{ formatSalary(offer.salary, offer.currency) }} p.m.
            </span>
          </div>

          <VDivider v-if="formatSalary(offer.salary, offer.currency) !== null" />

          <div
            v-if="offer.start_date"
            class="d-flex align-center justify-space-between"
          >
            <span class="text-body-2 text-medium-emphasis d-flex align-center gap-2">
              <VIcon icon="tabler-calendar-event" size="16" />
              {{ t('offers_pages.portal.fields.start_date') }}
            </span>
            <span class="text-body-2 font-weight-medium">{{ offer.start_date }}</span>
          </div>

          <VDivider v-if="offer.start_date" />

          <div
            v-if="offer.expires_at"
            class="d-flex align-center justify-space-between"
          >
            <span class="text-body-2 text-medium-emphasis d-flex align-center gap-2">
              <VIcon icon="tabler-clock" size="16" />
              {{ t('offers_pages.portal.fields.offer_expires') }}
            </span>
            <span class="text-body-2 font-weight-medium">{{ offer.expires_at }}</span>
          </div>

        </div>
      </VCardText>

      <!-- ── Notes ──────────────────────────────────────────────────────── -->
      <VCardText v-if="offer.notes" class="pa-6 pt-4 pb-2">
        <div
          class="pa-4 rounded-lg"
          style="background: rgba(var(--v-theme-surface-variant), 0.4);"
        >
          <p class="text-caption text-uppercase text-medium-emphasis font-weight-bold mb-2">
            {{ t('offers_pages.portal.sections.additional_notes') }}
          </p>
          <p
            class="text-body-2 font-italic mb-0"
            style="white-space: pre-wrap; line-height: 1.6;"
          >
            {{ offer.notes }}
          </p>
        </div>
      </VCardText>

      <!-- ── Action section (only when status allows responding) ────────── -->
      <template v-if="canAct">
        <VDivider class="mt-4" />

        <VCardText class="pa-6">
          <p class="text-caption text-uppercase text-medium-emphasis font-weight-bold mb-4">
            {{ t('offers_pages.portal.sections.your_response') }}
          </p>

          <VTextField
            v-model="otpCode"
            :label="t('offers_pages.portal.fields.otp_label')"
            variant="outlined"
            density="comfortable"
            :hint="t('offers_pages.portal.fields.otp_hint')"
            persistent-hint
            maxlength="8"
            class="mb-4"
            :disabled="loading !== null"
          />

          <div class="d-flex flex-wrap gap-3 align-center">
            <!-- Send OTP -->
            <VBtn
              color="primary"
              variant="tonal"
              prepend-icon="tabler-message-dots"
              :loading="loading === 'otp'"
              :disabled="loading !== null && loading !== 'otp'"
              @click="sendOtp"
            >
              {{ otpSent ? t('offers_pages.portal.actions.resend_otp') : t('offers_pages.portal.actions.send_otp') }}
            </VBtn>

            <!-- Accept -->
            <VBtn
              color="success"
              prepend-icon="tabler-check"
              :loading="loading === 'accept'"
              :disabled="!otpCode || loading !== null && loading !== 'accept'"
              @click="acceptOffer"
            >
              {{ t('offers_pages.portal.actions.accept_offer') }}
            </VBtn>

            <VSpacer />

            <!-- Decline -->
            <VBtn
              color="error"
              variant="text"
              prepend-icon="tabler-x"
              :loading="loading === 'decline'"
              :disabled="loading !== null && loading !== 'decline'"
              @click="declineOffer"
            >
              {{ t('offers_pages.portal.actions.decline') }}
            </VBtn>
          </div>
        </VCardText>
      </template>

      <!-- ── Footer ─────────────────────────────────────────────────────── -->
      <VDivider />
      <VCardText class="pa-4 text-center">
        <p class="text-caption text-medium-emphasis mb-0">
          {{ t('offers_pages.portal.footer') }}
        </p>
      </VCardText>
    </VCard>
  </div>
</template>
