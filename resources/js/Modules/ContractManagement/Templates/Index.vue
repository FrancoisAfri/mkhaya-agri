<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import TiptapEditor from '@/@core/components/TiptapEditor.vue'

const { t } = useI18n()
const route = ziggyRoute

type Template = {
  id: number
  name: string
  content: string | null
  status: boolean
  /** From the shipped library shared by every company, rather than authored by this one. */
  is_shared: boolean
}
const props = defineProps<{ templates: Template[] }>()

const dialog = ref(false)
const editing = ref<Template | null>(null)
const editingShared = computed(() => editing.value?.is_shared === true)
const form = ref({ name: '', content: '', status: true })
const confirmDialog = ref()
const saving = ref(false)

function openCreate() {
  editing.value = null
  form.value = { name: '', content: '', status: true }
  dialog.value = true
}
function openEdit(t: Template) {
  editing.value = t
  form.value = { name: t.name, content: t.content ?? '', status: !!t.status }
  dialog.value = true
}
function save() {
  saving.value = true
  if (editing.value) {
    router.patch(route('contracts.templates.update', editing.value.id), form.value, {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false; saving.value = false },
      onError: () => { saving.value = false },
      onFinish: () => { saving.value = false },
    })
  } else {
    router.post(route('contracts.templates.store'), form.value, {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false; saving.value = false },
      onError: () => { saving.value = false },
      onFinish: () => { saving.value = false },
    })
  }
}
function destroy(template: Template) {
  confirmDialog.value.open(
    {
      title: t('contract_templates.confirm.title'),
      message: t('contract_templates.confirm.message', { name: template.name }),
      confirmText: t('contract_templates.confirm.confirm_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('contracts.templates.destroy', template.id), { preserveScroll: true }) },
  )
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />

    <!-- Loading overlay -->
    <VOverlay v-if="saving" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="d-flex align-center justify-space-between mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-template" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contract_templates.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contract_templates.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
        {{ t('contract_templates.new_template') }}
      </VBtn>
    </div>

    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('contract_templates.table.name') }}</th>
            <th>{{ t('contract_templates.table.status') }}</th>
            <th class="text-right">{{ t('contract_templates.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!templates.length">
            <td colspan="3" class="text-center text-medium-emphasis py-8">
              {{ t('contract_templates.no_templates') }}
            </td>
          </tr>
          <tr v-for="template in templates" :key="template.id">
            <td class="font-weight-medium">
              {{ template.name }}
              <VChip
                v-if="template.is_shared"
                size="x-small"
                color="info"
                variant="tonal"
                class="ms-2"
              >
                {{ t('contract_templates.library_badge') }}
              </VChip>
            </td>
            <td>
              <VChip size="small" :color="template.status ? 'success' : 'grey'" variant="tonal">
                {{ template.status ? t('contract_templates.active') : t('contract_templates.inactive') }}
              </VChip>
            </td>
            <td class="text-right">
              <VBtn
                icon="tabler-edit"
                size="small"
                variant="text"
                :aria-label="t('contract_templates.edit_aria', { name: template.name })"
                @click="openEdit(template)"
              />
              <VTooltip v-if="template.is_shared" location="top">
                <template #activator="{ props: tip }">
                  <span v-bind="tip">
                    <VBtn icon="tabler-trash" size="small" variant="text" disabled />
                  </span>
                </template>
                {{ t('contract_templates.shared_delete_blocked') }}
              </VTooltip>
              <VBtn
                v-else
                icon="tabler-trash"
                size="small"
                variant="text"
                color="error"
                :aria-label="t('contract_templates.delete_aria', { name: template.name })"
                @click="destroy(template)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="900" scrollable persistent>
      <VCard :loading="saving" :disabled="saving">
        <VCardTitle>
          {{ editing ? t('contract_templates.dialog.edit_title') : t('contract_templates.dialog.new_title') }}
        </VCardTitle>
        <VCardText>
          <VAlert
            v-if="editingShared"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('contract_templates.shared_copy_notice') }}
          </VAlert>
          <VRow class="mb-2">
            <VCol cols="12" md="8">
              <VTextField
                v-model="form.name"
                :label="t('contract_templates.form.template_name')"
                variant="outlined"
                density="compact"
                :disabled="saving"
              />
            </VCol>
            <VCol cols="12" md="4" class="d-flex align-center">
              <VSwitch
                v-model="form.status"
                :label="form.status ? t('contract_templates.form.active') : t('contract_templates.form.inactive')"
                color="success"
                :disabled="saving"
              />
            </VCol>
          </VRow>
          <div class="text-caption text-medium-emphasis mb-1">
            {{ t('contract_templates.form.content') }}
          </div>
          <TiptapEditor
            v-model="form.content"
            :placeholder="t('contract_templates.form.placeholder')"
            :disabled="saving"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" :disabled="saving" @click="dialog = false">
            {{ t('contract_templates.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!form.name || saving"
            :loading="saving"
            @click="save"
          >
            {{ t('contract_templates.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
