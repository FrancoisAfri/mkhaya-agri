<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

type Sdf = {
  id: number; user_id: number
  user: { id: number; person: { first_name: string; surname: string } | null }
  seta: { id: number; acronym: string } | null; seta_id: number | null
  registration_number: string | null
  registered_at: string | null; expires_at: string | null
  is_primary: boolean; department: string | null; status: string; notes: string | null
}

const props = defineProps<{
  sdfs:        Sdf[]
  setas:       { id: number; name: string; acronym: string }[]
  users:       { id: number; name: string }[]
  departments: { id: number; name: string }[]
}>()

const confirmDialog = ref<InstanceType<typeof ConfirmDialog>>()
const dialog  = ref(false)
const editing = ref<Sdf | null>(null)

const form = useForm({
  user_id: null as number | null,
  seta_id: null as number | null,
  registration_number: '',
  registered_at: '', expires_at: '',
  is_primary: false, department: '',
  status: 'active', notes: '',
})

function openNew() {
  editing.value = null
  form.reset()
  form.clearErrors()
  dialog.value = true
}

function openEdit(s: Sdf) {
  editing.value = s
  form.user_id             = s.user_id
  form.seta_id             = s.seta_id
  form.registration_number = s.registration_number ?? ''
  form.registered_at       = s.registered_at ? s.registered_at.substring(0, 10) : ''
  form.expires_at          = s.expires_at    ? s.expires_at.substring(0, 10)    : ''
  form.is_primary          = s.is_primary
  form.department          = s.department ?? ''
  form.status              = s.status
  form.notes               = s.notes ?? ''
  form.clearErrors()
  dialog.value = true
}

function save() {
  if (editing.value) {
    form.patch(route('lms.admin.qcto.sdfs.update', { sdf: editing.value.id }), {
      onSuccess: () => { dialog.value = false },
    })
  } else {
    form.post(route('lms.admin.qcto.sdfs.store'), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  }
}

function destroy(s: Sdf) {
  confirmDialog.value?.open(
    {
      title: t('lms_sdf.confirm_delete.title'),
      message: t('lms_sdf.confirm_delete.message'),
      confirmText: t('lms_sdf.confirm_delete.confirm'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { useForm({}).delete(route('lms.admin.qcto.sdfs.destroy', { sdf: s.id })) },
  )
}

function userName(s: Sdf) {
  const p = s.user?.person
  return p ? `${p.first_name} ${p.surname}` : `User #${s.user_id}`
}

function expiryColor(d: string | null): string {
  if (!d) return 'default'
  const diff = (new Date(d).getTime() - Date.now()) / 86400000
  return diff < 0 ? 'error' : diff <= 90 ? 'warning' : 'success'
}
</script>

<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_sdf.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_sdf.page_description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">
        {{ t('lms_sdf.add_sdf') }}
      </VBtn>
    </div>

    <VRow>
      <VCol v-for="s in sdfs" :key="s.id" cols="12" md="6" lg="4">
        <VCard rounded="lg" elevation="0" border :class="s.is_primary ? 'border-primary' : ''">
          <VCardText class="pa-4">
            <div class="d-flex align-start gap-3">
              <VAvatar color="primary" variant="tonal" size="44" rounded>
                <VIcon icon="tabler-briefcase" size="22" />
              </VAvatar>
              <div class="flex-1-1">
                <div class="d-flex align-center gap-2 mb-1">
                  <p class="text-body-1 font-weight-semibold mb-0">{{ userName(s) }}</p>
                  <VChip v-if="s.is_primary" color="primary" size="x-small" variant="tonal">
                    {{ t('lms_sdf.primary') }}
                  </VChip>
                </div>
                <p class="text-caption text-medium-emphasis mb-1">
                  {{ s.seta?.acronym ?? t('lms_sdf.no_seta') }}
                  <span v-if="s.registration_number"> · {{ s.registration_number }}</span>
                  <span v-if="s.department"> · {{ s.department }}</span>
                </p>
                <div class="d-flex gap-2 mt-2">
                  <VChip :color="s.status === 'active' ? 'success' : 'error'" size="x-small" variant="tonal" class="text-capitalize">
                    {{ t(`lms_sdf.statuses.${s.status}`) }}
                  </VChip>
                  <VChip v-if="s.expires_at" :color="expiryColor(s.expires_at)" size="x-small" variant="tonal">
                    {{ t('lms_sdf.expires', { date: s.expires_at }) }}
                  </VChip>
                </div>
              </div>
              <div class="d-flex flex-column gap-1">
                <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEdit(s)" />
                <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroy(s)" />
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!sdfs.length" cols="12">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="text-center pa-8 text-medium-emphasis">
            {{ t('lms_sdf.no_sdfs') }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Dialog -->
    <VDialog v-model="dialog" max-width="560">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">
          {{ editing ? t('lms_sdf.dialog.title_edit') : t('lms_sdf.dialog.title_new') }}
        </VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.user_id"
                :label="t('lms_sdf.dialog.employee_label')"
                :items="users.map(u => ({title: u.name, value: u.id}))"
                :error-messages="form.errors.user_id"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.seta_id"
                :label="t('lms_sdf.dialog.seta_label')"
                :items="setas.map(s => ({title: s.acronym + ' — ' + s.name, value: s.id}))"
                clearable
                :error-messages="form.errors.seta_id"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.registration_number"
                :label="t('lms_sdf.dialog.registration_number_label')"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.registered_at"
                :label="t('lms_sdf.dialog.registered_date_label')"
                type="date"
                :error-messages="form.errors.registered_at"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.expires_at"
                :label="t('lms_sdf.dialog.expiry_date_label')"
                type="date"
                :hint="t('lms_sdf.dialog.expiry_date_hint')"
                persistent-hint
                :error-messages="form.errors.expires_at"
              />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-model="form.department"
                :label="t('lms_sdf.dialog.department_label')"
                :items="departments.map(d => ({ title: d.name, value: d.name }))"
                clearable
                :error-messages="form.errors.department"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.status"
                :label="t('lms_sdf.dialog.status_label')"
                :items="[
                  {title: t('lms_sdf.statuses.active'), value: 'active'},
                  {title: t('lms_sdf.statuses.lapsed'), value: 'lapsed'},
                  {title: t('lms_sdf.statuses.suspended'), value: 'suspended'}
                ]"
              />
            </VCol>
            <VCol cols="12">
              <VCheckbox
                v-model="form.is_primary"
                :label="t('lms_sdf.dialog.primary_label')"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('lms_sdf.dialog.notes_label')"
                rows="2"
                auto-grow
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="dialog = false">{{ t('lms_sdf.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('lms_sdf.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
