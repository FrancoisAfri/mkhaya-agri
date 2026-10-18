<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute
const confirmDialog = ref()

type Account = {
  id: number; uuid: string; provider: string; provider_label: string
  display_name: string; is_active: boolean; has_token: boolean; token_expires_at: string | null
}
type Option = { value: string; label: string }

const props = defineProps<{
  accounts: Account[]
  providers: Option[]
  /** Providers this installation can actually post to. */
  publishable: string[]
  /** Of those, the ones a tenant can connect by signing in. */
  connectable: string[]
  /** Externally published vacancies, which are the only ones with a page to link to. */
  vacancies: { id: number; label: string }[]
}>()

const canPublish = (provider: string) => props.publishable.includes(provider)
const canConnect = (provider: string) => props.connectable.includes(provider)

function connect(provider: string) {
  window.location.href = route('recruitment.settings.social-accounts.connect', { provider })
}

const showPostDialog = ref(false)
const postingTo = ref<Account | null>(null)
const postForm = useForm({ vacancy_id: null as number | null })

function openPost(a: Account) {
  postingTo.value = a
  postForm.reset()
  postForm.clearErrors()
  showPostDialog.value = true
}

function sendPost() {
  if (!postingTo.value) return
  postForm.post(route('recruitment.settings.social-accounts.publish', postingTo.value.id), {
    preserveScroll: true,
    onSuccess: () => { showPostDialog.value = false; postForm.reset() },
  })
}

const showDialog = ref(false)
const editingId  = ref<number | null>(null)

const form = useForm({
  provider: props.providers[0]?.value ?? 'linkedin',
  display_name: '',
  is_active: true,
})

function openCreate() {
  editingId.value = null
  form.reset()
  form.provider     = props.providers[0]?.value ?? 'linkedin'
  form.display_name = ''
  form.is_active    = true
  form.clearErrors()
  showDialog.value = true
}

function openEdit(a: Account) {
  editingId.value   = a.id
  form.provider     = a.provider
  form.display_name = a.display_name
  form.is_active    = a.is_active
  form.clearErrors()
  showDialog.value = true
}

function save() {
  if (editingId.value) {
    form.put(route('recruitment.settings.social-accounts.update', editingId.value), {
      onSuccess: () => { showDialog.value = false }, preserveScroll: true,
    })
  } else {
    form.post(route('recruitment.settings.social-accounts.store'), {
      onSuccess: () => { showDialog.value = false; form.reset() }, preserveScroll: true,
    })
  }
}

function remove(id: number) {
  if (!confirm(t('recruitment_settings_social_account.delete_confirm'))) return
  router.delete(route('recruitment.settings.social-accounts.destroy', id), { preserveScroll: true })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('recruitment_settings_social_account.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('recruitment_settings_social_account.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('recruitment_settings_social_account.add_account') }}
      </VBtn>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">
      {{ t('recruitment_settings_social_account.oauth_warning') }}
    </VAlert>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('recruitment_settings_social_account.table_provider') }}</th>
            <th>{{ t('recruitment_settings_social_account.table_display_name') }}</th>
            <th>{{ t('recruitment_settings_social_account.table_token') }}</th>
            <th>{{ t('recruitment_settings_social_account.table_status') }}</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!accounts.length">
            <td colspan="5" class="text-center text-medium-emphasis py-6">
              {{ t('recruitment_settings_social_account.no_accounts') }}
            </td>
          </tr>
          <tr v-for="a in accounts" :key="a.id">
            <td>
              <VChip size="small" variant="tonal" color="info">{{ a.provider_label }}</VChip>
              <VChip v-if="!canPublish(a.provider)" size="x-small" variant="tonal" color="default" class="ms-2">
                {{ t('recruitment_settings_social_account.no_integration') }}
              </VChip>
            </td>
            <td>{{ a.display_name }}</td>
            <td>
              <VChip v-if="a.has_token" size="x-small" color="success" variant="tonal">
                {{ t('recruitment_settings_social_account.connected') }}
              </VChip>
              <VChip v-else size="x-small" color="warning" variant="tonal">
                {{ t('recruitment_settings_social_account.not_connected') }}
              </VChip>
            </td>
            <td>
              <VChip size="x-small" :color="a.is_active ? 'success' : 'default'" variant="tonal">
                {{ a.is_active ? t('recruitment_settings_social_account.active') : t('recruitment_settings_social_account.inactive') }}
              </VChip>
            </td>
            <td class="text-end">
              <VBtn
                v-if="canConnect(a.provider)"
                :prepend-icon="a.has_token ? 'tabler-refresh' : 'tabler-plug-connected'"
                variant="tonal"
                size="small"
                color="primary"
                class="me-2"
                @click="connect(a.provider)"
              >
                {{ a.has_token ? t('recruitment_settings_social_account.reconnect') : t('recruitment_settings_social_account.connect') }}
              </VBtn>
              <VBtn
                v-if="canPublish(a.provider) && a.has_token && a.is_active"
                prepend-icon="tabler-send"
                variant="text"
                size="small"
                class="me-1"
                :disabled="!vacancies.length"
                @click="openPost(a)"
              >
                {{ t('recruitment_settings_social_account.post_vacancy') }}
              </VBtn>
              <VBtn icon="tabler-pencil" variant="text" size="small" @click="openEdit(a)" />
              <VBtn icon="tabler-trash" variant="text" size="small" color="error" @click="remove(a.id)" />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="showPostDialog" max-width="520">
      <VCard>
        <VCardTitle>
          {{ t('recruitment_settings_social_account.post_vacancy_title', { account: postingTo?.display_name ?? '' }) }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VAutocomplete
            v-model="postForm.vacancy_id"
            :items="vacancies"
            item-title="label"
            item-value="id"
            :label="t('recruitment_settings_social_account.field_vacancy')"
            variant="outlined"
            density="comfortable"
            :error-messages="postForm.errors.vacancy_id"
          />
          <p class="text-caption text-medium-emphasis mb-0 mt-2">
            {{ t('recruitment_settings_social_account.post_vacancy_hint') }}
          </p>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showPostDialog = false">{{ t('recruitment_settings_social_account.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="postForm.processing"
            :disabled="!postForm.vacancy_id"
            @click="sendPost"
          >
            {{ t('recruitment_settings_social_account.post_vacancy') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="showDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle>
          {{ editingId ? t('recruitment_settings_social_account.dialog_edit_title') : t('recruitment_settings_social_account.dialog_create_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VSelect
            v-model="form.provider"
            :items="providers"
            item-title="label"
            item-value="value"
            :label="t('recruitment_settings_social_account.field_provider')"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :disabled="!!editingId"
            :error-messages="form.errors.provider"
          />
          <VTextField
            v-model="form.display_name"
            :label="t('recruitment_settings_social_account.field_display_name')"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :error-messages="form.errors.display_name"
          />
          <VSwitch
            v-model="form.is_active"
            :label="t('recruitment_settings_social_account.field_active')"
            color="primary"
            hide-details
          />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showDialog = false">
            {{ t('recruitment_settings_social_account.cancel') }}
          </VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editingId ? t('recruitment_settings_social_account.save') : t('recruitment_settings_social_account.add') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
