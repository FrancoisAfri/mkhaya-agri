<script setup lang="ts">
import { ref, computed, watch } from "vue";
import { router } from "@inertiajs/vue3";
import AddSpotlight from "./partials/add.vue";
import EditSpotlight from "./partials/edit.vue";
import { useSpotlightStore } from "./Store/store";
import { useI18n } from 'vue-i18n'
import {
    getTypeConfig,
    resolveUserName,
    resolveUserInitial,
} from "./Store/Usespotlightform";
import type {
    EmployeeSpotlight,
    UserProperties,
    PaginatedData,
} from "./Store/type";

const { t } = useI18n()

interface UnitItem {
    id: number;
    name: string;
    parent_id: number | null;
    type: string;
}
interface GroupedUnits {
    divisions?: UnitItem[];
    departments?: UnitItem[];
    teams?: UnitItem[];
    sections?: UnitItem[];
    units?: UnitItem[];
}

const props = withDefaults(
    defineProps<{
        spotlights?: PaginatedData<EmployeeSpotlight> | null;
        companyUnits?: GroupedUnits | null;
        employees?: UserProperties[];
    }>(),
    {
        spotlights: null,
        companyUnits: null,
        employees: () => [],
    },
);

const store = useSpotlightStore();
watch(
    () => props.spotlights?.data,
    (val) => store.setList(val ?? []),
    { immediate: true },
);

const isAddOpen = ref(false);
const isEditOpen = ref(false);
const deleteDialog = ref(false);
const spotToDelete = ref<EmployeeSpotlight | null>(null);

const descriptionDialog = ref(false);
const selectedDescription = ref("");

function openDescription(description: string) {
    selectedDescription.value = description || t('employee_spotlight.no_description');
    descriptionDialog.value = true;
}

const search = ref("");
const currentPage = ref(props.spotlights?.current_page ?? 1);
const perPage = ref(props.spotlights?.per_page ?? 10);

watch(
    () => props.spotlights?.current_page,
    (v) => {
        if (v) currentPage.value = v;
    },
);

const summaryCounts = computed(() => {
    const data = props.spotlights?.data ?? [];
    return [1, 2, 3].map((t) => ({
        type: t,
        count: data.filter((s) => s.type === t).length,
    }));
});

function formatMonth(val: string) {
    if (!val) return "—";
    const date = new Date(val);
    return date.toLocaleDateString("en-US", {
        day: "2-digit",
        month: "long",
        year: "numeric"
    });
}

function truncateText(text: string, maxLength: number = 80): string {
    if (!text) return "—";
    return text.length > maxLength ? text.substring(0, maxLength) + "..." : text;
}

function openEdit(spot: EmployeeSpotlight) {
    store.startEdit(spot);
    isEditOpen.value = true;
}

function confirmDelete(spot: EmployeeSpotlight) {
    spotToDelete.value = spot;
    deleteDialog.value = true;
}

function doDelete() {
    if (!spotToDelete.value) return;
    deleteDialog.value = false;
    store.remove(spotToDelete.value.id);
    spotToDelete.value = null;
}

let searchTimer: ReturnType<typeof setTimeout>;
function fetchList(page = 1) {
    router.get(
        route("cms.employee-team"),
        { search: search.value, page, per_page: perPage.value },
        { preserveState: true, preserveScroll: true, only: ["spotlights"] },
    );
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => fetchList(1), 350);
}

function onUpdateOptions({
    page,
    itemsPerPage,
}: {
    page: number;
    itemsPerPage: number;
}) {
    currentPage.value = page;
    perPage.value = itemsPerPage;
    fetchList(page);
}

const headers = [
    { title: t('employee_spotlight.table.employee_unit'), key: "employeeOrUnit", sortable: false },
    { title: t('employee_spotlight.table.title'), key: "title", sortable: false },
    { title: t('employee_spotlight.table.type'), key: "type", sortable: false },
    { title: t('employee_spotlight.table.month'), key: "month", sortable: false },
    { title: t('employee_spotlight.table.description'), key: "description", sortable: false },
    { title: t('employee_spotlight.table.actions'), key: "actions", sortable: false, align: "end" as const },
];
</script>

<template>
    <VContainer fluid class="pa-4 pa-sm-6">
        <div class="mb-6">
            <h3 class="text-h5 font-weight-bold mb-1">{{ t('employee_spotlight.page_title') }}</h3>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('employee_spotlight.subtitle') }}</p>
        </div>

        <VRow class="mb-4">
            <VCol v-for="s in summaryCounts" :key="s.type" cols="12" sm="4">
                <VCard elevation="0" border class="pa-4 d-flex align-center gap-4"
                    :style="{ borderLeft: `4px solid rgb(var(--v-theme-${getTypeConfig(s.type).color}))` }">
                    <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                        :style="{ width: '44px', height: '44px', background: getTypeConfig(s.type).bg }">
                        <VIcon :icon="getTypeConfig(s.type).icon" :color="getTypeConfig(s.type).color" size="22" />
                    </div>
                    <div>
                        <p class="text-h6 font-weight-bold mb-0">{{ s.count }}</p>
                        <p class="text-caption text-medium-emphasis mb-0">{{ t('employee_spotlight.summary.this_page', { label: getTypeConfig(s.type).label }) }}</p>
                    </div>
                </VCard>
            </VCol>
        </VRow>

        <VCard elevation="0" border>
            <div class="d-flex flex-wrap align-center gap-3 pa-4">
                <VTextField v-model="search" :placeholder="t('employee_spotlight.search.placeholder')"
                    prepend-inner-icon="tabler-search" variant="outlined" density="compact"
                    hide-details clearable style="max-width: 260px"
                    @input="onSearch"
                    @click:clear="() => { search = ''; onSearch(); }" />
                <VSpacer />
                <VBtn color="warning" prepend-icon="tabler-star-plus" @click="isAddOpen = true">
                    {{ t('employee_spotlight.add_button') }}
                </VBtn>
            </div>

            <VDivider />

            <VDataTableServer
                :headers="headers"
                :items="spotlights?.data ?? []"
                :items-length="spotlights?.total ?? 0"
                :items-per-page="perPage"
                :page="currentPage"
                :items-per-page-options="[10, 25, 50]"
                hover
                @update:options="onUpdateOptions"
            >
                <template #item.employeeOrUnit="{ item }: { item: EmployeeSpotlight }">
                    <div v-if="item.type === 2" class="d-flex align-center gap-3 py-2">
                        <VAvatar size="36" color="warning" variant="tonal">
                            <span class="text-caption font-weight-bold">{{ resolveUserInitial(item.employee) }}</span>
                        </VAvatar>
                        <p class="text-body-2 font-weight-semibold mb-0">{{ resolveUserName(item.employee) }}</p>
                    </div>
                    <div v-else class="py-2">
                        <span v-if="item.companyUnit" class="text-body-2 font-weight-medium">
                            <VIcon icon="tabler-building" size="14" class="me-1" color="medium-emphasis" />
                            {{ item.companyUnit.name }}
                        </span>
                        <span v-else class="text-caption text-medium-emphasis font-italic">—</span>
                    </div>
                </template>

                <template #item.title="{ item }: { item: EmployeeSpotlight }">
                    <p class="text-body-2 mb-0" style="max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        {{ item.title }}
                    </p>
                </template>

                <template #item.type="{ item }: { item: EmployeeSpotlight }">
                    <VChip size="small" :color="getTypeConfig(item.type).color" variant="tonal">
                        <VIcon :icon="getTypeConfig(item.type).icon" size="12" class="me-1" />
                        {{ getTypeConfig(item.type).label }}
                    </VChip>
                </template>

                <template #item.month="{ item }: { item: EmployeeSpotlight }">
                    <span class="text-body-2">{{ formatMonth(item.month) }}</span>
                </template>

                <template #item.description="{ item }: { item: EmployeeSpotlight }">
                    <div v-if="item.description" class="d-flex align-center gap-2">
                        <p class="text-body-2 mb-0" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            {{ truncateText(item.description, 60) }}
                        </p>
                        <VBtn icon variant="text" size="x-small" density="compact" color="medium-emphasis"
                            @click="openDescription(item.description)">
                            <VIcon icon="tabler-arrows-maximize" size="14" />
                        </VBtn>
                    </div>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                </template>

                <template #item.actions="{ item }: { item: EmployeeSpotlight }">
                    <div class="d-flex align-center justify-end gap-1">
                        <VTooltip :text="t('employee_spotlight.table.edit')" location="top">
                            <template #activator="{ props: tp }">
                                <VBtn v-bind="tp" icon variant="text" size="small" density="compact" @click="openEdit(item)">
                                    <VIcon icon="tabler-pencil" size="16" />
                                </VBtn>
                            </template>
                        </VTooltip>
                        <VTooltip :text="t('employee_spotlight.table.delete')" location="top">
                            <template #activator="{ props: tp }">
                                <VBtn v-bind="tp" icon variant="text" size="small" density="compact" color="error" @click="confirmDelete(item)">
                                    <VIcon icon="tabler-trash" size="16" />
                                </VBtn>
                            </template>
                        </VTooltip>
                    </div>
                </template>

                <template #no-data>
                    <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                        <VIcon icon="tabler-star-off" size="52" class="mb-3" style="opacity: 0.25" />
                        <p class="text-body-1 font-weight-medium mb-1">{{ t('employee_spotlight.empty.title') }}</p>
                        <p class="text-caption mb-0">{{ t('employee_spotlight.empty.description') }}</p>
                    </div>
                </template>
            </VDataTableServer>
        </VCard>

        <AddSpotlight v-model:isDrawerOpen="isAddOpen" :company-units="companyUnits" :employees="employees" />
        <EditSpotlight v-model:isDrawerOpen="isEditOpen" :company-units="companyUnits" :employees="employees" />

        <VDialog v-model="deleteDialog" max-width="420" persistent>
            <VCard elevation="0">
                <div class="px-6 py-5">
                    <div class="d-flex align-center gap-3 mb-3">
                        <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                            style="width: 44px; height: 44px; background: #fef2f2;">
                            <VIcon icon="tabler-alert-triangle" color="error" size="22" />
                        </div>
                        <div>
                            <p class="text-body-1 font-weight-semibold mb-0">{{ t('employee_spotlight.delete_dialog.title') }}</p>
                            <p class="text-caption text-medium-emphasis mb-0">{{ t('employee_spotlight.delete_dialog.subtitle') }}</p>
                        </div>
                    </div>
                    <p class="text-body-2 text-medium-emphasis mb-0">
                        {{ t('employee_spotlight.delete_dialog.message', { title: spotToDelete?.title }) }}
                    </p>
                </div>
                <div class="d-flex gap-3 px-6 pb-5">
                    <VBtn variant="tonal" class="flex-grow-1" @click="deleteDialog = false">{{ t('employee_spotlight.delete_dialog.cancel') }}</VBtn>
                    <VBtn color="error" class="flex-grow-1" @click="doDelete">
                        <VIcon icon="tabler-trash" size="16" class="me-2" />{{ t('employee_spotlight.delete_dialog.confirm') }}
                    </VBtn>
                </div>
            </VCard>
        </VDialog>

        <VDialog v-model="descriptionDialog" max-width="500">
            <VCard elevation="0">
                <div class="px-6 pt-5 pb-3">
                    <div class="d-flex align-center justify-space-between mb-4">
                        <p class="text-body-1 font-weight-semibold mb-0">{{ t('employee_spotlight.description_dialog.title') }}</p>
                        <VBtn icon="tabler-x" variant="text" size="small" density="compact" @click="descriptionDialog = false" />
                    </div>
                    <p class="text-body-2 mb-0" style="line-height: 1.6; white-space: pre-wrap;">{{ selectedDescription }}</p>
                </div>
                <div class="px-6 pb-5">
                    <VBtn variant="tonal" block @click="descriptionDialog = false">{{ t('employee_spotlight.description_dialog.close') }}</VBtn>
                </div>
            </VCard>
        </VDialog>

        <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right">
            <div class="d-flex align-center gap-2">
                <VIcon :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-alert-circle'" size="16" />
                {{ store.snackMsg }}
            </div>
        </VSnackbar>
    </VContainer>
</template>

<style scoped>
:deep(.v-data-table-server tbody tr:hover td) {
    background: rgba(var(--v-theme-warning), 0.04);
}
</style>
