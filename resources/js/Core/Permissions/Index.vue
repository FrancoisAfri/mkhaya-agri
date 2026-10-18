<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AddPermission  from './partials/Add.vue'
import EditPermission from './partials/Edit.vue'
import { usePermissionStore } from './Store/permissionStore'
import type { PermissionProperties } from './types/type'

const { t } = useI18n()

const props = defineProps<{ permissions: PermissionProperties[] }>()

const store = usePermissionStore()
const isAddOpen = ref(false)
const isEditOpen = ref(false)
const deleteDialog = ref(false)
const selectedPermission = ref<PermissionProperties | null>(null)
const permToDelete = ref<PermissionProperties | null>(null)
const search = ref('')
const guardFilter = ref('')

watch(() => props.permissions, (val) => store.setPermissions(val), { immediate: true })

const PERM_META: Record<string, { icon: string; color: string; label: string }> = {
  view:    { icon: 'tabler-eye',          color: 'info',      label: t('permissions.meta.view')    },
  create:  { icon: 'tabler-circle-plus',  color: 'success',   label: t('permissions.meta.create')  },
  edit:    { icon: 'tabler-pencil',        color: 'warning',   label: t('permissions.meta.edit')    },
  delete:  { icon: 'tabler-trash',        color: 'error',     label: t('permissions.meta.delete')  },
  publish: { icon: 'tabler-send',          color: 'secondary', label: t('permissions.meta.publish') },
  export:  { icon: 'tabler-file-export',   color: 'teal',      label: t('permissions.meta.export')  },
}

function permMeta(name: string) {
  const prefix = name.split(' ')[0].toLowerCase()
  return PERM_META[prefix] ?? { icon: 'tabler-key', color: 'primary', label: prefix }
}

function guardColor(guard: string | undefined) {
  return guard === 'api' ? 'warning' : 'primary'
}

const categoryCards = computed(() => {
  const groups: Record<string, number> = {}
  store.permissions.forEach(p => {
    const prefix = p.name.split(' ')[0].toLowerCase()
    groups[prefix] = (groups[prefix] ?? 0) + 1
  })
  const order = ['view', 'create', 'edit', 'delete', 'publish', 'export']
  return Object.entries(groups).sort(([a], [b]) => {
    const ai = order.indexOf(a), bi = order.indexOf(b)
    if (ai === -1 && bi === -1) return a.localeCompare(b)
    if (ai === -1) return 1
    if (bi === -1) return -1
    return ai - bi
  })
})

const filtered = computed(() => {
  let list = store.permissions
  if (search.value) list = list.filter(p => p.name.toLowerCase().includes(search.value.toLowerCase()))
  if (guardFilter.value) list = list.filter(p => (p.guard_name ?? 'web') === guardFilter.value)
  return list
})

const headers = [
  { title: t('permissions.table.permission'),    key: 'name',        sortable: true  },
  { title: t('permissions.table.guard'),         key: 'guard_name',  sortable: true  },
  { title: t('permissions.table.assigned_roles'), key: 'roles_count', sortable: true  },
  { title: t('permissions.table.actions'),       key: 'actions',     sortable: false, align: 'end' as const },
]

const guardOptions = [
  { title: t('permissions.guard_filter_all'), value: '' },
  { title: 'web', value: 'web' },
  { title: 'api', value: 'api' },
]

function openEdit(p: PermissionProperties) { selectedPermission.value = p; isEditOpen.value = true }
function confirmDelete(p: PermissionProperties) { permToDelete.value = p; deleteDialog.value = true }
function doDelete() {
  if (!permToDelete.value) return
  deleteDialog.value = false
  router.delete(`/settings/permissions/delete/${permToDelete.value.id}`, {
    preserveScroll: true,
    onSuccess: () => { store.deletePermission(permToDelete.value!.id); permToDelete.value = null },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="mb-6">
      <h3 class="text-h5 font-weight-bold mb-1">{{ t('permissions.page_title') }}</h3>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('permissions.subtitle') }}</p>
    </div>

    <VRow>
      <VCol cols="12" sm="6" lg="3">
        <VCard rounded="lg" elevation="0" border class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center gap-4">
              <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width: 52px; height: 52px; background: rgba(var(--v-theme-primary), 0.1);">
                <VIcon icon="tabler-shield-lock" size="26" color="primary" />
              </div>
              <div>
                <p class="text-h4 font-weight-bold mb-0">{{ store.permissions.length }}</p>
                <p class="text-body-2 text-medium-emphasis mb-0">{{ t('permissions.total_permissions') }}</p>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-for="([prefix, count]) in categoryCards" :key="prefix" cols="6" sm="4" lg="3">
        <VCard rounded="lg" elevation="0" border class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-3">
              <VChip :color="permMeta(prefix + ' x').color" size="small" variant="tonal" :prepend-icon="permMeta(prefix + ' x').icon" class="text-capitalize font-weight-medium">{{ permMeta(prefix + ' x').label }}</VChip>
              <span class="text-h5 font-weight-bold">{{ count }}</span>
            </div>
            <p class="text-caption text-medium-emphasis mb-0">{{ count }} {{ count === 1 ? t('permissions.permission_singular') : t('permissions.permission_plural') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" lg="3">
        <VCard rounded="lg" elevation="0" border class="h-100" style="min-height: 110px;">
          <VCardText class="pa-5 d-flex align-center justify-space-between h-100" style="min-height: 110px;">
            <div>
              <VBtn color="primary" rounded="lg" class="mb-3" prepend-icon="tabler-plus" @click="isAddOpen = true">{{ t('permissions.add_permission_card_title') }}</VBtn>
              <p class="text-body-2 text-medium-emphasis mb-0">{{ t('permissions.add_permission_card_desc') }}</p>
            </div>
            <div class="d-flex align-center justify-center rounded-circle flex-shrink-0" style="width: 72px; height: 72px; background: rgba(var(--v-theme-primary), 0.06);">
              <VIcon icon="tabler-key-plus" size="34" color="primary" style="opacity: 0.5;" />
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <div class="mt-10 mb-4">
      <h4 class="text-h6 font-weight-bold mb-1">{{ t('permissions.all_permissions') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('permissions.all_permissions_desc') }}</p>
    </div>

    <VCard elevation="0" border rounded="lg">
      <div class="d-flex flex-wrap align-center gap-3 pa-4">
        <VTextField v-model="search" :placeholder="t('permissions.search_placeholder')" prepend-inner-icon="tabler-search" variant="outlined" density="compact" rounded="lg" hide-details clearable style="max-width: 260px;" />
        <VSpacer />
        <VSelect v-model="guardFilter" :items="guardOptions" :placeholder="t('permissions.guard_filter_all')" variant="outlined" density="compact" rounded="lg" hide-details style="max-width: 160px;" />
        <VBtn color="primary" rounded="lg" prepend-icon="tabler-plus" @click="isAddOpen = true">{{ t('permissions.new_permission') }}</VBtn>
      </div>
      <VDivider />
      <VDataTable :headers="headers" :items="filtered" :items-per-page="15" :items-per-page-options="[15, 30, 50]" hover class="rounded-b-lg">
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-3 py-1">
            <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="`width: 34px; height: 34px; background: rgba(var(--v-theme-${permMeta(item.name).color}), 0.12);`">
              <VIcon :icon="permMeta(item.name).icon" size="16" :color="permMeta(item.name).color" />
            </div>
            <span class="text-body-2 font-weight-medium">{{ item.name }}</span>
          </div>
        </template>
        <template #item.guard_name="{ item }"><VChip :color="guardColor(item.guard_name)" size="x-small" variant="tonal" label>{{ item.guard_name ?? 'web' }}</VChip></template>
        <template #item.roles_count="{ item }">
          <VChip v-if="(item.roles_count ?? 0) > 0" size="small" color="primary" variant="tonal" prepend-icon="tabler-shield-lock">{{ item.roles_count }} {{ (item.roles_count ?? 0) === 1 ? t('permissions.table.role_singular') : t('permissions.table.role_plural') }}</VChip>
          <span v-else class="text-caption text-medium-emphasis font-italic">{{ t('permissions.table.unassigned') }}</span>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex align-center justify-end gap-1">
            <VTooltip :text="t('permissions.table.edit')" location="top">
              <template #activator="{ props: tp }"><VBtn v-bind="tp" icon variant="text" size="small" density="compact" @click="openEdit(item)"><VIcon icon="tabler-pencil" size="17" /></VBtn></template>
            </VTooltip>
            <VTooltip :text="t('permissions.table.delete')" location="top">
              <template #activator="{ props: tp }"><VBtn v-bind="tp" icon variant="text" size="small" density="compact" color="error" @click="confirmDelete(item)"><VIcon icon="tabler-trash" size="17" /></VBtn></template>
            </VTooltip>
          </div>
        </template>
        <template #no-data>
          <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
            <VIcon icon="tabler-key" size="52" class="mb-3" style="opacity: 0.25;" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('permissions.empty.title') }}</p>
            <p class="text-caption mb-4">{{ t('permissions.empty.description') }}</p>
            <VBtn color="primary" size="small" rounded="lg" prepend-icon="tabler-plus" @click="isAddOpen = true">{{ t('permissions.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <AddPermission v-model:isDrawerOpen="isAddOpen" @created="() => router.reload({ only: ['permissions'] })" />
    <EditPermission v-model:isDrawerOpen="isEditOpen" :permission="selectedPermission" @updated="store.updatePermission" />

    <VDialog v-model="deleteDialog" max-width="420" persistent>
      <VCard rounded="lg" elevation="0">
        <div class="px-6 py-5">
          <div class="d-flex align-center gap-3 mb-3">
            <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width: 44px; height: 44px; background: #fef2f2;"><VIcon icon="tabler-alert-triangle" color="error" size="22" /></div>
            <div>
              <p class="text-body-1 font-weight-semibold mb-0">{{ t('permissions.delete_dialog.title') }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ t('permissions.delete_dialog.subtitle') }}</p>
            </div>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('permissions.delete_dialog.message', { name: permToDelete?.name }) }}</p>
        </div>
        <div class="d-flex gap-3 px-6 pb-5">
          <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="deleteDialog = false">{{ t('permissions.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" class="flex-grow-1" rounded="lg" @click="doDelete"><VIcon icon="tabler-trash" size="16" class="me-2" />{{ t('permissions.delete_dialog.confirm') }}</VBtn>
        </div>
      </VCard>
    </VDialog>

    <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
      <div class="d-flex align-center gap-2"><VIcon :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'" size="16" />{{ store.snackMsg }}</div>
    </VSnackbar>
  </VContainer>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) { background: rgba(var(--v-theme-primary), 0.04); }
</style>
