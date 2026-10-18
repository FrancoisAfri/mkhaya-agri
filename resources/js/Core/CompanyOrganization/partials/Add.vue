<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, watch, nextTick, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useOrganisationStore } from '../Store/store'
import UserAvatar from './UserAvatar.vue'

const { t } = useI18n()
const store    = useOrganisationStore()
const refForm  = ref<VForm>()
const required = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('organisation_structure_page.name_required')
const form     = useForm({ name: '', type: '', user_id: '' as string | number })

const formattedUsers = computed(() =>
  store.users.map(u => ({
    ...u,
    display_name: `${u.first_name} ${u.surname}`,
  }))
)

watch(() => store.isAddOpen, (val) => {
  if (val) nextTick(() => {
    form.name    = ''
    form.type    = ''
    form.user_id = ''
    form.clearErrors()
    refForm.value?.resetValidation()
  })
})

const onSubmit = async () => {
  const { valid } = await refForm.value!.validate()
  if (!valid) return
  form.transform(d => ({ ...d, parent_id: store.addParentId, company_id: store.companyId }))
    .post('/settings/organization/store', {
      preserveScroll: true,
      // Reload the tree so the newly-created unit appears immediately — without
      // this the dialog just closed and the tree stayed "No units yet" until a
      // manual page reload.
      onSuccess: () => { store.closeAdd(); router.reload({ only: ['tree'] }) },
    })
}

const capitalizeWords = (str: string) => {
  return str
    .toLowerCase()
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

const formattedUnitTypes = computed(() => {
  return store.unitTypes.map(type => ({
    title: capitalizeWords(type),
    value: type
  }))
})
</script>

<template>
  <VDialog :model-value="store.isAddOpen" max-width="480" scrollable @update:model-value="store.closeAdd">
    <VCard rounded="lg" elevation="0">

      <!-- Header -->
      <div class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
        style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3">
          <div
            class="d-flex align-center justify-center rounded-lg flex-shrink-0"
            style="width:40px;height:40px;background:rgba(var(--v-theme-success),0.12)">
            <VIcon icon="tabler-sitemap" color="success" size="20" />
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('organisation_structure_page.add_unit_title') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">
              {{ t('organisation_structure_page.add_unit_under', { parent: store.addParentName || t('organisation_structure_page.add_unit_under_root') }) }}
            </p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="store.closeAdd" />
      </div>

      <!-- Body -->
      <VCardText class="pa-6" style="overflow-y:auto;max-height:55vh">
        <VForm ref="refForm" @submit.prevent="onSubmit">

          <div class="form-block mb-4">
            <p class="block-label">{{ t('organisation_structure_page.unit_name_label') }}</p>
            <VTextField v-model="form.name" :rules="[required]" :error-messages="form.errors.name"
              :label="t('organisation_structure_page.unit_name_label') + ' *'" :placeholder="t('organisation_structure_page.unit_name_placeholder')"
              prepend-inner-icon="tabler-building" variant="outlined" density="compact" autofocus />
          </div>

          <div class="form-block mb-4">
            <p class="block-label">{{ t('organisation_structure_page.type_label') }}</p>
            <VSelect v-model="form.type" :items="formattedUnitTypes" :rules="[required]"
              :label="t('organisation_structure_page.type_label') + ' *'" prepend-inner-icon="tabler-tag"
              variant="outlined" density="compact" clearable />
          </div>

          <div class="form-block">
            <p class="block-label">{{ t('organisation_structure_page.manager_label') }}</p>
            <VAutocomplete
              v-model="form.user_id"
              :items="formattedUsers"
              item-title="display_name"
              item-value="id"
              :rules="[required]"
              :label="t('organisation_structure_page.select_manager')"
              prepend-inner-icon="tabler-user-pentagon"
              variant="outlined"
              density="compact"
              clearable
              :no-data-text="t('organisation_structure_page.no_users_found')"
            >
              <template #item="{ item, props: ip }">
                <VListItem v-bind="ip" :title="undefined">
                  <template #prepend>
                    <div class="me-3">
                      <UserAvatar
                        :src="item.raw.profile_pic"
                        :initial="item.raw.initial || `${item.raw.first_name?.[0] ?? ''}${item.raw.surname?.[0] ?? ''}`"
                        :size="32"
                        rounded="full"
                      />
                    </div>
                  </template>
                  <VListItemTitle class="text-body-2 font-weight-medium">
                    {{ item.raw.first_name }} {{ item.raw.surname }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-caption">
                    {{ item.raw.job_function || item.raw.email || '' }}
                  </VListItemSubtitle>
                </VListItem>
              </template>
            </VAutocomplete>
          </div>

        </VForm>
      </VCardText>

      <!-- Footer -->
      <div class="d-flex gap-3 px-6 py-4 flex-shrink-0"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" class="flex-grow-1" rounded="lg"
          :disabled="form.processing" @click="store.closeAdd">
          {{ t('organisation_structure_page.cancel') }}
        </VBtn>
        <VBtn color="primary" class="flex-grow-1" rounded="lg" :loading="form.processing" @click="onSubmit">
          <template #loader>
            <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
            <span>{{ t('organisation_structure_page.adding') }}</span>
          </template>
          <VIcon icon="tabler-plus" size="16" class="me-2" />
          {{ t('organisation_structure_page.add_unit_btn') }}
        </VBtn>
      </div>

    </VCard>
  </VDialog>
</template>

<style scoped>
.form-block { background:rgba(var(--v-border-color),.04); border:1px solid rgba(var(--v-border-color),var(--v-border-opacity)); border-radius:12px; padding:16px; }
.block-label { font-size:11px; font-weight:700; letter-spacing:.6px; color:rgba(var(--v-theme-on-surface),.5); text-transform:uppercase; margin:0 0 12px; }
</style>