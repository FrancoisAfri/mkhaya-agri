<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import AddRole from './partials/Add.vue'
import EditRole from './partials/Edit.vue'
import { useRoleStore } from './Store/roleStore'
import type { RoleProperties, Permission, UserWithRole, PaginatedUsers } from './types/type'

const { t } = useI18n()

const props = defineProps<{
    roles: RoleProperties[]
    permissions: Permission[]
    users: PaginatedUsers
}>()

const store = useRoleStore()
const isAddOpen = ref(false)
const isEditOpen = ref(false)
const deleteDialog = ref(false)
const selectedRole = ref<RoleProperties | null>(null)
const roleToDelete = ref<RoleProperties | null>(null)
const search = ref('')
const roleFilter = ref('')
const currentPage = ref(props.users.current_page)
const perPage = ref(props.users.per_page)

watch(() => props.roles, (val) => store.setRoles(val), { immediate: true })
watch(() => props.users.current_page, (val) => { currentPage.value = val })

function displayName(user: { email: string; person?: { first_name?: string | null; surname?: string | null } | null }) {
    const full = `${user.person?.first_name ?? ''} ${user.person?.surname ?? ''}`.trim()
    return full || user.email
}

function getInitials(user: { email: string; person?: { first_name?: string | null; surname?: string | null } | null }) {
    const f = user.person?.first_name?.[0] ?? ''
    const s = user.person?.surname?.[0] ?? ''
    return (f + s).toUpperCase() || user.email[0].toUpperCase()
}

function sampleInitials(u: { person?: { first_name?: string | null; surname?: string | null } | null }) {
    const f = u.person?.first_name?.[0] ?? ''
    const s = u.person?.surname?.[0] ?? ''
    return (f + s).toUpperCase() || '?'
}

function openEdit(role: RoleProperties) {
    selectedRole.value = role
    isEditOpen.value = true
}

function confirmDelete(role: RoleProperties) {
    roleToDelete.value = role
    deleteDialog.value = true
}

function doDelete() {
    if (!roleToDelete.value) return
    deleteDialog.value = false
    router.delete(`/settings/role/${roleToDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            store.deleteRole(roleToDelete.value!.id)
            roleToDelete.value = null
        },
    })
}

let searchTimer: ReturnType<typeof setTimeout>
function fetchUsers(page = 1) {
    router.get(
        route('settings.role.list'),
        { search: search.value, role: roleFilter.value, page, per_page: perPage.value },
        { preserveState: true, preserveScroll: true, only: ['users'] },
    )
}

function onSearch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => fetchUsers(1), 350)
}

function onUpdateOptions({ page, itemsPerPage }: { page: number; itemsPerPage: number }) {
    currentPage.value = page
    perPage.value = itemsPerPage
    fetchUsers(page)
}

const roleFilterItems = computed(() => [
    { title: t('roles_page.all_roles'), value: '' },
    ...store.roles.map(r => ({ title: r.name, value: r.name })),
])

const headers = computed(() => [
    { title: t('roles_page.table_user'), key: 'user', sortable: false },
    { title: t('roles_page.table_role'), key: 'role', sortable: false },
    { title: t('roles_page.table_status'), key: 'status', sortable: false },
    { title: t('roles_page.table_actions'), key: 'actions', sortable: false, align: 'end' as const },
])

function statusColor(status: number) {
    return status === 1 ? 'success' : 'default'
}
function statusLabel(status: number) {
    return status === 1 ? t('roles_page.active') : t('roles_page.inactive')
}

const ROLE_COLORS: Record<string, string> = {
    'super admin': 'error', administrator: 'error',
    manager: 'warning',
    editor: 'info',
    user: 'success', subscriber: 'success',
    maintainer: 'secondary',
    author: 'teal',
}
function roleColor(name: string) {
    return ROLE_COLORS[name.toLowerCase()] ?? 'primary'
}
</script>

<template>
    <VContainer fluid class="pa-4 pa-sm-6">

        <!-- ── Page Header ── -->
        <div class="mb-6">
            <h3 class="text-h5 font-weight-bold mb-1">{{ t('roles_page.title') }}</h3>
            <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('roles_page.subtitle') }}
            </p>
        </div>

        <!-- ── Role Cards Grid ── -->
        <VRow>
            <VCol v-for="role in store.roles" :key="role.id" cols="12" sm="6" lg="4">
                <VCard rounded="lg" elevation="0" border class="h-100">
                    <VCardText class="pa-5">
                        <div class="d-flex align-center justify-space-between mb-4">
                            <span class="text-body-2 text-medium-emphasis">
                                {{ t('roles_page.total_users', { count: role.users_count ?? 0 }) }}
                            </span>
                            <div class="d-flex align-center" style="padding-left: 8px;">
                                <VAvatar v-for="(u, i) in (role.sample_users ?? []).slice(0, 4)" :key="u.id" size="32"
                                    color="primary" variant="tonal"
                                    :style="`margin-left: -8px; border: 2px solid white; z-index: ${i + 1};`">
                                    <span class="text-caption font-weight-bold">{{ sampleInitials(u) }}</span>
                                </VAvatar>
                                <VAvatar v-if="(role.users_count ?? 0) > (role.sample_users?.length ?? 0)" size="32"
                                    color="primary" variant="tonal"
                                    style="margin-left: -8px; border: 2px solid white; z-index: 5;"
                                    class="text-caption font-weight-bold">
                                    +{{ (role.users_count ?? 0) - (role.sample_users?.length ?? 0) }}
                                </VAvatar>
                            </div>
                        </div>
                        <h6 class="text-h6 font-weight-bold mb-1 text-capitalize">{{ role.name }}</h6>
                        <div class="d-flex align-center justify-space-between mt-2">
                            <a href="#" class="text-primary text-body-2 font-weight-medium text-decoration-none"
                                @click.prevent="openEdit(role)">
                                {{ t('roles_page.edit_role') }}
                            </a>
                            <!-- <VBtn icon variant="text" size="small" density="compact" :title="t('roles_page.copy_role', { name: role.name })">
                                <VIcon icon="tabler-copy" size="16" />
                            </VBtn> -->
                        </div>
                    </VCardText>
                </VCard>
            </VCol>

            <VCol cols="12" sm="6" lg="4">
                <VCard rounded="lg" elevation="0" border class="h-100" style="min-height: 130px;">
                    <VCardText class="pa-5 d-flex align-center justify-space-between h-100" style="min-height: 130px;">
                        <div>
                            <VBtn color="primary" rounded="lg" class="mb-3" prepend-icon="tabler-plus"
                                @click="isAddOpen = true">
                                {{ t('roles_page.add_new_role') }}
                            </VBtn>
                            <p class="text-body-2 text-medium-emphasis mb-0">
                                {{ t('roles_page.add_new_role_sub') }}
                            </p>
                        </div>
                        <div class="d-flex align-center justify-center rounded-circle"
                            style="width: 80px; height: 80px; background: rgba(var(--v-theme-primary), 0.06); flex-shrink: 0;">
                            <VIcon icon="tabler-shield-lock" size="40" color="primary" style="opacity: 0.5;" />
                        </div>
                    </VCardText>
                </VCard>
            </VCol>
        </VRow>

        <!-- ── Users Table ── -->
        <div class="mt-10 mb-4">
            <h4 class="text-h6 font-weight-bold mb-1">{{ t('roles_page.users_table_title') }}</h4>
            <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('roles_page.users_table_sub') }}
            </p>
        </div>

        <VCard elevation="0" border rounded="lg">
            <div class="d-flex flex-wrap align-center gap-3 pa-4">
                <VTextField v-model="search" :placeholder="t('roles_page.search_user')" prepend-inner-icon="tabler-search"
                    variant="outlined" density="compact" rounded="lg" hide-details clearable style="max-width: 240px;"
                    @input="onSearch" @click:clear="() => { search = ''; onSearch() }" />
                <VSpacer />
                <VSelect v-model="roleFilter" :items="roleFilterItems" :placeholder="t('roles_page.select_role')" variant="outlined"
                    density="compact" rounded="lg" hide-details style="max-width: 180px;"
                    @update:model-value="onSearch" />
            </div>

            <VDivider />

            <VDataTableServer :headers="headers" :items="props.users.data" :items-length="props.users.total"
                :items-per-page="perPage" :page="currentPage" :items-per-page-options="[10, 25, 50]" hover
                @update:options="onUpdateOptions">
                <template #item.user="{ item }: { item: UserWithRole }">
                    <div class="d-flex align-center gap-3 py-2">
                        <VAvatar size="36" color="primary" variant="tonal" rounded="lg">
                            <span class="text-caption font-weight-bold">{{ getInitials(item) }}</span>
                        </VAvatar>
                        <div>
                            <p class="text-body-2 font-weight-semibold mb-0">{{ displayName(item) }}</p>
                            <p class="text-caption text-medium-emphasis mb-0">{{ item.email }}</p>
                        </div>
                    </div>
                </template>

                <template #item.role="{ item }: { item: UserWithRole }">
                    <VChip v-if="item.roles?.[0]" size="small" :color="roleColor(item.roles[0].name)" variant="tonal"
                        class="text-capitalize">
                        <VIcon icon="tabler-shield-lock" size="13" class="me-1" />
                        {{ item.roles[0].name }}
                    </VChip>
                    <span v-else class="text-caption text-medium-emphasis font-italic">{{ t('roles_page.no_role') }}</span>
                </template>

                <template #item.status="{ item }: { item: UserWithRole }">
                    <VChip size="small" :color="statusColor(item.status)" variant="tonal" label>
                        {{ statusLabel(item.status) }}
                    </VChip>
                </template>

                <template #item.actions="{ item }: { item: UserWithRole }">
                    <div class="d-flex align-center justify-end gap-1">
                        <VTooltip :text="t('roles_page.view_profile')" location="top">
                            <template #activator="{ props: tp }">
                                <VBtn v-bind="tp" icon variant="text" size="small" density="compact"
                                    :href="item.person?.uuid ? `/user-profile/${item.person.uuid}` : undefined"
                                    :disabled="!item.person?.uuid">
                                    <VIcon icon="tabler-eye" size="18" />
                                </VBtn>
                            </template>
                        </VTooltip>
                        <VMenu location="bottom end">
                            <template #activator="{ props: mp }">
                                <VBtn v-bind="mp" icon variant="text" size="small" density="compact">
                                    <VIcon icon="tabler-dots-vertical" size="18" />
                                </VBtn>
                            </template>
                            <VList density="compact" min-width="150">
                                <VListItem v-if="item.person?.uuid" @click="router.visit(`/user/edit/${item.person?.uuid}`)">
                                    <template #prepend>
                                        <VIcon icon="tabler-pencil" size="14" color="primary" class="me-2" />
                                    </template>
                                    <template #title>
                                        <span class="text-body-2">{{ t('roles_page.edit_user') }}</span>
                                    </template>
                                </VListItem>
                                <!-- <VDivider v-if="item.person?.uuid" /> -->
                                <!-- <VListItem>
                                    <template #prepend>
                                        <VIcon icon="tabler-trash" size="14" color="error" class="me-2" />
                                    </template>
                                    <template #title>
                                        <span class="text-body-2 text-error">{{ t('roles_page.remove_role') }}</span>
                                    </template>
                                </VListItem> -->
                            </VList>
                        </VMenu>
                    </div>
                </template>

                <template #no-data>
                    <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                        <VIcon icon="tabler-users" size="52" class="mb-3" style="opacity: 0.25;" />
                        <p class="text-body-1 font-weight-medium mb-1">{{ t('roles_page.no_users_found') }}</p>
                        <p class="text-caption mb-0">{{ t('roles_page.no_users_sub') }}</p>
                    </div>
                </template>
            </VDataTableServer>
        </VCard>

        <AddRole v-model:isDrawerOpen="isAddOpen" :permissions="permissions"
            @created="() => router.reload({ only: ['roles'] })" />

        <EditRole v-model:isDrawerOpen="isEditOpen" :role="selectedRole" :permissions="permissions"
            @updated="store.updateRole" />

        <!-- ── Delete Confirmation ── -->
        <VDialog v-model="deleteDialog" max-width="420">
            <VCard rounded="lg" elevation="0">
                <div class="px-6 py-5">
                    <div class="d-flex align-center gap-3 mb-3">
                        <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                            style="width: 44px; height: 44px; background: #fef2f2;">
                            <VIcon icon="tabler-alert-triangle" color="error" size="22" />
                        </div>
                        <div>
                            <p class="text-body-1 font-weight-semibold mb-0">{{ t('roles_page.delete_role_title') }}</p>
                            <p class="text-caption text-medium-emphasis mb-0">{{ t('roles_page.delete_role_sub') }}</p>
                        </div>
                    </div>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                        {{ t('roles_page.delete_role_confirm', { name: roleToDelete?.name }) }}
                    </p>
                </div>
                <div class="d-flex gap-3 px-6 pb-5">
                    <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="deleteDialog = false">
                        {{ t('roles_page.cancel') }}
                    </VBtn>
                    <VBtn color="error" class="flex-grow-1" rounded="lg" @click="doDelete">
                        <VIcon icon="tabler-trash" size="16" class="me-2" />
                        {{ t('roles_page.delete') }}
                    </VBtn>
                </div>
            </VCard>
        </VDialog>

        <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right"
            rounded="lg">
            <div class="d-flex align-center gap-2">
                <VIcon :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'"
                    size="16" />
                {{ store.snackMsg }}
            </div>
        </VSnackbar>

    </VContainer>
</template>

<style scoped>
:deep(.v-data-table-server tbody tr:hover td) {
    background: rgba(var(--v-theme-primary), 0.04);
}
</style>
