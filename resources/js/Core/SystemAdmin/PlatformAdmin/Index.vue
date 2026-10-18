<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface AdminUser {
  id: number
  email: string
  name: string
  is_master_admin: boolean
  granted_by: string | null
  created_at: string
}

interface EligibleUser {
  id: number
  email: string
  name: string
}

const props = defineProps<{
  admins: AdminUser[]
  eligible: EligibleUser[]
}>()

// ── Add new platform admin ────────────────────────────────────────────────────
const showAddDialog  = ref(false)
const showPassword   = ref(false)
const showConfirm    = ref(false)

const addForm = useForm({
  first_name:            '',
  surname:               '',
  email:                 '',
  phone_number:          '',
  password:              '',
  password_confirmation: '',
})

function submitAdd() {
  addForm.post(route('settings.platform-admins.store'), {
    preserveScroll: true,
    onSuccess: () => {
      showAddDialog.value = false
      addForm.reset()
      showToast('success', t('platform_admins_page.toast_added', { name: `${addForm.first_name} ${addForm.surname}` }))
    },
  })
}

function closeAdd() {
  showAddDialog.value = false
  addForm.reset()
  addForm.clearErrors()
}

// ── Grant existing user ───────────────────────────────────────────────────────
const grantingId = ref<number | null>(null)

function grant(user: EligibleUser) {
  grantingId.value = user.id
  router.post(
    route('settings.platform-admins.grant', user.id),
    {},
    {
      preserveScroll: true,
      onSuccess: () => showToast('success', t('platform_admins_page.toast_granted', { name: user.name })),
      onError: () => showToast('error', t('platform_admins_page.toast_grant_failed')),
      onFinish: () => (grantingId.value = null),
    },
  )
}

// ── Revoke ────────────────────────────────────────────────────────────────────
const revokingId      = ref<number | null>(null)
const confirmRevoke   = ref<AdminUser | null>(null)
const showRevokeDialog = ref(false)

function revoke(user: AdminUser) {
  revokingId.value = user.id
  showRevokeDialog.value = false
  confirmRevoke.value = null
  router.delete(
    route('settings.platform-admins.revoke', user.id),
    {
      preserveScroll: true,
      onSuccess: () => showToast('success', t('platform_admins_page.toast_revoked', { name: user.name })),
      onError: () => showToast('error', t('platform_admins_page.toast_revoke_failed')),
      onFinish: () => (revokingId.value = null),
    },
  )
}

// ── Toast ─────────────────────────────────────────────────────────────────────
const toast        = ref<{ type: 'success' | 'error'; message: string } | null>(null)
const toastVisible = ref(false)

function showToast(type: 'success' | 'error', message: string) {
  toast.value   = { type, message }
  toastVisible.value = true
  setTimeout(() => (toastVisible.value = false), 4000)
}

const passwordStrength = computed(() => {
  const p = addForm.password
  if (!p) return { score: 0, label: '', color: '' }
  let score = 0
  if (p.length >= 8) score++
  if (/[A-Z]/.test(p)) score++
  if (/[0-9]/.test(p)) score++
  if (/[^A-Za-z0-9]/.test(p)) score++
  const labels = [
    t('platform_admins_page.password_strength_weak'),
    t('platform_admins_page.password_strength_fair'),
    t('platform_admins_page.password_strength_good'),
    t('platform_admins_page.password_strength_strong'),
    t('platform_admins_page.password_strength_very_strong'),
  ]
  const colors = ['error', 'warning', 'info', 'success', 'success']
  return { score, label: labels[score], color: colors[score] }
})
</script>

<template>
  <div class="pa-6" style="max-width:960px;margin:0 auto">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div class="d-flex align-center ga-3">
        <VIcon color="primary" size="32">tabler-shield-lock</VIcon>
        <div>
          <div class="text-h5 font-weight-bold">{{ t('platform_admins_page.title') }}</div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('platform_admins_page.subtitle') }}
          </div>
        </div>
      </div>

      <VBtn color="primary" prepend-icon="tabler-user-plus" @click="showAddDialog = true">
        {{ t('platform_admins_page.add_button') }}
      </VBtn>
    </div>

    <!-- Toast -->
    <VSnackbar
      v-model="toastVisible"
      :color="toast?.type === 'success' ? 'success' : 'error'"
      location="top right"
      :timeout="4000"
    >
      {{ toast?.message }}
    </VSnackbar>

    <!-- Current platform admins -->
    <VCard class="mb-6" border>
      <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2 d-flex align-center ga-2">
        <VIcon size="18" color="primary">tabler-shield-check</VIcon>
        {{ t('platform_admins_page.current_admins') }}
        <VChip size="x-small" class="ml-1">{{ admins.length }}</VChip>
      </VCardTitle>

      <VDivider />

      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('platform_admins_page.table_name') }}</th>
            <th>{{ t('platform_admins_page.table_email') }}</th>
            <th>{{ t('platform_admins_page.table_granted_by') }}</th>
            <th>{{ t('platform_admins_page.table_since') }}</th>
            <th class="text-right">{{ t('platform_admins_page.table_action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="admin in admins" :key="admin.id">
            <td>
              <div class="d-flex align-center ga-2 py-2">
                <VAvatar :color="admin.is_master_admin ? 'warning' : 'primary'" size="32">
                  <span class="text-caption text-white font-weight-bold">
                    {{ admin.name.charAt(0).toUpperCase() }}
                  </span>
                </VAvatar>
                <span class="font-weight-medium">{{ admin.name }}</span>
                <VChip v-if="admin.is_master_admin" size="x-small" color="warning" label>
                  {{ t('platform_admins_page.master_badge') }}
                </VChip>
              </div>
            </td>
            <td class="text-body-2 text-medium-emphasis">{{ admin.email }}</td>
            <td class="text-body-2">{{ admin.granted_by ?? '—' }}</td>
            <td class="text-body-2 text-medium-emphasis">{{ admin.created_at }}</td>
            <td class="text-right">
              <VBtn
                v-if="!admin.is_master_admin"
                size="small"
                variant="tonal"
                color="error"
                :loading="revokingId === admin.id"
                @click="confirmRevoke = admin; showRevokeDialog = true"
              >
                {{ t('platform_admins_page.revoke') }}
              </VBtn>
              <VChip v-else size="small" label color="warning" variant="tonal">
                {{ t('platform_admins_page.protected') }}
              </VChip>
            </td>
          </tr>
          <tr v-if="admins.length === 0">
            <td colspan="5" class="text-center text-medium-emphasis pa-4">
              {{ t('platform_admins_page.no_admins') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Promote an existing user -->
    <VCard border>
      <VCardTitle class="text-subtitle-1 font-weight-semibold pa-4 pb-2 d-flex align-center ga-2">
        <VIcon size="18" color="primary">tabler-user-up</VIcon>
        {{ t('platform_admins_page.promote_user') }}
        <VChip size="x-small" class="ml-1">{{ eligible.length }}</VChip>
      </VCardTitle>
      <VCardSubtitle class="px-4 pb-3 text-body-2 text-medium-emphasis">
        {{ t('platform_admins_page.promote_subtitle') }}
      </VCardSubtitle>

      <VDivider />

      <div v-if="eligible.length === 0" class="pa-4 text-medium-emphasis text-body-2">
        {{ t('platform_admins_page.all_already_admins') }}
      </div>

      <VTable v-else density="compact">
        <thead>
          <tr>
            <th>{{ t('platform_admins_page.table_name') }}</th>
            <th>{{ t('platform_admins_page.table_email') }}</th>
            <th class="text-right">{{ t('platform_admins_page.table_action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in eligible" :key="user.id">
            <td class="py-2">
              <div class="d-flex align-center ga-2">
                <VAvatar color="grey-lighten-1" size="30">
                  <span class="text-caption font-weight-bold">{{ user.name.charAt(0).toUpperCase() }}</span>
                </VAvatar>
                {{ user.name }}
              </div>
            </td>
            <td class="text-body-2 text-medium-emphasis">{{ user.email }}</td>
            <td class="text-right">
              <VBtn
                size="small"
                variant="tonal"
                color="primary"
                :loading="grantingId === user.id"
                @click="grant(user)"
              >
                {{ t('platform_admins_page.grant_access') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- ── Add New Platform Admin dialog ─────────────────────────────────────── -->
    <VDialog v-model="showAddDialog" max-width="520">
      <VCard rounded="lg">
        <VCardTitle class="d-flex align-center ga-2 pa-5 pb-3">
          <VIcon color="primary">tabler-user-plus</VIcon>
          {{ t('platform_admins_page.dialog_add_title') }}
        </VCardTitle>
        <VCardSubtitle class="px-5 pb-3 text-body-2">
          {{ t('platform_admins_page.dialog_add_subtitle') }}
        </VCardSubtitle>

        <VDivider />

        <VCardText class="pa-5">
          <VForm @submit.prevent="submitAdd">
            <VRow dense>
              <VCol cols="6">
                <VTextField
                  v-model="addForm.first_name"
                  :label="t('platform_admins_page.field_first_name')"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="addForm.errors.first_name"
                  autofocus
                />
              </VCol>
              <VCol cols="6">
                <VTextField
                  v-model="addForm.surname"
                  :label="t('platform_admins_page.field_surname')"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="addForm.errors.surname"
                />
              </VCol>
              <VCol cols="12">
                <VTextField
                  v-model="addForm.email"
                  :label="t('platform_admins_page.field_email')"
                  type="email"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="addForm.errors.email"
                  :hint="t('platform_admins_page.field_email_hint')"
                  persistent-hint
                />
              </VCol>
              <VCol cols="12">
                <VTextField
                  v-model="addForm.phone_number"
                  :label="t('platform_admins_page.field_phone')"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="addForm.errors.phone_number"
                />
              </VCol>
              <VCol cols="12">
                <VTextField
                  v-model="addForm.password"
                  :label="t('platform_admins_page.field_password')"
                  :type="showPassword ? 'text' : 'password'"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="addForm.errors.password"
                  :append-inner-icon="showPassword ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="showPassword = !showPassword"
                >
                  <template v-if="addForm.password" #details>
                    <div class="d-flex align-center ga-2 mt-1">
                      <VProgressLinear
                        :model-value="(passwordStrength.score / 4) * 100"
                        :color="passwordStrength.color"
                        height="4"
                        rounded
                        style="max-width:120px"
                      />
                      <span class="text-caption" :class="`text-${passwordStrength.color}`">
                        {{ passwordStrength.label }}
                      </span>
                    </div>
                  </template>
                </VTextField>
              </VCol>
              <VCol cols="12">
                <VTextField
                  v-model="addForm.password_confirmation"
                  :label="t('platform_admins_page.field_confirm_password')"
                  :type="showConfirm ? 'text' : 'password'"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="addForm.errors.password_confirmation"
                  :append-inner-icon="showConfirm ? 'tabler-eye-off' : 'tabler-eye'"
                  @click:append-inner="showConfirm = !showConfirm"
                />
              </VCol>
            </VRow>
          </VForm>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-4 ga-2">
          <VSpacer />
          <VBtn variant="text" :disabled="addForm.processing" @click="closeAdd">
            {{ t('platform_admins_page.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="addForm.processing"
            @click="submitAdd"
          >
            {{ t('platform_admins_page.create_admin') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Revoke confirmation dialog -->
    <VDialog v-model="showRevokeDialog" max-width="420">
      <VCard v-if="confirmRevoke" rounded="lg">
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="error">tabler-alert-circle</VIcon>
          {{ t('platform_admins_page.dialog_revoke_title') }}
        </VCardTitle>
        <VCardText class="text-body-2">
          {{ t('platform_admins_page.dialog_revoke_confirm', { name: confirmRevoke.name }) }}
        </VCardText>
        <VCardActions class="pa-4 pt-0 ga-2">
          <VSpacer />
          <VBtn variant="text" @click="showRevokeDialog = false; confirmRevoke = null">
            {{ t('platform_admins_page.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            :loading="revokingId === confirmRevoke?.id"
            @click="revoke(confirmRevoke!)"
          >
            {{ t('platform_admins_page.revoke_access') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>