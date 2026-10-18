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

const medicalItems = computed<InfoRow[]>(() => [
    { icon: "fa-calendar-alt", label: t('profile_tabs.start_date'), value: store.medStartDateFormatted },
    { icon: "fa-divide", label: t('profile_tabs.split'), value: p.value?.med_split || "—" },
    { icon: "fa-file-medical-alt", label: t('profile_tabs.plan_name'), value: p.value?.med_plan_name || "—" },
    { icon: "fa-user-friends", label: t('profile_tabs.dependants_spouse'), value: p.value?.med_dep_spouse || "—" },
    { icon: "fa-user-friends", label: t('profile_tabs.dependants_adult'), value: p.value?.med_dep_adult || "—" },
    { icon: "fa-children", label: t('profile_tabs.dependants_children'), value: p.value?.med_dep_kids || "—" },
    { icon: "fa-dollar-sign", label: t('profile_tabs.amount'), value: p.value?.med_amount ?? "—" },
    { icon: "fa-dollar-sign", label: t('profile_tabs.package_med_aid'), value: p.value?.package_med_aid ?? "—" },
]);
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
            <i class="fa-solid fa-notes-medical mr-2" /> {{ t('profile_tabs.medical_aid_details') }}
        </p>
        <div class="space-y-3">
            <div v-for="item in medicalItems" :key="item.label" class="flex justify-between items-start gap-3">
                <span class="text-sm text-slate-500 min-w-[170px] flex items-center gap-2">
                    <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                </span>
                <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
            </div>
        </div>
    </div>
</template>
