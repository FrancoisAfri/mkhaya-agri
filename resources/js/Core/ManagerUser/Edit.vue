<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { UserProperties } from './types/type'

const { t } = useI18n()

interface PersonData {
  first_name:   string; surname: string; initial: string
  email:        string; cell_number: string; phone_number: string
  profile_pic:  string | null
}

interface UserEditProps {
  user: UserProperties & { person: PersonData | null; roles?: { name: string }[]; lockout_time?: number }
  roles: { id: number; name: string }[]
}

const props = defineProps<UserEditProps>()

const form = useForm({
  email: props.user.email ?? '', status: props.user.status ?? 1,
  lockout_time: props.user.lockout_time ?? 25, password: '',
  role: props.user.roles?.[0]?.name ?? '',
  first_name: props.user.person?.first_name ?? '', surname: props.user.person?.surname ?? '',
  initial: props.user.person?.initial ?? '', cell_number: props.user.person?.cell_number ?? '',
  phone_number: props.user.person?.phone_number ?? '', profile_pic: null as File | null,
})

const avatarPreview = ref<string | null>(props.user.person?.profile_pic ? `/images/avatars/${props.user.person.profile_pic}` : null)
const defaultAvatar = '/images/avatars/m-silhouette.jpg'

const onAvatarChange = (e: Event) => { const file = (e.target as HTMLInputElement).files?.[0]; if (!file) return; form.profile_pic = file; avatarPreview.value = URL.createObjectURL(file) }
const removeAvatar = () => { avatarPreview.value = null; form.profile_pic = null }

const fullName = computed(() => `${form.first_name} ${form.surname}`.trim() || props.user.email)

const requiredValidator = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('user_edit.this_field_is_required')
const emailValidator = (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || t('user_edit.invalid_email')

const refForm = ref()
const onSubmit = async () => { if (!refForm.value) return; const { valid } = await refForm.value.validate(); if (!valid) return; form.post(`/settings/userUpdate/${props.user.id}`, { preserveScroll: true }) }
const goBack = () => router.visit('/users')
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ t('user_edit.page_title') }}</h3>
          <p class="text-medium-emphasis mb-0">{{ t('user_edit.subtitle') }}</p>
        </div>
        <VBreadcrumbs :items="[
          { title: t('user_edit.breadcrumb.home'), href: '/', disabled: false },
          { title: t('user_edit.breadcrumb.manage_users'), href: '/settings/manage-users', disabled: false },
          { title: t('user_edit.breadcrumb.user_profile'), disabled: true },
        ]" density="compact" class="pa-0">
          <template #divider><i class="fa-solid fa-chevron-right" style="font-size:11px" /></template>
        </VBreadcrumbs>
      </div>
      <VDivider class="mb-6" />

      <VForm ref="refForm" @submit.prevent="onSubmit">
        <VRow>
          <VCol cols="12" md="4" lg="3">
            <VCard elevation="0" border rounded="lg" class="text-center pa-6">
              <div class="position-relative d-inline-block mb-4">
                <VAvatar size="160" class="mx-auto" style="border:4px solid #e2e8f0">
                  <img :src="avatarPreview ?? defaultAvatar" style="width:100%;height:100%;object-fit:cover" alt="avatar" />
                </VAvatar>
                <label class="avatar-upload-btn" :for="`avatar-input-${user.id}`" title="Change photo"><i class="fa-solid fa-camera" /></label>
                <input :id="`avatar-input-${user.id}`" type="file" accept="image/*" style="display:none" @change="onAvatarChange" />
              </div>
              <h3 class="text-h6 font-weight-semibold mb-0">{{ fullName }}</h3>
              <p class="text-body-2 text-medium-emphasis mb-4">{{ user.email }}</p>
              <VDivider class="mb-4" />
              <div class="text-left">
                <div class="d-flex align-center gap-2 mb-2"><span class="text-caption text-medium-emphasis" style="width:90px">{{ t('user_edit.full_name_label') }}</span><span class="text-body-2 font-weight-medium">{{ fullName }}</span></div>
                <div class="d-flex align-center gap-2"><span class="text-caption text-medium-emphasis" style="width:90px">{{ t('user_edit.mobile_label') }}</span><span class="text-body-2 font-weight-medium">{{ form.cell_number || '—' }}</span></div>
              </div>
              <VBtn v-if="avatarPreview" variant="text" color="error" size="small" class="mt-4" @click="removeAvatar"><i class="fa-solid fa-trash me-1" />{{ t('user_edit.remove_photo') }}</VBtn>
            </VCard>
          </VCol>

          <VCol cols="12" md="8" lg="9">
            <VCard elevation="0" border rounded="lg">
              <VCardText class="pa-6">
                <div class="section-header mb-4"><i class="fa-solid fa-circle-user section-icon" /><span class="section-title">{{ t('user_edit.personal_info') }}</span></div>
                <VRow dense class="mb-2">
                  <VCol cols="12" sm="5"><VTextField v-model="form.first_name" :rules="[requiredValidator]" :error-messages="form.errors.first_name" :label="`${t('user_edit.first_name')} *`" variant="outlined" density="compact" /></VCol>
                  <VCol cols="12" sm="2"><VTextField v-model="form.initial" :error-messages="form.errors.initial" :label="t('user_edit.initial')" :placeholder="t('user_edit.initial_placeholder')" variant="outlined" density="compact" /></VCol>
                  <VCol cols="12" sm="5"><VTextField v-model="form.surname" :rules="[requiredValidator]" :error-messages="form.errors.surname" :label="`${t('user_edit.last_name')} *`" variant="outlined" density="compact" /></VCol>
                  <VCol cols="12" sm="6"><VTextField v-model="form.cell_number" :error-messages="form.errors.cell_number" :label="t('user_edit.cell_number')" :placeholder="t('user_edit.cell_placeholder')" prepend-inner-icon="tabler-phone" variant="outlined" density="compact" /></VCol>
                  <VCol cols="12" sm="6"><VTextField v-model="form.email" :rules="[requiredValidator, emailValidator]" :error-messages="form.errors.email" :label="`${t('user_edit.email')} *`" prepend-inner-icon="tabler-mail" variant="outlined" density="compact" /></VCol>
                  <VCol cols="12" sm="6"><VSelect v-model="form.role" :items="roles" item-title="name" item-value="name" :label="t('user_edit.roles')" variant="outlined" density="compact" /></VCol>
                  <VCol cols="12" sm="6"><VTextField v-model="form.lockout_time" :rules="[requiredValidator]" :error-messages="form.errors.lockout_time" :label="t('user_edit.lockout_time')" type="number" variant="outlined" density="compact" /></VCol>
                </VRow>

                <VDivider class="my-5" />
                <div class="section-header mb-4"><i class="fa-solid fa-circle-user section-icon" /><span class="section-title">{{ t('user_edit.profile_image') }}</span></div>
                <p class="text-body-2 font-weight-medium mb-2">{{ t('user_edit.profile_picture') }}</p>
                <div class="dropzone" :class="{ 'dropzone--filled': avatarPreview }" @click="($refs.dropInput as HTMLInputElement).click()">
                  <img v-if="avatarPreview" :src="avatarPreview" class="dropzone-img" />
                  <div v-else class="dropzone-placeholder"><i class="fa-solid fa-cloud-arrow-up dropzone-icon" /><p class="dropzone-text">{{ t('user_edit.dropzone_text') }}</p></div>
                </div>
                <input ref="dropInput" type="file" accept="image/*" style="display:none" @change="onAvatarChange" />
                <p class="text-caption text-medium-emphasis mt-1">{{ t('user_edit.profile_picture') }}</p>

                <VDivider class="my-5" />
                <div class="section-header mb-4"><i class="fa-solid fa-lock section-icon" /><span class="section-title">{{ t('user_edit.change_password') }}</span></div>
                <VRow dense>
                  <VCol cols="12" sm="6">
                    <VTextField v-model="form.password" :error-messages="form.errors.password" :label="t('user_edit.new_password')" :placeholder="t('user_edit.password_placeholder')" prepend-inner-icon="tabler-lock" type="password" variant="outlined" density="compact" :hint="t('user_edit.password_hint')" persistent-hint />
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <div class="d-flex justify-end gap-3 mt-5">
          <VBtn variant="tonal" rounded="lg" :disabled="form.processing" @click="goBack"><i class="fa-solid fa-xmark me-2" />{{ t('user_edit.cancel') }}</VBtn>
          <VBtn color="success" rounded="lg" type="submit" :loading="form.processing">
            <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('user_edit.saving') }}...</span></template>
            <i class="fa-solid fa-floppy-disk me-2" />{{ t('user_edit.save') }}
          </VBtn>
        </div>
      </VForm>
    </VContainer>
  </section>
</template>

<style scoped>
.section-header { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f1f5f9; border-radius: 8px; }
.section-icon  { color: #64748b; font-size: 14px; }
.section-title { font-size: 12px; font-weight: 700; letter-spacing: 1px; color: #475569; }
.avatar-upload-btn { position: absolute; bottom: 6px; right: 6px; width: 32px; height: 32px; border-radius: 50%; background: #6366f1; color: white; display: flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.2); transition: background .15s; }
.avatar-upload-btn:hover { background: #5558e8; }
.dropzone { border: 2px dashed #cbd5e1; border-radius: 12px; background: repeating-linear-gradient(-45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px); min-height: 160px; cursor: pointer; display: flex; align-items: center; justify-content: center; overflow: hidden; transition: border-color .2s; }
.dropzone:hover { border-color: #6366f1; }
.dropzone--filled { border-style: solid; border-color: #e2e8f0; background: #f8fafc; }
.dropzone-img { width:100%;height:160px;object-fit:cover; }
.dropzone-placeholder { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 24px; }
.dropzone-icon { font-size: 36px; color: #cbd5e1; }
.dropzone-text { font-size: 13px; color: #94a3b8; margin: 0; }
</style>
