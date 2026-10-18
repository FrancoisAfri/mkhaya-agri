<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from "vue";
import { useI18n } from 'vue-i18n'
import AddNewModule from "./partials/Add.vue";
import type { ModuleProperties } from "./types/type";
import { useModuleStore } from "./Store/moduleStore";
import { router } from "@inertiajs/vue3";
import EditModule from "./partials/Edit.vue";

const { t } = useI18n()

const props = defineProps<{ modules: ModuleProperties[]; categoryId: number }>();
const isEditOpen = ref(false);
const selectedModule = ref<ModuleProperties | null>(null);
const openEdit = (item: ModuleProperties) => { selectedModule.value = item; isEditOpen.value = true; };

const store = useModuleStore();
watch(() => props.modules, (val) => { store.setModules(val); }, { immediate: true });

const perPage = ref(10);
const selected = ref<number[]>([]);
const isDrawerOpen = ref(false);

onMounted(() => store.startPolling(10));
onUnmounted(() => store.stopPolling());

function goToRibbons(id: number) { router.visit(`/ribbon/${id}`); }

const headers = [
    { title: t('modules.table.name'), key: "name", sortable: true },
    { title: t('modules.table.icon'), key: "font_awesome", sortable: false },
    { title: t('modules.table.code_name'), key: "code_name", sortable: false },
    { title: t('modules.table.path'), key: "path", sortable: false },
    { title: t('modules.table.status'), key: "active", sortable: true },
    { title: t('modules.table.actions'), key: "actions", sortable: false, align: "center" as const },
];
</script>

<template>
    <section class="bg-[#f8f8ff]">
        <VContainer fluid class="pa-4 pa-sm-6">
            <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
                <div>
                    <h3 class="text-h5 font-weight-semibold mb-1">{{ t('modules.page_title') }}</h3>
                    <p class="text-medium-emphasis mb-0">{{ t('modules.subtitle') }}</p>
                </div>
                <VBreadcrumbs :items="[
                    { title: t('modules.breadcrumb.home'), href: '/', disabled: false },
                    { title: t('modules.breadcrumb.categories'), href: '/settings/category', disabled: false },
                    { title: t('modules.breadcrumb.modules'), disabled: true },
                ]" density="compact" class="pa-0">
                    <template #divider><i class="fa-solid fa-chevron-right" style="font-size: 11px" /></template>
                </VBreadcrumbs>
            </div>
            <VDivider class="mb-6" />

            <VCard elevation="0" border rounded="lg">
                <VCardText class="pa-5 pa-sm-6">
                    <div class="d-flex flex-wrap align-center gap-3 mb-5">
                        <VSpacer class="d-none d-sm-flex" />
                        <VBtn color="primary" rounded="lg" @click="isDrawerOpen = true"><i class="fa-solid fa-plus me-2" />{{ t('modules.new_module') }}</VBtn>
                    </div>

                    <VDataTable :headers="headers" :items="store.modules" :items-per-page="perPage" item-value="id" v-model="selected" show-select hover class="rounded-lg">
                        <template #item.name="{ item }"><p class="font-weight-medium text-body-2 mb-0">{{ item.name }}</p></template>
                        <template #item.font_awesome="{ item }">
                            <div class="d-flex align-center gap-2"><i :class="`fa-solid fa-${item.font_awesome}`" style="font-size:16px;width:20px;text-align:center;color:rgb(var(--v-theme-primary))" /><span class="text-caption text-medium-emphasis">{{ item.font_awesome }}</span></div>
                        </template>
                        <template #item.code_name="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.code_name }}</span></template>
                        <template #item.path="{ item }"><span class="text-caption text-medium-emphasis">{{ item.path }}</span></template>
                        <template #item.active="{ item }"><VChip :color="item.active ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">{{ item.active ? t('modules.table.active') : t('modules.table.inactive') }}</VChip></template>
                        <template #item.actions="{ item }">
                            <VMenu location="bottom end">
                                <template #activator="{ props: mp }"><VBtn v-bind="mp" variant="text" size="small" icon><i class="fa-solid fa-ellipsis-vertical" /></VBtn></template>
                                <VList density="compact" min-width="160">
                                    <VListItem @click="goToRibbons(item.id)">
                                        <template #prepend><i class="fa-solid fa-list me-2" style="font-size:13px;color:rgb(var(--v-theme-secondary))" /></template>
                                        <template #title><span style="color:rgb(var(--v-theme-secondary))">{{ t('modules.table.ribbons') }}</span></template>
                                    </VListItem>
                                    <VDivider />
                                    <VListItem @click="openEdit(item)">
                                        <template #prepend><i class="fa-solid fa-pencil me-2" style="font-size:13px;color:rgb(var(--v-theme-primary))" /></template>
                                        <template #title><span>{{ t('modules.table.edit') }}</span></template>
                                    </VListItem>
                                </VList>
                            </VMenu>
                        </template>
                        <template #no-data>
                            <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                                <i class="fa-solid fa-puzzle-piece mb-3" style="font-size:48px;opacity:.25" />
                                <p class="text-body-1 font-weight-medium mb-1">{{ t('modules.empty.title') }}</p>
                                <p class="text-caption mb-4">{{ t('modules.empty.description') }}</p>
                                <VBtn color="primary" size="small" rounded="lg" @click="isDrawerOpen = true"><i class="fa-solid fa-plus me-2" />{{ t('modules.empty.button') }}</VBtn>
                            </div>
                        </template>
                    </VDataTable>
                </VCardText>
            </VCard>

            <AddNewModule v-model:isDrawerOpen="isDrawerOpen" @userData="store.addModule" />
            <EditModule v-model:isDrawerOpen="isEditOpen" :module="selectedModule" @updated="store.updateModule" />

            <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
                <div class="d-flex align-center gap-2"><i :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-trash'" style="font-size:16px" />{{ store.snackMsg }}</div>
            </VSnackbar>
        </VContainer>
    </section>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) { background: rgba(var(--v-theme-primary), 0.04); }
</style>
