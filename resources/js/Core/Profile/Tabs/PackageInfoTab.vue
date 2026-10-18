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

const packageItems = computed<InfoRow[]>(() => [
    { icon: "fa-cubes", label: t('profile_tabs.package_components'), value: p.value?.package_components || "—" },
    { icon: "fa-dollar-sign", label: t('profile_tabs.monthly_package'), value: p.value?.monthly_package ?? "—" },
    { icon: "fa-money-bill", label: t('profile_tabs.package_cash'), value: p.value?.package_cash ?? "—" },
    { icon: "fa-plane", label: t('profile_tabs.package_travel'), value: p.value?.package_travel ?? "—" },
    { icon: "fa-gift", label: t('profile_tabs.package_incentive'), value: p.value?.package_incentive ?? "—" },
    { icon: "fa-mobile-alt", label: t('profile_tabs.package_cell_phone'), value: p.value?.package_cell_phone ?? "—" },
    { icon: "fa-notes-medical", label: t('profile_tabs.package_med_aid'), value: p.value?.package_med_aid ?? "—" },
]);
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
            <i class="fa-solid fa-box mr-2" /> {{ t('profile_tabs.package_information') }}
        </p>
        <div class="space-y-3">
            <div v-for="item in packageItems" :key="item.label" class="flex justify-between items-start gap-3">
                <span class="text-sm text-slate-500 min-w-[170px] flex items-center gap-2">
                    <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                </span>
                <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
            </div>
        </div>
    </div>
</template>
