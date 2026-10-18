<script setup lang="ts">
import { ref } from 'vue'

// ─── Props ────────────────────────────────────────────────────────────────────
type Props = {
  contract: {
    uuid: string
    status: string
    job_title: string | null
    candidate_name: string | null
    content: string | null
    expires_at: string | null
  }
  sigRequest: {
    uuid: string
    signatory_type: string
  }
  token: string
}

const props = defineProps<Props>()

// ─── State ────────────────────────────────────────────────────────────────────
const hasRead   = ref(false)
const otpSent   = ref(false)
const otpCode   = ref('')
const loading   = ref<'otp' | 'sign' | null>(null)
const message   = ref<{ type: 'success' | 'error'; text: string } | null>(null)
let msgTimer: ReturnType<typeof setTimeout>

// ─── Helpers ──────────────────────────────────────────────────────────────────
function getBaseUrl(): string {
  return `/portal/sign/${props.sigRequest.uuid}`
}

function csrfToken(): string {
  return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
}

function setMessage(type: 'success' | 'error', text: string): void {
  clearTimeout(msgTimer)
  message.value = { type, text }
  if (type === 'success') {
    msgTimer = setTimeout(() => { message.value = null }, 8000)
  }
}

const signatoryLabel = props.sigRequest.signatory_type === 'candidate'
  ? 'Candidate'
  : 'HR Representative'

const signatoryIcon = props.sigRequest.signatory_type === 'candidate'
  ? 'tabler-user'
  : 'tabler-user-tie'

const alreadySigned = props.contract.status === 'signed'

// ─── Actions ──────────────────────────────────────────────────────────────────
async function sendOtp(): Promise<void> {
  loading.value = 'otp'
  message.value = null
  try {
    const res = await fetch(`${getBaseUrl()}/otp?token=${props.token}`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        'Accept': 'application/json',
      },
    })
    const data = await res.json()
    if (res.ok) {
      otpSent.value = true
      setMessage('success', data.message ?? 'OTP sent to your registered contact.')
    } else {
      setMessage('error', data.message ?? 'Failed to send OTP.')
    }
  } catch {
    setMessage('error', 'Network error. Please try again.')
  } finally {
    loading.value = null
  }
}

async function sign(): Promise<void> {
  if (!otpCode.value || !hasRead.value) return
  loading.value = 'sign'
  message.value = null
  try {
    const res = await fetch(`${getBaseUrl()}/sign?token=${props.token}`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({ otp: otpCode.value }),
    })
    const data = await res.json()
    if (res.ok) {
      setMessage('success', 'Contract signed successfully! Thank you.')
      setTimeout(() => window.location.reload(), 2000)
    } else {
      setMessage('error', data.message ?? 'Invalid OTP. Please try again.')
    }
  } catch {
    setMessage('error', 'Network error. Please try again.')
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
      align-items: flex-start;
      justify-content: center;
      padding: 40px 24px;
    "
  >
    <VCard
      elevation="0"
      border
      style="max-width: 700px; width: 100%;"
    >

      <!-- ── Header ──────────────────────────────────────────────────────── -->
      <VCardText class="pa-8 pb-6 text-center">
        <VIcon
          icon="tabler-file-text"
          size="48"
          color="primary"
          class="mb-4"
        />
        <h5 class="text-h5 font-weight-bold mb-2">Employment Contract</h5>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ contract.job_title ?? 'Position' }}
          &middot;
          {{ signatoryLabel }}
        </p>
        <p v-if="contract.candidate_name" class="text-caption text-medium-emphasis mt-1 mb-0">
          Candidate: {{ contract.candidate_name }}
        </p>
      </VCardText>

      <VDivider />

      <!-- ── Status banner (already signed) ───────────────────────────────── -->
      <div v-if="alreadySigned" class="pa-6 pb-0">
        <VAlert
          type="success"
          variant="tonal"
          density="comfortable"
          icon="tabler-circle-check"
        >
          This contract has been fully signed.
        </VAlert>
      </div>

      <!-- ── Contract content ───────────────────────────────────────────── -->
      <VCardText v-if="contract.content" class="pa-6 pb-2">
        <p class="text-caption text-uppercase text-medium-emphasis font-weight-bold mb-3">
          Contract Document
        </p>

        <!-- Scrollable contract body -->
        <div
          style="
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid rgba(0, 0, 0, 0.12);
            border-radius: 8px;
            padding: 16px;
            background: #ffffff;
            font-size: 0.875rem;
            line-height: 1.7;
            color: rgba(0, 0, 0, 0.87);
          "
          v-html="contract.content"
        />

        <!-- Read confirmation checkbox -->
        <VCheckbox
          v-model="hasRead"
          label="I have read and understood this contract"
          color="primary"
          density="comfortable"
          class="mt-3"
          :disabled="alreadySigned"
          hide-details
        />
      </VCardText>

      <!-- ── Signatory info row ─────────────────────────────────────────── -->
      <VCardText class="pa-6 pb-2">
        <VCard elevation="0" border>
          <VListItem
            :prepend-icon="signatoryIcon"
            :title="signatoryLabel"
            subtitle="Signing capacity"
          >
            <template #append>
              <VChip
                :color="alreadySigned ? 'success' : 'default'"
                size="small"
                variant="tonal"
                :text="alreadySigned ? 'Signed' : 'Pending Signature'"
              />
            </template>
          </VListItem>
        </VCard>
      </VCardText>

      <!-- ── Action section (hidden if already signed) ─────────────────── -->
      <template v-if="!alreadySigned">
        <VDivider class="mt-4" />

        <VCardText class="pa-6">
          <p class="text-caption text-uppercase text-medium-emphasis font-weight-bold mb-4">
            Sign This Contract
          </p>

          <!-- Feedback alert -->
          <VAlert
            v-if="message"
            :type="message.type"
            variant="tonal"
            density="comfortable"
            closable
            class="mb-4"
            @click:close="message = null"
          >
            {{ message.text }}
          </VAlert>

          <!-- Step 1: Request OTP -->
          <VBtn
            color="primary"
            variant="tonal"
            prepend-icon="tabler-mail"
            :loading="loading === 'otp'"
            :disabled="loading !== null && loading !== 'otp'"
            class="mb-4"
            @click="sendOtp"
          >
            {{ otpSent ? 'Resend OTP' : 'Send OTP to my email' }}
          </VBtn>

          <!-- Step 2: OTP input (shown after OTP sent) -->
          <VTextField
            v-if="otpSent"
            v-model="otpCode"
            label="One-Time Password"
            variant="outlined"
            density="comfortable"
            hint="Check your email/phone for the OTP code"
            persistent-hint
            maxlength="8"
            append-inner-icon="tabler-key"
            class="mb-4"
            :disabled="loading !== null"
          />

          <!-- Step 3: Sign -->
          <VBtn
            v-if="otpSent"
            color="success"
            prepend-icon="tabler-pen"
            :loading="loading === 'sign'"
            :disabled="!otpCode || !hasRead"
            block
            @click="sign"
          >
            Sign Contract
          </VBtn>

          <p v-if="!hasRead && otpSent" class="text-caption text-medium-emphasis mt-2 mb-0">
            Please read the contract and check the acknowledgement box before signing.
          </p>
        </VCardText>
      </template>

      <!-- ── Footer ─────────────────────────────────────────────────────── -->
      <VDivider />
      <VCardText class="pa-4 text-center">
        <p class="text-caption text-medium-emphasis mb-0">
          This portal is secured. Your signature is legally binding.
        </p>
      </VCardText>

    </VCard>
  </div>
</template>
