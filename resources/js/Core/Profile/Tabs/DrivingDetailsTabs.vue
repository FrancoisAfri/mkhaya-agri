<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useProfileStore } from "../Store/profileStore";

const { t } = useI18n()
const store = useProfileStore();

const CAN_DRIVE_LABELS: Record<string | number, string> = {
    1: t('profile_tabs.yes'),
    2: t('profile_tabs.no'),
};

const formatDate = (val: string | null | undefined): string => {
    if (!val) return "—";
    try {
        return new Date(val).toLocaleDateString("en-US", { day: "numeric", month: "long", year: "numeric" });
    } catch { return "—"; }
};

const p = store.user?.person;
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">{{ t('profile_tabs.driving_transport') }}</p>
            <div class="space-y-3">
                <div v-for="item in [
                    { icon: 'fa-car', label: t('profile_tabs.eligible_to_drive'), value: p?.can_drive ? (CAN_DRIVE_LABELS[p.can_drive] ?? '—') : '—' },
                    { icon: 'fa-id-card', label: t('profile_tabs.licence_number'), value: p?.drivers_licence_number || '—' },
                    { icon: 'fa-list-ol', label: t('profile_tabs.licence_code'), value: p?.drivers_licence_code || '—' },
                    { icon: 'fa-calendar-xmark', label: t('profile_tabs.licence_expiry'), value: formatDate(p?.drivers_licence_exp_date) },
                ]" :key="item.label" class="flex items-center gap-3">
                    <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />
                    <span class="text-sm text-slate-500 min-w-[140px]">{{ item.label }}:</span>
                    <span class="text-sm font-medium text-slate-700">{{ item.value }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">{{ t('profile_tabs.transport_permit') }}</p>
            <div class="space-y-3">
                <div v-for="item in [
                    { icon: 'fa-truck', label: t('profile_tabs.own_transport'), value: p?.own_transport ? (CAN_DRIVE_LABELS[p.own_transport] ?? '—') : '—' },
                ]" :key="item.label" class="flex items-center gap-3">
                    <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />
                    <span class="text-sm text-slate-500 min-w-[140px]">{{ item.label }}:</span>
                    <span class="text-sm font-medium text-slate-700">{{ item.value }}</span>
                </div>

                <div class="flex items-center gap-3">
                    <i class="fa-solid fa-paperclip text-slate-400 w-4 text-center text-sm" />
                    <span class="text-sm text-slate-500 min-w-[140px]">{{ t('profile_tabs.drive_permit') }}:</span>
                    <a v-if="p?.proof_drive_permit" :href="p.proof_drive_permit_url" :download="p.proof_drive_permit_original_name ?? 'permit'" target="_blank"
                        class="text-sm font-medium text-indigo-500 hover:text-indigo-700 flex items-center gap-1.5 transition-colors">
                        <i class="fa-solid fa-file-arrow-down text-xs" />{{ t('profile_tabs.view_document') }}
                    </a>
                    <span v-else class="text-sm font-medium text-slate-700">—</span>
                </div>
            </div>
        </div>
    </div>
</template>
