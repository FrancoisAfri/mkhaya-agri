<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from "vue";
import { useI18n } from 'vue-i18n'
import AddNewRibbon from "./partials/Add.vue";
import EditRibbon from "./partials/Edit.vue";
import type { RibbonProperties } from "./types/type";
import { useRibbonStore } from "./Store/ribbonStore";

const { t } = useI18n()

const props = defineProps<{
    ribbons: RibbonProperties[] | null;
    moduleId: number;
}>();

const store = useRibbonStore();

watch(() => props.ribbons, (val) => { store.setRibbons(val); }, { immediate: true });

const perPage = ref(10);
const selected = ref<number[]>([]);
const isDrawerOpen = ref(false);
const isEditOpen = ref(false);
const selectedRibbon = ref<RibbonProperties | null>(null);

onMounted(() => store.startPolling(10));
onUnmounted(() => store.stopPolling());

const openEdit = (item: RibbonProperties) => { selectedRibbon.value = item; isEditOpen.value = true; };

const headers = [
    { title: t('ribbons.table.name'), key: "ribbon_name", sortable: true },
    { title: t('ribbons.table.icon'), key: "font_awesome", sortable: false },
    { title: t('ribbons.table.path'), key: "ribbon_path", sortable: false },
    { title: t('ribbons.table.sort_order'), key: "sort_order", sortable: true },
    { title: t('ribbons.table.access_level'), key: "access_level", sortable: true },
    { title: t('ribbons.table.status'), key: "active", sortable: true },
    { title: t('ribbons.table.actions'), key: "actions", sortable: false, align: "center" as const },
];

const goBack = () => window.history.back();
</script>

<template>
    <section class="bg-[#f8f8ff]">
        <VContainer fluid class="pa-4 pa-sm-6">
            <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
                <div>
                    <h3 class="text-h5 font-weight-semibold mb-1">{{ t('ribbons.page_title') }}</h3>
                    <p class="text-medium-emphasis mb-0">{{ t('ribbons.subtitle') }}</p>
                </div>
                <VBreadcrumbs
                    :items="[
                        { title: t('ribbons.breadcrumb.home'), href: '/', disabled: false },
                        { title: t('ribbons.breadcrumb.categories'), href: '/settings/category', disabled: false },
                        { title: t('ribbons.breadcrumb.modules'), disabled: false, onClick: goBack },
                        { title: t('ribbons.breadcrumb.ribbons'), disabled: true },
                    ]" density="compact" class="pa-0">
                    <template #divider><i class="fa-solid fa-chevron-right" style="font-size: 11px" /></template>
                </VBreadcrumbs>
            </div>

            <VDivider class="mb-6" />

            <VCard elevation="0" border rounded="lg">
                <VCardText class="pa-5 pa-sm-6">
                    <div class="d-flex flex-wrap align-center gap-3 mb-5">
                        <VSpacer class="d-none d-sm-flex" />
                        <VBtn color="primary" rounded="lg" @click="isDrawerOpen = true">
                            <i class="fa-solid fa-plus me-2" /> {{ t('ribbons.new_ribbon') }}
                        </VBtn>
                    </div>

                    <VDataTable :headers="headers" :items="store.ribbons" :items-per-page="perPage" item-value="id" v-model="selected" show-select hover class="rounded-lg">

                        <template #item.ribbon_name="{ item }">
                            <p class="font-weight-medium text-body-2 mb-0">{{ item.ribbon_name }}</p>
                        </template>

                        <template #item.font_awesome="{ item }">
                            <div class="d-flex align-center gap-2">
                                <i :class="`fa-solid fa-${item.font_awesome}`" style="font-size: 16px; width: 20px; text-align: center; color: rgb(var(--v-theme-primary));" />
                                <span class="text-caption text-medium-emphasis">{{ item.font_awesome }}</span>
                            </div>
                        </template>

                        <template #item.ribbon_path="{ item }">
                            <span class="text-caption text-medium-emphasis">{{ item.ribbon_path }}</span>
                        </template>

                        <template #item.sort_order="{ item }">
                            <VChip size="small" variant="tonal" color="secondary" label>{{ item.sort_order }}</VChip>
                        </template>

                        <template #item.access_level="{ item }">
                            <VChip size="small" variant="tonal" color="info" label>{{ t(`ribbons.access_levels.${item.access_level}`) }}</VChip>
                        </template>

                        <template #item.active="{ item }">
                            <VChip :color="item.active ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">
                                {{ item.active ? t('ribbons.table.active') : t('ribbons.table.inactive') }}
                            </VChip>
                        </template>

                        <template #item.actions="{ item }">
                            <VMenu location="bottom end">
                                <template #activator="{ props: mp }">
                                    <VBtn v-bind="mp" variant="text" size="small" icon><i class="fa-solid fa-ellipsis-vertical" /></VBtn>
                                </template>
                                <VList density="compact" min-width="150">
                                    <VListItem @click="openEdit(item)">
                                        <template #prepend><i class="fa-solid fa-pencil me-2" style="font-size: 13px; color: rgb(var(--v-theme-primary));" /></template>
                                        <template #title><span>{{ t('ribbons.table.edit') }}</span></template>
                                    </VListItem>
                                    <VDivider />
                                    <VListItem @click="store.deleteRibbon(item.id, item.ribbon_name)">
                                        <template #prepend><i class="fa-solid fa-trash me-2" style="font-size: 13px; color: rgb(var(--v-theme-error));" /></template>
                                        <template #title><span style="color: rgb(var(--v-theme-error));">{{ t('ribbons.table.delete') }}</span></template>
                                    </VListItem>
                                </VList>
                            </VMenu>
                        </template>

                        <template #no-data>
                            <div class="d-flex flex-column align-center justify-center py-12 text-medium-emphasis">
                                <i class="fa-solid fa-list mb-3" style="font-size: 48px; opacity: 0.25" />
                                <p class="text-body-1 font-weight-medium mb-1">{{ t('ribbons.empty.title') }}</p>
                                <p class="text-caption mb-4">{{ t('ribbons.empty.description') }}</p>
                                <VBtn color="primary" size="small" rounded="lg" @click="isDrawerOpen = true">
                                    <i class="fa-solid fa-plus me-2" /> {{ t('ribbons.empty.button') }}
                                </VBtn>
                            </div>
                        </template>
                    </VDataTable>
                </VCardText>
            </VCard>

            <AddNewRibbon v-model:isDrawerOpen="isDrawerOpen" @userData="store.addRibbon" />
            <EditRibbon v-model:isDrawerOpen="isEditOpen" :ribbon="selectedRibbon" @updated="store.updateRibbon" />

            <VSnackbar v-model="store.snackbar" :timeout="3500" :color="store.snackColor" location="bottom right" rounded="lg">
                <div class="d-flex align-center gap-2">
                    <i :class="store.snackColor === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-trash'" style="font-size: 16px" />
                    {{ store.snackMsg }}
                </div>
            </VSnackbar>
        </VContainer>
    </section>
</template>

<style scoped>
:deep(.v-data-table tbody tr:hover td) { background: rgba(var(--v-theme-primary), 0.04); }
</style>
