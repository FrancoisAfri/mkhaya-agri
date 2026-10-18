<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { router } from "@inertiajs/vue3";
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
    profileAlert: { show: boolean; message: string; completion: number } | null;
    approvalAlert: { show: boolean; message: string; pendingCount: number } | null;
}>();

const showModal = ref(false);
const activeTab = ref(0);

const tabs = computed(() => {
    const list = [];
    if (props.profileAlert?.show) { list.push({ key: 0, label: t('home_page.notifications.profile_tab') }); }
    if (props.approvalAlert?.show && props.approvalAlert.pendingCount > 0) { list.push({ key: 1, label: t('home_page.notifications.approvals_tab') }); }
    return list;
});

onMounted(() => {
    if (props.approvalAlert?.show && props.approvalAlert.pendingCount > 0) { activeTab.value = 1; showModal.value = true; }
    else if (props.profileAlert?.show) { activeTab.value = 0; showModal.value = true; }
});

const closeModal = () => { showModal.value = false; };
const goToProfile = () => { showModal.value = false; router.visit("/profile"); };
const goToApprovals = () => { showModal.value = false; router.visit("/settings/profile-updates-approval"); };
</script>

<template>
    <VDialog v-model="showModal" max-width="550" persistent>
        <VCard elevation="0" border>
            <div class="d-flex align-center justify-space-between px-6 py-4 border-b">
                <div class="d-flex align-center gap-3">
                    <div class="icon-box"><i class="fa-solid fa-layer-group"></i></div>
                    <div><p class="text-h6 font-weight-bold mb-0">{{ t('home_page.notifications.title') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('home_page.notifications.actions_required') }}</p></div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" @click="closeModal" />
            </div>

            <VTabs v-if="tabs.length > 1" v-model="activeTab" grow>
                <VTab v-for="tab in tabs" :key="tab.key" :value="tab.key">
                    <i v-if="tab.key === 0" class="fa-solid fa-user me-2"></i>
                    <i v-if="tab.key === 1" class="fa-solid fa-clipboard-check me-2"></i>
                    {{ tab.label }}
                </VTab>
            </VTabs>
            <VDivider v-if="tabs.length > 1" />

            <VWindow v-model="activeTab">
                <VWindowItem v-if="props.profileAlert?.show" :value="0">
                    <VCardText class="pa-6">
                        <div class="progress-box mb-4">
                            <div class="d-flex justify-space-between mb-2"><span>{{ t('home_page.notifications.profile_completion') }}</span><span>{{ profileAlert.completion }}%</span></div>
                            <VProgressLinear :model-value="profileAlert.completion" height="8" rounded color="primary" />
                        </div>
                        <p class="mb-3">{{ profileAlert.message }}</p>
                        <div class="info-box">{{ t('home_page.notifications.profile_message_default') }}</div>
                    </VCardText>
                    <VDivider />
                    <div class="d-flex gap-3 pa-4">
                        <VBtn variant="outlined" @click="closeModal">{{ t('home_page.notifications.later') }}</VBtn>
                        <VBtn color="primary" class="flex-grow-1" @click="goToProfile">{{ t('home_page.notifications.complete_now') }}</VBtn>
                    </div>
                </VWindowItem>

                <VWindowItem v-if="props.approvalAlert?.show && props.approvalAlert.pendingCount > 0" :value="1">
                    <VCardText class="pa-6">
                        <div class="alert-box mb-4">
                            <div class="d-flex align-start gap-3">
                                <div class="bell-icon"><i class="fa-solid fa-bell text-white" /></div>
                                <div><p class="mb-2">{{ approvalAlert.message }}</p><VChip color="warning" size="small">{{ t('home_page.notifications.pending_count', { count: approvalAlert.pendingCount }) }}</VChip></div>
                            </div>
                        </div>
                        <div class="info-box">{{ t('home_page.notifications.review_requests') }}</div>
                    </VCardText>
                    <VDivider />
                    <div class="d-flex gap-3 pa-4">
                        <VBtn variant="outlined" @click="closeModal">{{ t('home_page.notifications.later') }}</VBtn>
                        <VBtn color="primary" class="flex-grow-1" @click="goToApprovals">{{ t('home_page.notifications.view_requests') }}</VBtn>
                    </div>
                </VWindowItem>
            </VWindow>
        </VCard>
    </VDialog>
</template>

<style scoped>
.icon-box { width: 42px; height: 42px; background: #eef2ff; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #4f46e5; }
.progress-box { background: #f9fafb; border: 1px solid #e5e7eb; padding: 12px; border-radius: 10px; }
.alert-box { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 12px; padding: 16px; }
.bell-icon { width: 36px; height: 36px; background: #f97316; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.info-box { background: rgba(var(--v-theme-primary), 0.05); border: 1px solid rgba(var(--v-theme-primary), 0.15); padding: 12px; border-radius: 10px; font-size: 13px; }
</style>
