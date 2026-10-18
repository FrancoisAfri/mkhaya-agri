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

const providentItems = computed<InfoRow[]>(() => [
    { icon: "fa-calendar-alt", label: t('profile_tabs.start_date'), value: store.providentStartDateFormatted },
    { icon: "fa-file-signature", label: t('profile_tabs.fund_name'), value: p.value?.provident_name || "—" },
    { icon: "fa-box-open", label: t('profile_tabs.fund_product'), value: p.value?.provident_fund_product || "—" },
    { icon: "fa-dollar-sign", label: t('profile_tabs.amount'), value: p.value?.provident_amount ?? "—" },
]);
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
            <i class="fa-solid fa-piggy-bank mr-2" /> {{ t('profile_tabs.provident_fund_details') }}
        </p>
        <div class="space-y-3">
            <div v-for="item in providentItems" :key="item.label" class="flex justify-between items-start gap-3">
                <span class="text-sm text-slate-500 min-w-[150px] flex items-center gap-2">
                    <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                </span>
                <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
            </div>
        </div>
    </div>
</template>
