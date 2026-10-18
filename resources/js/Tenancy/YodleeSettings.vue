<script setup lang="ts">
import { computed } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

interface Settings {
  environment: string
  configured: boolean
  is_active: boolean
  has_secret: boolean
  client_id?: string | null
  base_url?: string | null
  fastlink_url?: string | null
  verified_at?: string | null
  last_error?: string | null
}

const props = defineProps<{
  settings: Settings
  environments: string[]
  hosts: Record<string, string>
  fastlinkUrls: Record<string, string>
  linkedCompanies: number
}>()

const form = useForm({
  environment: props.settings.environment ?? 'sandbox',
  client_id: props.settings.client_id ?? '',
  // Blank means "keep the stored secret" — see the note on the field.
  secret: '',
  base_url: '',
  fastlink_url: '',
  is_active: props.settings.is_active ?? true,
})

// Shown beneath the environment so it is obvious which Yodlee the keys have to come from.
const host = computed(() => props.hosts[form.environment] ?? '')
const fastlink = computed(() => props.fastlinkUrls[form.environment] ?? '')

const environmentChanged = computed(
  () => props.settings.configured && form.environment !== props.settings.environment,
)

function save() {
  form.post(route('tenancy.yodlee.save'), {
    preserveScroll: true,
    onSuccess: () => { form.secret = '' },
  })
}

function test() {
  useForm({}).post(route('tenancy.yodlee.test'), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4" style="max-width: 860px">
    <Link :href="route('tenancy.overview')">
      <VBtn size="small" variant="text" prepend-icon="tabler-arrow-left" class="mb-3">Platform overview</VBtn>
    </Link>

    <div class="mb-5">
      <div class="text-overline text-medium-emphasis">Platform tools (Mkhaya only)</div>
      <h2 class="text-h5 mb-1">Yodlee settings</h2>
      <div class="text-body-2 text-medium-emphasis" style="max-width: 68ch">
        Mkhaya's own Yodlee subscription. Every company on this installation links their bank
        through it — customers never enter a credential of their own.
      </div>
    </div>

    <!--
      Status first. Whether this works is the question somebody opens this page with, and it is
      answerable in one line.
    -->
    <VCard border class="mb-4">
      <VCardText class="d-flex align-center flex-wrap ga-4">
        <VChip :color="settings.configured ? 'success' : 'warning'" variant="tonal" size="small">
          {{ settings.configured ? 'Configured' : 'Not configured' }}
        </VChip>
        <VChip v-if="settings.verified_at" color="success" variant="tonal" size="small" prepend-icon="tabler-check">
          Verified {{ settings.verified_at }}
        </VChip>
        <div class="text-caption text-medium-emphasis">
          {{ linkedCompanies }} compan{{ linkedCompanies === 1 ? 'y has' : 'ies have' }} linked a bank through this subscription
        </div>
      </VCardText>
      <VAlert v-if="settings.last_error" type="error" variant="tonal" density="compact" class="mx-4 mb-4 text-caption">
        {{ settings.last_error }}
      </VAlert>
    </VCard>

    <VCard border>
      <VCardText class="d-flex flex-column ga-4">
        <VSelect
          v-model="form.environment"
          :items="environments.map(e => ({ title: e.charAt(0).toUpperCase() + e.slice(1), value: e }))"
          label="Environment"
          variant="outlined" density="comfortable" hide-details="auto"
          :error-messages="form.errors.environment"
          :hint="host"
          persistent-hint
        />

        <!--
          Said before it is saved, not discovered afterwards. Yodlee's environments are separate
          subscriptions with separate users, so switching abandons every link made under the old one.
        -->
        <VAlert v-if="environmentChanged && linkedCompanies > 0" type="warning" variant="tonal" density="compact">
          Changing the environment abandons the {{ linkedCompanies }} existing bank link{{ linkedCompanies === 1 ? '' : 's' }}.
          Yodlee treats each environment as a separate subscription, so those customers will have to link their banks again.
        </VAlert>

        <VTextField
          v-model="form.client_id"
          label="Client ID"
          variant="outlined" density="comfortable" hide-details="auto"
          :error-messages="form.errors.client_id"
          hint="From the Yodlee dashboard, for the environment selected above."
          persistent-hint
        />

        <VTextField
          v-model="form.secret"
          type="password"
          autocomplete="new-password"
          label="Client secret"
          :placeholder="settings.has_secret ? '•••••••• stored' : ''"
          :hint="settings.has_secret
            ? 'Leave blank to keep the stored secret. Type a new one only to replace it.'
            : 'Required the first time. Stored encrypted and never shown again.'"
          persistent-hint
          variant="outlined" density="comfortable"
          :error-messages="form.errors.secret"
        />

        <VExpansionPanels variant="accordion">
          <VExpansionPanel elevation="0">
            <VExpansionPanelTitle class="text-body-2">Advanced — regional hosts</VExpansionPanelTitle>
            <VExpansionPanelText>
              <div class="text-caption text-medium-emphasis mb-3">
                Leave both blank unless Yodlee issued your subscription a region-specific host.
                Defaults for {{ form.environment }}: <code>{{ host }}</code> and <code>{{ fastlink }}</code>.
              </div>
              <VTextField
                v-model="form.base_url" label="API base URL" placeholder="host"
                variant="outlined" density="compact" class="mb-3" hide-details="auto"
                :error-messages="form.errors.base_url"
              />
              <VTextField
                v-model="form.fastlink_url" label="FastLink URL" placeholder="fastlink"
                variant="outlined" density="compact" hide-details="auto"
                :error-messages="form.errors.fastlink_url"
              />
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>

        <VSwitch
          v-model="form.is_active"
          label="Bank feeds available to customers"
          color="primary" density="compact" hide-details
        />
      </VCardText>

      <VCardActions class="px-4 pb-4">
        <VBtn variant="text" :disabled="!settings.configured" @click="test">Test connection</VBtn>
        <VSpacer />
        <VBtn color="primary" :loading="form.processing" :disabled="!form.client_id" @click="save">Save</VBtn>
      </VCardActions>
    </VCard>

    <div class="text-caption text-medium-emphasis mt-4" style="max-width: 68ch">
      Each company gets its own Yodlee user beneath this subscription, created the first time they
      link a bank. That is what keeps one customer's accounts out of another's — Yodlee scopes
      accounts to the user, not the subscription.
    </div>
  </div>
</template>
