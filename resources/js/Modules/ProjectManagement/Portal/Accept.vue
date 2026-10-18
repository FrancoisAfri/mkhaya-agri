<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The one Project Management page a person sees before they have an account.
 *
 * Two shapes behind one component: a usable invitation, and one that is expired, withdrawn,
 * already taken up or simply not found. The refused shape carries only a reason key — no project
 * name, no email — because a link that has stopped working should not keep describing what it
 * used to point at to whoever now holds it.
 */
const props = defineProps<{
  valid: boolean
  reason?: string
  token?: string
  email?: string
  project?: string | null
  role?: string
  hasAccount?: boolean
  expires?: string
}>()

const form = useForm({
  password: '',
  password_confirmation: '',
})

function submit() {
  form.post(route('project-management.invite.accept', { token: props.token }), {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <div class="d-flex align-center justify-center pa-4" style="min-block-size: 100vh">
    <VCard border rounded="lg" class="pa-2" style="inline-size: 100%; max-inline-size: 30rem">
      <!-- ── Refused ────────────────────────────────────────────────────── -->
      <template v-if="!props.valid">
        <VCardText class="text-center py-10">
          <VAvatar color="warning" variant="tonal" size="56" class="mb-4">
            <VIcon icon="tabler-link-off" size="30" />
          </VAvatar>
          <h5 class="text-h5 mb-2">{{ t('project_management.invites.unavailable_title') }}</h5>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t(props.reason ?? 'project_management.invites.not_found') }}
          </p>
          <p class="text-body-2 text-medium-emphasis mt-4 mb-0">
            {{ t('project_management.invites.ask_for_new_link') }}
          </p>
        </VCardText>
      </template>

      <!-- ── Usable ─────────────────────────────────────────────────────── -->
      <template v-else>
        <VCardItem>
          <VCardTitle class="text-h5">{{ t('project_management.invites.title') }}</VCardTitle>
          <VCardSubtitle v-if="props.project">{{ props.project }}</VCardSubtitle>
        </VCardItem>

        <VCardText>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('project_management.invites.intro', { email: props.email }) }}
          </p>

          <!-- The address already has an account, so no password is set here. Saying so up front
               is what stops somebody typing a new password and wondering why it was ignored. -->
          <VAlert
            v-if="props.hasAccount"
            type="info"
            variant="tonal"
            density="comfortable"
            class="mb-4"
          >
            {{ t('project_management.invites.existing_account') }}
          </VAlert>

          <form @submit.prevent="submit">
            <template v-if="!props.hasAccount">
              <VTextField
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                :label="t('project_management.invites.password')"
                :error-messages="form.errors.password"
                variant="outlined"
                density="compact"
                class="mb-3"
              />
              <VTextField
                v-model="form.password_confirmation"
                type="password"
                autocomplete="new-password"
                :label="t('project_management.invites.password_confirm')"
                variant="outlined"
                density="compact"
                class="mb-4"
              />
            </template>

            <VBtn type="submit" color="primary" block :loading="form.processing">
              {{ props.hasAccount
                ? t('project_management.invites.link_account')
                : t('project_management.invites.create_account') }}
            </VBtn>
          </form>

          <p v-if="props.expires" class="text-caption text-medium-emphasis text-center mt-4 mb-0">
            {{ t('project_management.invites.expires_on', { date: props.expires }) }}
          </p>
        </VCardText>
      </template>
    </VCard>
  </div>
</template>
