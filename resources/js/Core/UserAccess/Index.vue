<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from "vue";
import { router } from "@inertiajs/vue3";
import AccessModal from "./partials/Modal.vue";
import type { UserProperties } from "./types/type";
import { useI18n } from "vue-i18n";

const props = defineProps<{
    users: UserProperties[] | null;
    roles: { id: number; name: string }[];
}>();
const { t } = useI18n();

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
const allUsers = ref<UserProperties[]>(props.users ?? []);
const activeRoleId = ref<number | null>(null);
const search = ref("");
const isModalOpen = ref(false);
const selectedUser = ref<UserProperties | null>(null);
const selected = ref<number[]>([]);
const perPage = ref(10);

watch(
    () => props.users,
    (val) => {
        allUsers.value = val ?? [];
    },
    { immediate: true },
);

watch([activeRoleId, search], () => {
    selected.value = [];
});

const filtered = computed(() =>
    allUsers.value.filter((u) => {
        const matchRole = activeRoleId.value
            ? u.roles?.some((r) => r.id === activeRoleId.value) ?? false
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
    { title: t("roles"), key: "roles", sortable: false },
    { title: t("status"), key: "status", sortable: true },
    {
        title: t("access"),
        key: "access",
        sortable: false,
        align: "center" as const,
    },
];

const openAccess = (user: UserProperties) => {
    selectedUser.value = user;
    isModalOpen.value = true;
};

const getUserName = (u: UserProperties) =>
    u.person ? `${u.person.first_name} ${u.person.surname}` : u.email;

const getInitials = (u: UserProperties) => {
    if (u.person && u.person.first_name && u.person.surname) {
        return `${u.person.first_name[0]}${u.person.surname[0]}`.toUpperCase();
    }
    return u.email[0].toUpperCase();
};

const getAvatarColor = (user: UserProperties) => {
    if (!user.roles || user.roles.length === 0) return "#ef4444";
    const isAdmin = user.roles.some(r => r.name.toLowerCase().includes('admin'));
    const isManager = user.roles.some(r => r.name.toLowerCase().includes('manager'));
    if (isAdmin) return "#6366f1";
    if (isManager) return "#0d9488";
    return "#ef4444";
};

let interval: ReturnType<typeof setInterval>;
onMounted(() => {
    interval = setInterval(() => router.reload({ only: ["users"] }), 30000);
});
onUnmounted(() => clearInterval(interval));
</script>

<template>
    <section class="bg-[#f8f8ff]">
        <VContainer fluid class="pa-4 pa-sm-6">
            <!-- ══ Page Title ══ -->
            <div
                class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4"
            >
                <div>
                    <h3 class="text-h5 font-weight-semibold mb-1">
                        {{ t("user_access") }}
                    </h3>
                    <p class="text-medium-emphasis mb-0">
                        {{ t("user_access_subtitle") }}
                    </p>
                </div>
                <VBreadcrumbs
                    :items="[
                        { title: t('home'), href: '/', disabled: false },
                        { title: t('user_access'), disabled: true },
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

            <!-- ══ Main Panel ══ -->
            <VCard elevation="0" border rounded="lg">
                <VCardText class="pa-5 pa-sm-6">
                    <!-- Toolbar -->
                    <div class="d-flex flex-wrap align-center gap-3 mb-5">
                        <!-- Role Filter -->
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
                            style="min-width: 220px"
                        />

                        <VSpacer class="d-none d-sm-flex" />

                        <!-- Search -->
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
                    </div>

                    <!-- ══ Data Table ══ -->
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
                        <!-- User -->
                        <template #item.name="{ item }">
                            <div class="d-flex align-center gap-3 py-1">
                                <div
                                    class="d-flex align-center justify-center rounded-lg flex-shrink-0 text-white text-caption font-weight-bold"
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

                        <!-- Email -->
                        <template #item.email="{ item }">
                            <span class="text-body-2 text-medium-emphasis">{{
                                item.email
                            }}</span>
                        </template>

                        <!-- Roles -->
                        <template #item.roles="{ item }">
                            <div class="d-flex gap-1 flex-wrap">
                                <VChip
                                    v-for="role in item.roles"
                                    :key="role.id"
                                    size="x-small"
                                    variant="tonal"
                                    color="primary"
                                    label
                                    class="font-weight-medium"
                                >
                                    {{ role.name }}
                                </VChip>
                                <span v-if="!item.roles?.length" class="text-caption text-medium-emphasis">
                                    {{ t("no_role") }}
                                </span>
                            </div>
                        </template>

                        <!-- Status -->
                        <template #item.status="{ item }">
                            <VChip
                                :color="item.status === 1 ? 'success' : 'default'"
                                size="small"
                                variant="tonal"
                                label
                                class="font-weight-medium"
                            >
                                <i
                                    :class="item.status === 1 ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'"
                                    class="me-1"
                                    style="font-size: 10px"
                                />
                                {{ item.status === 1 ? t("active") : t("inactive") }}
                            </VChip>
                        </template>

                        <!-- Access -->
                        <template #item.access="{ item }">
                            <VBtn
                                size="small"
                                color="primary"
                                variant="tonal"
                                rounded="lg"
                                @click="openAccess(item)"
                            >
                                <i
                                    class="fa-solid fa-key me-1"
                                    style="font-size: 11px"
                                />
                                {{ t('access') }}
                            </VBtn>
                        </template>

                        <!-- Empty state -->
                        <template #no-data>
                            <div
                                class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis"
                            >
                                <i
                                    class="fa-solid fa-users mb-3"
                                    style="font-size: 48px; opacity: 0.25"
                                />
                                <p class="text-body-1 font-weight-medium mb-1">
                                    {{ t("users_not_found") }}
                                </p>
                                <p class="text-caption">
                                    {{ t("users_not_found_subtitle") }}
                                </p>
                            </div>
                        </template>
                    </VDataTable>
                </VCardText>
            </VCard>

            <!-- ══ Modal ══ -->
            <AccessModal v-model:isOpen="isModalOpen" :user="selectedUser" />
        </VContainer>
    </section>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) {
    background: rgba(var(--v-theme-primary), 0.04);
}
</style>
