<script setup lang="ts">
/**
 * The way in for a contracted grower: their mobile number (or email address), a code sent there,
 * or the password they set. Outside every staff layout — a grower is not a user of the company's
 * system — and wearing the company's brand where it is white-labelled.
 */
import { computed, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import type { Brand } from './GrowerShell.vue'

defineOptions({ layout: null })

const props = defineProps<{
  company: string | null
  brand: Brand
  pending: { identifier: string; company: string | null } | null
  locale: string
}>()
const { t } = useI18n()
const k = (key: string, p?: Record<string, unknown>) => t(`agri.grower_portal.${key}`, p ?? {})
const page = usePage()
const flash = computed(() => (page.props as any).flash ?? {})

const mode = ref<'code' | 'password'>('code')
const step = ref<'ask' | 'verify'>(props.pending ? 'verify' : 'ask')
const ask = useForm({ identifier: props.pending?.identifier ?? '', company: props.company ?? props.pending?.company ?? '', via: 'whatsapp' })
const verify = useForm({ identifier: props.pending?.identifier ?? '', company: props.company ?? props.pending?.company ?? '', code: '' })
const pwd = useForm({ identifier: '', company: props.company ?? '', password: '' })
const isEmail = computed(() => ask.identifier.includes('@'))

const sendCode = () => ask.post(route('grower.portal.code'), {
  preserveScroll: true,
  onSuccess: () => { verify.identifier = ask.identifier; verify.company = ask.company; step.value = 'verify' },
})
const signIn = () => verify.post(route('grower.portal.verify'), { onFinish: () => verify.reset('code') })
const signInPassword = () => pwd.post(route('grower.portal.password.login'), { onFinish: () => pwd.reset('password') })
const restart = () => { step.value = 'ask'; verify.reset() }
</script>

<template>
  <div class="gl-shell">
    <VCard class="gl-card" elevation="6">
      <VCardText class="pa-6">
        <div class="text-center mb-4">
          <img v-if="brand.logo_url" :src="brand.logo_url" :alt="brand.name" class="gl-logo">
          <div class="text-h6 mt-2">{{ brand.white_label ? brand.name : (brand.company ?? brand.name) }}</div>
          <div class="text-body-2 text-medium-emphasis">{{ k('portal_name') }}</div>
        </div>

        <VAlert v-if="flash.success" type="success" variant="tonal" density="compact" class="mb-4">{{ flash.success }}</VAlert>

        <template v-if="mode === 'code'">
          <VForm v-if="step === 'ask'" @submit.prevent="sendCode">
            <p class="text-body-2 mb-4">{{ k('login_blurb') }}</p>
            <VTextField v-model="ask.identifier" :label="k('identifier')" :hint="k('identifier_hint')" persistent-hint autocomplete="username"
              inputmode="tel" variant="outlined" class="mb-3" :error-messages="ask.errors.identifier" />
            <VBtnToggle v-if="!isEmail" v-model="ask.via" mandatory density="comfortable" class="mb-4" divided>
              <VBtn value="whatsapp" prepend-icon="tabler-brand-whatsapp">{{ k('via_whatsapp') }}</VBtn>
              <VBtn value="sms" prepend-icon="tabler-message">{{ k('via_sms') }}</VBtn>
            </VBtnToggle>
            <VBtn type="submit" block size="large" color="primary" :loading="ask.processing">{{ k('send_code') }}</VBtn>
          </VForm>

          <VForm v-else @submit.prevent="signIn">
            <p class="text-body-2 mb-4">{{ k('code_blurb', { to: verify.identifier }) }}</p>
            <VTextField v-model="verify.code" :label="k('code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" variant="outlined" class="mb-4"
              :error-messages="verify.errors.code" />
            <VBtn type="submit" block size="large" color="primary" :loading="verify.processing">{{ k('sign_in') }}</VBtn>
            <VBtn variant="text" block class="mt-2" @click="restart">{{ k('another_code') }}</VBtn>
          </VForm>
        </template>

        <VForm v-else @submit.prevent="signInPassword">
          <VTextField v-model="pwd.identifier" :label="k('identifier')" autocomplete="username" variant="outlined" class="mb-3" />
          <VTextField v-model="pwd.password" :label="k('password')" type="password" autocomplete="current-password" variant="outlined" class="mb-4"
            :error-messages="pwd.errors.password || pwd.errors.identifier" />
          <VBtn type="submit" block size="large" color="primary" :loading="pwd.processing">{{ k('sign_in') }}</VBtn>
        </VForm>

        <VBtn variant="text" size="small" block class="mt-4" @click="mode = mode === 'code' ? 'password' : 'code'">
          {{ mode === 'code' ? k('use_password') : k('use_code') }}
        </VBtn>
        <p class="text-caption text-medium-emphasis mt-4 mb-0">{{ k('trouble') }}</p>
      </VCardText>
    </VCard>
    <p v-if="brand.powered_by" class="gl-powered">{{ k('powered_by', { name: brand.name }) }}</p>
  </div>
</template>

<style scoped>
.gl-shell { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1.5rem 1rem;
  background: rgb(var(--v-theme-background)); }
.gl-card { width: 100%; max-width: 26rem; }
.gl-logo { height: 48px; width: auto; max-width: 180px; object-fit: contain; }
.gl-powered { margin-top: 1rem; font-size: 0.75rem; color: rgba(var(--v-theme-on-surface), 0.6); }
</style>
