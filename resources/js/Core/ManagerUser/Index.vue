<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import AddNewUser from "./partials/Add.vue";
import { useI18n } from "vue-i18n";
import { useManagerUserStore } from "./Store/ManagerUser";
import ImportModal from "./partials/Import.vue";
import { UserProperties } from "./types/type";


type NonLoginEmployee = { id: number; first_name: string; surname: string; employee_number: string | null; phone_number: string | null };

const props = defineProps<{
    users: UserProperties[] | null;
    managers: UserProperties[] | null;
    nonLoginEmployees?: NonLoginEmployee[];
    roles: { id: number; name: string }[];
    positions: { id: number; name: string }[];
    leave_profiles: { id: number; name: string }[];
    companyUnits: {
        divisions: { id: number; name: string; parent_id: number | null }[];
        departments: { id: number; name: string; parent_id: number | null }[];
        teams: { id: number; name: string; parent_id: number | null }[];
        sections: { id: number; name: string; parent_id: number | null }[];
        units: { id: number; name: string; parent_id: number | null }[];
    };
    open_user_id?: number | null;
}>();

const selectedUser = ref<UserProperties | null>(null);
const isModalOpen = ref(false);
const store = useManagerUserStore();

// ── New-user credentials (copyable, shown after create / enable-login) ────────
const page = usePage();
const credentials = ref<any>(null);
const copiedKey = ref("");
watch(() => (page.props as any).flash?.credentials, (c) => { if (c) credentials.value = c; }, { immediate: true });
async function copyText(text: string, key: string) {
    try {
        await navigator.clipboard.writeText(text);
        copiedKey.value = key;
        setTimeout(() => { if (copiedKey.value === key) copiedKey.value = ""; }, 1500);
    } catch { /* clipboard unavailable */ }
}
function copyAll(c: any) {
    copyText(`Login: ${c.login_url}\nEmail: ${c.email}\nPassword: ${c.password}`, "all");
}

// ── Non-login employees: list + "enable login" ────────────────────────────────
const enableLoginDialog = ref(false);
const enableTarget = ref<NonLoginEmployee | null>(null);
const enableForm = ref<{ email: string; role: string | null }>({ email: "", role: null });
function openEnableLogin(emp: NonLoginEmployee) {
    enableTarget.value = emp;
    enableForm.value = { email: "", role: null };
    enableLoginDialog.value = true;
}
function submitEnableLogin() {
    if (!enableTarget.value || !enableForm.value.email) return;
    router.post(`/settings/employees/${enableTarget.value.id}/enable-login`, enableForm.value, {
        preserveScroll: true,
        onSuccess: () => {
            enableLoginDialog.value = false;
            store.showSnack("Login enabled — credentials emailed to the employee.", "success");
            router.reload({ only: ["nonLoginEmployees", "users"] });
        },
    });
}
const capitalize = (str: string) => {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
}
const rolesUpperCase = computed(() => {
    return props.roles.map(role => ({
        ...role,
   name: capitalize(role.name)
    }));
});

onMounted(() => {
    if (props.open_user_id) {
        const user = store.users.find((u) => u.id === props.open_user_id);
        if (user) {
            selectedUser.value = user;
            isModalOpen.value = true;
        }
    }
});

const { t } = useI18n();

const allUsers = ref<UserProperties[]>(props.users ?? []);
const activeRoleId = ref<number | null>(null);
const search = ref("");
const isAddOpen = ref(false);
const selected = ref<number[]>([]);
const perPage = ref(10);

const deleteDialog = ref(false);
const userToDelete = ref<UserProperties | null>(null);

watch(
    () => props.users,
    (val) => {
        allUsers.value = val ?? [];
        store.setUsers(val);
    },
    { immediate: true },
);

watch([activeRoleId, search], () => {
    selected.value = [];
});

const filtered = computed(() =>
    store.users.filter((u) => {
        const matchRole = activeRoleId.value
            ? u.roles.some((r) => r.id === activeRoleId.value)
            : true;
        const name =
            `${u.person?.first_name ?? ""} ${u.person?.surname ?? ""} ${u.email}`.toLowerCase();
        const matchSearch = name.includes(search.value.toLowerCase());
        return matchRole && matchSearch;
    }),
);

const headers = [
    { title: t("user"), key: "name", sortable: true },
    { title: t("email"), key: "email", sortable: true },
    { title: t("status"), key: "status", sortable: true },
    {
        title: t("actions"),
        key: "actions",
        sortable: false,
        align: "center" as const,
    },
];

const goToEdit = (user: UserProperties) => {
    /*
     * The personnel record if there is one, otherwise the user.
     *
     * This used to refuse outright: no hr_people row meant no uuid to put in the URL, so the
     * button answered "no profile for this user" and went nowhere. Every client, subcontractor
     * and portal account fell into that — they are listed here like everybody else and simply
     * could not be opened. The profile route now accepts either uuid, so the account is viewable
     * and the employee sections are empty, which is the truth about somebody who is not one.
     */
    const uuid = user.person?.uuid ?? user.uuid;

    if (!uuid) {
        store.showSnack(t("no_profile_for_user"), "warning");
        return;
    }

    router.visit(`/user-profile/${uuid}`);
};

const toggleStatus = (user: UserProperties) => {
    const newStatus = user.status === 1 ? 0 : 1;
    router.post(
        `/settings/users/${user.id}/toggle-status`,
        { status: newStatus },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                store.updateUserStatus(user.id, newStatus);
                store.showSnack(
                    newStatus === 1
                        ? t("user_activated")
                        : t("user_deactivated"),
                    newStatus === 1 ? "success" : "warning",
                );
            },
        },
    );
};

const confirmDelete = (user: UserProperties) => {
    userToDelete.value = user;
    deleteDialog.value = true;
};

const doDelete = () => {
    if (!userToDelete.value) return;
    router.delete(`/settings/delete/${userToDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            store.deleteUser(userToDelete.value!.id);
            deleteDialog.value = false;
            userToDelete.value = null;
        },
    });
};

const getUserName = (u: UserProperties | null) => {
    if (!u) return "";
    return u.person ? `${u.person.first_name} ${u.person.surname}` : u.email;
};

const bulkDeleteDialog = ref(false);
const bulkDeleting = ref(false);
const doBulkDelete = () => {
    if (!selected.value.length) return;
    bulkDeleting.value = true;
    const ids = [...selected.value];
    router.post(
        `/settings/users/bulk-delete`,
        { ids },
        {
            preserveScroll: true,
            onSuccess: () => {
                ids.forEach((id) => store.deleteUser(id));
                selected.value = [];
                bulkDeleteDialog.value = false;
            },
            onFinish: () => {
                bulkDeleting.value = false;
            },
        },
    );
};

const getInitials = (u: UserProperties) => {
    if (u.person && u.person.first_name && u.person.surname) {
        return `${u.person.first_name[0]}${u.person.surname[0]}`.toUpperCase();
    }
    return u.email[0].toUpperCase();
};

const getAvatarColor = (user: UserProperties) =>
    user.roles.length && user.roles[0].id === 1 ? "#6366f1" : "#ef4444";

let interval: ReturnType<typeof setInterval>;
onMounted(() => {
    interval = setInterval(() => router.reload({ only: ["users"] }), 30000);
});
onUnmounted(() => clearInterval(interval));
</script>

<template>
    <section class="bg-[#f8f8ff]">
        <VContainer fluid class="pa-4 pa-sm-6">
            <!-- Page Title -->
            <div
                class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4"
            >
                <div>
                    <h3 class="text-h5 font-weight-semibold mb-1">
                        {{ t("manager_user") }}
                    </h3>
                    <p class="text-medium-emphasis mb-0">
                        {{ t("manager_user_subtitle") }}
                    </p>
                </div>
                <VBreadcrumbs
                    :items="[
                        { title: t('home'), href: '/', disabled: false },
                        { title: t('manager_user'), disabled: true },
                    ]"
                    density="compact"
                    class="pa-0"
                >
                    <template #divider>
                        <i
                            class="fa-solid fa-chevron-right"
                            style="font-size: 11px"
                        />
                    </template>
                </VBreadcrumbs>
            </div>

            <VDivider class="mb-6" />

            <VCard elevation="0" border rounded="lg">
                <VCardText class="pa-5 pa-sm-6">
                    <!-- Toolbar -->
                    <div class="d-flex flex-wrap align-center gap-3 mb-5">
                        <VSelect
                            v-model="activeRoleId"
                            :items="rolesUpperCase"
                            item-title="name"
                            item-value="id"
                            :label="t('filter_by_role')"
                            density="compact"
                            clearable
                            variant="outlined"
                            hide-details
                            style="min-width: 180px"
                        >

                        </VSelect>
                        <VSpacer class="d-none d-sm-flex" />
                        <VTextField
                            v-model="search"
                            :placeholder="t('search...')"
                            prepend-inner-icon="tabler-search"
                            variant="outlined"
                            density="compact"
                            rounded="lg"
                            clearable
                            hide-details
                            style="max-width: 240px"
                        />
                        <VBtn
                            color="primary"
                            rounded="lg"
                            @click="isAddOpen = true"
                        >
                            <i class="fa-solid fa-plus me-2" />
                            {{ t("add_user") }}
                        </VBtn>
                        <VBtn
                            variant="outlined"
                            color="success"
                            rounded="lg"
                            @click="store.openImport"
                        >
                            <i class="fa-solid fa-file-import me-2" />
                            {{ t("import") }}
                        </VBtn>
                        <VBtn
                            v-if="selected.length"
                            color="error"
                            rounded="lg"
                            @click="bulkDeleteDialog = true"
                        >
                            <i class="fa-solid fa-trash me-2" />
                            {{ t("delete") }} ({{ selected.length }})
                        </VBtn>
                    </div>

                    <!-- Data Table -->
                    <VDataTable
                        :headers="headers"
                        :items="filtered"
                        :items-per-page="perPage"
                        item-value="id"
                        v-model="selected"
                        show-select
                        hover
                        class="rounded-lg"
                    >
                        <template #item.name="{ item }">
                            <div class="d-flex align-center gap-3 py-1">
                                <div
                                    class="d-flex align-center justify-center rounded-lg flex-shrink-0 text-white font-weight-bold"
                                    style="
                                        width: 36px;
                                        height: 36px;
                                        font-size: 13px;
                                    "
                                    :style="{
                                        background: getAvatarColor(item),
                                    }"
                                >
                                    {{ getInitials(item) }}
                                </div>
                                <p class="font-weight-medium text-body-2 mb-0">
                                    {{ getUserName(item) }}
                                </p>
                            </div>
                        </template>

                        <template #item.email="{ item }">
                            <span class="text-body-2">{{ item.email }}</span>
                        </template>

                        <template #item.status="{ item }">
                            <VChip
                                :color="item.status === 1 ? 'success' : 'error'"
                                size="small"
                                class="font-weight-medium"
                            >
                                {{
                                    item.status === 1
                                        ? t("active")
                                        : t("inactive")
                                }}
                            </VChip>
                        </template>

                        <!-- Actions Dropdown -->
                        <template #item.actions="{ item }">
                            <VMenu location="bottom end" offset-y>
                                <template #activator="{ props: mp }">
                                    <VBtn
                                        v-bind="mp"
                                        variant="text"
                                        size="small"
                                        icon
                                        class="hover:bg-gray-100 transition-colors"
                                    >
                                        <i
                                            class="fa-solid fa-ellipsis-vertical text-gray-600"
                                        />
                                    </VBtn>
                                </template>

                                <VCard
                                    class="rounded-lg shadow-md overflow-hidden"
                                    style="min-width: 200px"
                                >
                                    <VList density="compact">
                                        <VListItem
                                            @click="goToEdit(item)"
                                            class="hover:bg-gray-50 cursor-pointer transition-colors"
                                        >
                                            <template #prepend>
                                                <i
                                                    class="fa-regular fa-user me-2 text-primary"
                                                    style="font-size: 13px"
                                                />
                                            </template>
                                            <VListItemTitle>
                                                <span
                                                    class="font-medium text-gray-700"
                                                    >{{ t("profile") }}</span
                                                >
                                            </VListItemTitle>
                                        </VListItem>

                                        <VDivider />

                                        <VListItem
                                            @click="toggleStatus(item)"
                                            class="hover:bg-gray-50 cursor-pointer transition-colors"
                                        >
                                            <template #prepend>
                                                <i
                                                    :class="[
                                                        'fa-solid me-2',
                                                        item.status === 1
                                                            ? 'fa-toggle-off'
                                                            : 'fa-toggle-on',
                                                    ]"
                                                    :style="{
                                                        fontSize: '13px',
                                                        color:
                                                            item.status === 1
                                                                ? '#64748b'
                                                                : '#22c55e',
                                                    }"
                                                />
                                            </template>
                                            <VListItemTitle>
                                                <span
                                                    :style="{
                                                        color:
                                                            item.status === 1
                                                                ? '#64748b'
                                                                : '#22c55e',
                                                    }"
                                                    class="font-medium"
                                                >
                                                    {{
                                                        item.status === 1
                                                            ? t("deactivate")
                                                            : t("activate")
                                                    }}
                                                </span>
                                            </VListItemTitle>
                                        </VListItem>

                                        <VDivider />

                                        <VListItem
                                            @click="confirmDelete(item)"
                                            class="hover:bg-gray-50 cursor-pointer transition-colors"
                                        >
                                            <template #prepend>
                                                <i
                                                    class="fa-solid fa-trash me-2 text-error"
                                                    style="font-size: 13px"
                                                />
                                            </template>
                                            <VListItemTitle>
                                                <span
                                                    class="font-medium text-error"
                                                    >{{ t("delete") }}</span
                                                >
                                            </VListItemTitle>
                                        </VListItem>
                                    </VList>
                                </VCard>
                            </VMenu>
                        </template>
                    </VDataTable>
                </VCardText>
            </VCard>

            <AddNewUser
                v-model:isDrawerOpen="isAddOpen"
                :roles="roles"   :positions="positions" :companyUnits="companyUnits" :leave_profiles="leave_profiles"
                :managers="managers"
                @userData="() => router.reload({ only: ['users'] })"
            />

            <ImportModal />

            <VDialog v-model="deleteDialog" max-width="420" persistent>
                <VCard rounded="lg" elevation="0">
                    <div class="px-6 py-5">
                        <div class="d-flex align-center gap-3 mb-3">
                            <div
                                class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                                style="
                                    width: 44px;
                                    height: 44px;
                                    background: #fef2f2;
                                "
                            >
                                <i
                                    class="fa-solid fa-triangle-exclamation"
                                    style="color: #ef4444; font-size: 18px"
                                />
                            </div>
                            <div>
                                <p
                                    class="text-body-1 font-weight-semibold mb-0"
                                >
                                    {{ t("delete") }} {{ t("user") }}
                                </p>
                                <p
                                    class="text-caption text-medium-emphasis mb-0"
                                >
                                    {{ t("this_action_cannot_be_undone") }}
                                </p>
                            </div>
                        </div>
                        <p class="text-body-2 text-medium-emphasis">
                            {{ t("are_you_sure_delete") }}
                            <strong>{{ getUserName(userToDelete) }}</strong> ?
                        </p>
                    </div>
                    <div class="d-flex gap-3 px-6 pb-5">
                        <VBtn
                            variant="tonal"
                            class="flex-grow-1"
                            rounded="lg"
                            @click="deleteDialog = false"
                        >
                            {{ t("cancel") }}
                        </VBtn>
                        <VBtn
                            color="error"
                            class="flex-grow-1"
                            rounded="lg"
                            @click="doDelete"
                        >
                            <i class="fa-solid fa-trash me-2" />
                            {{ t("delete") }}
                        </VBtn>
                    </div>
                </VCard>
            </VDialog>

            <!-- Bulk delete confirmation -->
            <VDialog v-model="bulkDeleteDialog" max-width="420" persistent>
                <VCard rounded="lg" elevation="0">
                    <div class="px-6 py-5">
                        <div class="d-flex align-center gap-3 mb-3">
                            <div
                                class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                                style="width: 44px; height: 44px; background: #fef2f2"
                            >
                                <i
                                    class="fa-solid fa-triangle-exclamation"
                                    style="color: #ef4444; font-size: 18px"
                                />
                            </div>
                            <div>
                                <p class="text-body-1 font-weight-semibold mb-0">
                                    {{ t("delete") }} {{ selected.length }} {{ t("user") }}(s)
                                </p>
                                <p class="text-caption text-medium-emphasis mb-0">
                                    {{ t("this_action_cannot_be_undone") }}
                                </p>
                            </div>
                        </div>
                        <p class="text-body-2 text-medium-emphasis">
                            {{ t("are_you_sure_delete") }}
                            <strong>{{ selected.length }}</strong>
                            {{ t("user") }}(s)?
                        </p>
                    </div>
                    <div class="d-flex gap-3 px-6 pb-5">
                        <VBtn
                            variant="tonal"
                            class="flex-grow-1"
                            rounded="lg"
                            @click="bulkDeleteDialog = false"
                        >
                            {{ t("cancel") }}
                        </VBtn>
                        <VBtn
                            color="error"
                            class="flex-grow-1"
                            rounded="lg"
                            :loading="bulkDeleting"
                            @click="doBulkDelete"
                        >
                            <i class="fa-solid fa-trash me-2" />
                            {{ t("delete") }}
                        </VBtn>
                    </div>
                </VCard>
            </VDialog>

            <!-- Snackbar -->
            <VSnackbar
                v-model="store.snackbar"
                :timeout="3500"
                :color="store.snackColor"
                location="bottom right"
                rounded="lg"
            >
                <div class="d-flex align-center gap-2">
                    <i
                        :class="
                            store.snackColor === 'success'
                                ? 'fa-solid fa-circle-check'
                                : store.snackColor === 'warning'
                                  ? 'fa-solid fa-toggle-off'
                                  : 'fa-solid fa-trash'
                        "
                        style="font-size: 16px"
                    />
                    {{ store.snackMsg }}
                </div>
            </VSnackbar>

            <!-- Non-login employees (no login account) — visually distinct (amber) -->
            <VCard v-if="(props.nonLoginEmployees?.length ?? 0) > 0" class="mt-6" variant="outlined" style="border-color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), 0.04)">
                <VCardItem>
                    <template #prepend><VIcon icon="tabler-user-off" color="warning" /></template>
                    <VCardTitle>Non-login employees</VCardTitle>
                    <VCardSubtitle>Staff without a sign-in account (e.g. cleaners) — a supervisor manages their leave. Add a login when needed.</VCardSubtitle>
                </VCardItem>
                <VTable density="comfortable">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Employee #</th>
                            <th>Cell</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="emp in props.nonLoginEmployees" :key="emp.id">
                            <td>
                                <div class="d-flex align-center gap-2">
                                    <VChip size="x-small" color="warning" variant="tonal">No login</VChip>
                                    <span>{{ emp.first_name }} {{ emp.surname }}</span>
                                </div>
                            </td>
                            <td class="text-body-2 text-medium-emphasis">{{ emp.employee_number || '—' }}</td>
                            <td class="text-body-2 text-medium-emphasis">{{ emp.phone_number || '—' }}</td>
                            <td class="text-right">
                                <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-login" @click="openEnableLogin(emp)">Enable login</VBtn>
                            </td>
                        </tr>
                    </tbody>
                </VTable>
            </VCard>

            <!-- New-user credentials (copyable) -->
            <VDialog :model-value="!!credentials" max-width="480" @update:model-value="v => { if (!v) credentials = null }">
                <VCard v-if="credentials">
                    <VCardTitle>{{ credentials.title }}</VCardTitle>
                    <VCardText>
                        <VAlert :type="credentials.emailed ? 'success' : 'warning'" variant="tonal" density="compact" class="mb-4">
                            {{ credentials.emailed
                                ? 'Login details were emailed to the user. You can also copy them below.'
                                : 'The email could not be sent — copy these details and send them to the user manually.' }}
                        </VAlert>
                        <div class="d-flex align-center mb-2">
                            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">Login URL</div><div>{{ credentials.login_url }}</div></div>
                            <VBtn size="small" variant="text" @click="copyText(credentials.login_url, 'url')">{{ copiedKey === 'url' ? 'Copied' : 'Copy' }}</VBtn>
                        </div>
                        <div class="d-flex align-center mb-2">
                            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">Email</div><div>{{ credentials.email }}</div></div>
                            <VBtn size="small" variant="text" @click="copyText(credentials.email, 'email')">{{ copiedKey === 'email' ? 'Copied' : 'Copy' }}</VBtn>
                        </div>
                        <div class="d-flex align-center">
                            <div class="flex-grow-1"><div class="text-caption text-medium-emphasis">Password</div><div style="font-family: monospace">{{ credentials.password }}</div></div>
                            <VBtn size="small" variant="text" @click="copyText(credentials.password, 'pw')">{{ copiedKey === 'pw' ? 'Copied' : 'Copy' }}</VBtn>
                        </div>
                    </VCardText>
                    <VCardActions class="pa-4">
                        <VBtn variant="tonal" color="primary" @click="copyAll(credentials)">{{ copiedKey === 'all' ? 'Copied all' : 'Copy all' }}</VBtn>
                        <VSpacer />
                        <VBtn variant="text" @click="credentials = null">Close</VBtn>
                    </VCardActions>
                </VCard>
            </VDialog>

            <!-- Enable-login dialog -->
            <VDialog v-model="enableLoginDialog" max-width="460">
                <VCard>
                    <VCardTitle>Enable login — {{ enableTarget?.first_name }} {{ enableTarget?.surname }}</VCardTitle>
                    <VCardText>
                        <p class="text-body-2 text-medium-emphasis mb-4">Give this employee a sign-in account. Their credentials will be emailed to the address below. This uses one user seat.</p>
                        <VTextField v-model="enableForm.email" label="Email *" type="email" prepend-inner-icon="tabler-mail" variant="outlined" density="compact" class="mb-3" />
                        <VSelect v-model="enableForm.role" :items="props.roles.map(r => ({ title: r.name, value: r.name }))" label="Role" prepend-inner-icon="tabler-shield" variant="outlined" density="compact" clearable />
                    </VCardText>
                    <VCardActions class="pa-4">
                        <VSpacer />
                        <VBtn variant="text" @click="enableLoginDialog = false">Cancel</VBtn>
                        <VBtn color="primary" :disabled="!enableForm.email" @click="submitEnableLogin">Enable login</VBtn>
                    </VCardActions>
                </VCard>
            </VDialog>
        </VContainer>
    </section>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) {
    background: rgba(var(--v-theme-primary), 0.04);
}
</style>
