<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from 'vue-i18n'
import { useProfileStore } from "../Store/profileStore";

const { t } = useI18n()
const store = useProfileStore();
const p = computed(() => store.user?.person);

interface InfoRow {
    icon: string;
    label: string;
    value: string | number | null | undefined;
}

const emergencyItems = computed<InfoRow[]>(() => [
    { icon: "fa-user-shield", label: t('profile_tabs.name_surname'), value: p.value?.next_of_kin || "—" },
    { icon: "fa-mobile-alt", label: t('profile_tabs.cell_number'), value: p.value?.next_of_kin_number || "—" },
    { icon: "fa-phone", label: t('profile_tabs.work_number'), value: p.value?.next_of_kin_work_number || "—" },
]);
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
            <i class="fa-solid fa-people-arrows mr-2" /> {{ t('profile_tabs.emergency_contact_information') }}
        </p>
        <div class="space-y-3">
            <div v-for="item in emergencyItems" :key="item.label" class="flex justify-between items-start gap-3">
                <span class="text-sm text-slate-500 min-w-[150px] flex items-center gap-2">
                    <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                </span>
                <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
            </div>
        </div>
    </div>
</template>
