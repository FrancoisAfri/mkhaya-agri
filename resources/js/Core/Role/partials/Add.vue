<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, computed, nextTick, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { Permission } from '../types/type'

const { t } = useI18n()

interface Props {
  isDrawerOpen: boolean
  permissions:  Permission[]
}
interface Emit {
  (e: 'update:isDrawerOpen', value: boolean): void
  (e: 'created'): void
}

const props = defineProps<Props>()
const emit  = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm     = ref<VForm>()
const searchPerm  = ref('')

const requiredValidator = (v: unknown) =>
  (v !== null && v !== undefined && v !== '') || t('roles_page.field_required')

const form = useForm({
  name:        '',
  permissions: [] as string[],
})

const filteredPermissions = computed(() =>
  props.permissions.filter(p =>
    p.name.toLowerCase().includes(searchPerm.value.toLowerCase()) &&
    !form.permissions.includes(p.name)
  )
)

const selectedPermissions = computed(() =>
  props.permissions.filter(p => form.permissions.includes(p.name))
)

const grouped = computed(() => {
  const groups: Record<string, Permission[]> = {}
  filteredPermissions.value.forEach(p => {
    const parts = p.name.split(' ')
    const group = parts.length > 1 ? parts[parts.length - 1] : 'general'
    if (!groups[group]) groups[group] = []
    groups[group].push(p)
  })
  return groups
})

const addPermission = (name: string) => {
  if (!form.permissions.includes(name)) {
    form.permissions = [...form.permissions, name]
  }
  searchPerm.value = ''
}

const removePermission = (name: string) => {
  form.permissions = form.permissions.filter(p => p !== name)
}

const toggleGroup = (perms: Permission[]) => {
  const names = perms.map(p => p.name)
  form.permissions = [...new Set([...form.permissions, ...names])]
  searchPerm.value = ''
}

const clearAll = () => { form.permissions = [] }

const getPermColor = (name: string) => {
  if (name.startsWith('view'))    return 'info'
  if (name.startsWith('create'))  return 'success'
  if (name.startsWith('edit'))    return 'warning'
  if (name.startsWith('delete'))  return 'error'
  if (name.startsWith('publish')) return 'secondary'
  return 'primary'
}

watch(() => props.isDrawerOpen, (val) => {
  if (!val) {
    nextTick(() => {
      form.reset()
      form.clearErrors()
      refForm.value?.resetValidation()
      searchPerm.value = ''
    })
  }
})

const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return

  form.post('/settings/role/store', {
    preserveScroll: true,
    onSuccess: () => {
      emit('created')
      emit('update:isDrawerOpen', false)
    },
  })
}
</script>

<template>
  <VDialog
    :model-value="props.isDrawerOpen"
    max-width="600"
    scrollable
    @update:model-value="emit('update:isDrawerOpen', $event)"
  >
    <VCard rounded="lg" elevation="0">

      <div class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
        style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3 min-w-0 flex-grow-1">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
            style="width:40px;height:40px;background:#ede9fe">
            <i class="fa-solid fa-shield-halved" style="color:#6366f1;font-size:16px" />
          </div>
          <div class="min-w-0">
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('roles_page.add_role_title') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('roles_page.add_role_sub') }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" class="flex-shrink-0" @click="closeDialog" />
      </div>

      <VCardText class="pa-6" style="overflow-y:auto;max-height:65vh">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">

          <div class="form-block mb-4">
            <p class="block-label">{{ t('roles_page.role_name_label') }}</p>
            <VTextField
              v-model="form.name"
              :rules="[requiredValidator]"
              :error-messages="form.errors.name"
              :label="t('roles_page.role_name_label') + ' *'"
              :placeholder="t('roles_page.role_name_placeholder')"
              prepend-inner-icon="tabler-shield-lock"
              variant="outlined"
              density="compact"
            />
          </div>

          <div class="form-block">
            <div class="d-flex align-center justify-space-between mb-3">
              <p class="block-label mb-0">{{ t('roles_page.permissions_label') }}</p>
              <div class="d-flex align-center gap-2">
                <span class="text-caption text-medium-emphasis">
                  {{ t('roles_page.selected_count', { count: form.permissions.length }) }}
                </span>
                <button
                  v-if="form.permissions.length"
                  type="button"
                  class="clear-btn"
                  @click="clearAll"
                >
                  <i class="fa-solid fa-xmark" /> {{ t('roles_page.clear_all') }}
                </button>
              </div>
            </div>

            <div v-if="selectedPermissions.length" class="selected-chips mb-3">
              <VChip
                v-for="perm in selectedPermissions"
                :key="perm.id"
                :color="getPermColor(perm.name)"
                size="small"
                variant="tonal"
                closable
                class="font-weight-medium"
                @click:close="removePermission(perm.name)"
              >
                {{ perm.name }}
              </VChip>
            </div>

            <VTextField
              v-model="searchPerm"
              :placeholder="t('roles_page.search_permissions')"
              prepend-inner-icon="tabler-search"
              variant="outlined"
              density="compact"
              clearable
              hide-details
              class="mb-2"
            />

            <div v-if="searchPerm || Object.keys(grouped).length" class="perm-dropdown">

              <div v-if="!Object.keys(grouped).length" class="empty-search">
                <i class="fa-solid fa-check-circle me-1 text-success" />
                {{ t('roles_page.all_selected') }}
              </div>

              <div v-for="(perms, group) in grouped" :key="group" class="perm-group mb-1">
                <div class="perm-group-header">
                  <div class="d-flex align-center gap-2">
                    <i class="fa-solid fa-folder" style="font-size:11px;color:#6366f1" />
                    <span class="perm-group-title text-capitalize">{{ group }}</span>
                    <span class="text-caption text-medium-emphasis">({{ perms.length }})</span>
                  </div>
                  <button type="button" class="add-all-btn" @click="toggleGroup(perms)">
                    <i class="fa-solid fa-plus-circle" /> {{ t('roles_page.add_all') }}
                  </button>
                </div>

                <div
                  v-for="perm in perms"
                  :key="perm.id"
                  class="perm-item"
                  @click="addPermission(perm.name)"
                >
                  <VChip
                    :color="getPermColor(perm.name)"
                    size="x-small"
                    variant="tonal"
                    label
                    class="font-weight-medium"
                  >
                    {{ perm.name }}
                  </VChip>
                  <i class="fa-solid fa-plus perm-add-icon" />
                </div>
              </div>
            </div>

            <p v-if="!searchPerm && !form.permissions.length" class="text-caption text-medium-emphasis mt-2">
              <i class="fa-solid fa-circle-info me-1" />
              {{ t('roles_page.type_to_search') }}
            </p>

          </div>

        </VForm>
      </VCardText>

      <div class="d-flex gap-3 px-6 py-4 flex-shrink-0"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="lg"
          :disabled="form.processing" @click="closeDialog">
          <i class="fa-regular fa-x me-2" /> {{ t('roles_page.cancel') }}
        </VBtn>
        <VBtn color="success" class="flex-grow-1" rounded="lg"
          :loading="form.processing" @click="onSubmit">
          <template #loader>
            <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
            <span>{{ t('roles_page.saving') }}</span>
          </template>
          <i class="fa-regular fa-floppy-disk me-2" /> {{ t('roles_page.save') }}
        </VBtn>
      </div>

    </VCard>
  </VDialog>
</template>

<style scoped>
.form-block {
  background: rgba(var(--v-border-color), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px; padding: 16px;
}
.block-label {
  font-size: 11px; font-weight: 700; letter-spacing: .6px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  text-transform: uppercase; margin: 0 0 12px;
}
.selected-chips {
  display: flex; flex-wrap: wrap; gap: 6px;
  padding: 10px 12px;
  background: rgba(var(--v-theme-primary), 0.04);
  border: 1px solid rgba(var(--v-theme-primary), 0.15);
  border-radius: 10px;
  min-height: 44px;
}
.perm-dropdown {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
  max-height: 260px;
  overflow-y: auto;
}
.perm-group { border-bottom: 1px solid rgba(var(--v-border-color), 0.06); }
.perm-group:last-child { border-bottom: none; }
.perm-group-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 7px 12px;
  background: rgba(var(--v-border-color), 0.05);
  position: sticky; top: 0; z-index: 1;
}
.perm-group-title { font-size: 11px; font-weight: 700; color: rgba(var(--v-theme-on-surface), .6); }
.perm-item {
  display: flex; align-items: center; justify-content: space-between;
  padding: 7px 12px; cursor: pointer;
  transition: background .1s;
}
.perm-item:hover { background: rgba(var(--v-theme-primary), 0.05); }
.perm-add-icon {
  font-size: 11px; color: #6366f1; opacity: 0;
  transition: opacity .15s;
}
.perm-item:hover .perm-add-icon { opacity: 1; }
.add-all-btn {
  font-size: 11px; font-weight: 600; color: #6366f1;
  background: #eef2ff; border: none; border-radius: 6px;
  padding: 3px 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
  transition: background .15s;
}
.add-all-btn:hover { background: #e0e7ff; }
.clear-btn {
  font-size: 11px; font-weight: 600; color: #ef4444;
  background: #fef2f2; border: none; border-radius: 6px;
  padding: 3px 8px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
}
.clear-btn:hover { background: #fee2e2; }
.empty-search {
  padding: 12px 14px; font-size: 12px; color: #64748b;
}
</style>
