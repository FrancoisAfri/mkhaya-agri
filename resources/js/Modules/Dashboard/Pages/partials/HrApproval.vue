<script setup lang="ts">
import { ref, onMounted } from "vue";
import { router } from "@inertiajs/vue3";
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
    approvalAlert: { show: boolean; message: string; pendingCount: number } | null;
}>();

const showModal = ref(false);

const closeModal = () => { showModal.value = false; };
const goToApprovals = () => { showModal.value = false; router.visit("/settings/profile-updates-approval"); };

onMounted(() => {
    if (props.approvalAlert?.show && props.approvalAlert.pendingCount > 0) { setTimeout(() => { showModal.value = true; }, 300); }
});
</script>

<template>
    <VDialog v-model="showModal" max-width="520" persistent>
        <VCard elevation="0" border>
            <div class="d-flex align-center justify-space-between px-6 py-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity));">
                <div class="d-flex align-center gap-3">
                    <div class="d-flex align-center justify-center rounded-lg" style="width:44px;height:44px;background:#fff7ed">
                        <i class="fa-solid fa-clipboard-list" style="color:#f97316;font-size:20px" />
                    </div>
                    <div>
                        <p class="text-h6 font-weight-bold mb-0">{{ t('home_page.notifications.approvals_pending') }}</p>
                        <p class="text-caption text-medium-emphasis mb-0">{{ t('home_page.notifications.action_required') }}</p>
                    </div>
                </div>
                <VBtn icon="tabler-x" variant="text" size="small" @click="closeModal" />
            </div>

            <VCardText class="pa-6">
                <div class="alert-box mb-4">
                    <div class="d-flex align-start gap-3">
                        <div class="bell-icon"><i class="fa-solid fa-bell" style="color:white;font-size:16px" /></div>
                        <div>
                            <p class="text-body-2 font-weight-medium mb-2" style="color:#9a3412">{{ approvalAlert?.message }}</p>
                            <VChip color="warning" variant="flat" size="small"><i class="fa-solid fa-clock me-1" />{{ t('home_page.notifications.pending_count', { count: approvalAlert?.pendingCount }) }}</VChip>
                        </div>
                    </div>
                </div>
                <div class="info-box">
                    <div class="d-flex align-start gap-3">
                        <i class="fa-solid fa-circle-info text-primary mt-1" style="font-size:18px" />
                        <div>
                            <p class="text-body-2 font-weight-medium mb-1">{{ t('home_page.notifications.why_notification') }}</p>
                            <p class="text-caption text-medium-emphasis mb-0">{{ t('home_page.notifications.why_approval') }}</p>
                        </div>
                    </div>
                </div>
            </VCardText>

            <VDivider />
            <div class="d-flex gap-3 pa-4">
                <VBtn variant="outlined" color="grey-darken-1" @click="closeModal"><i class="fa-solid fa-clock me-2" />{{ t('home_page.notifications.later') }}</VBtn>
                <VBtn color="primary" class="flex-grow-1" size="large" @click="goToApprovals"><i class="fa-solid fa-check-circle me-2" />{{ t('home_page.notifications.view_requests') }}</VBtn>
            </div>
        </VCard>
    </VDialog>
</template>

<style scoped>
.alert-box { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 12px; padding: 16px; }
.bell-icon { width: 36px; height: 36px; min-width: 36px; background: #f97316; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.info-box { background: rgba(var(--v-theme-primary), 0.04); border: 1px solid rgba(var(--v-theme-primary), 0.12); border-radius: 12px; padding: 16px; }
</style>
