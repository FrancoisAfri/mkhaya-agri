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

const taxItems = computed<InfoRow[]>(() => [
    { icon: "fa-file-invoice-dollar", label: t('profile_tabs.income_tax_number'), value: p.value?.income_tax_number || "—" },
    { icon: "fa-building-columns", label: t('profile_tabs.tax_office'), value: p.value?.tax_office || "—" },
]);

const bankingItems = computed<InfoRow[]>(() => [
    { icon: "fa-file-lines", label: t('profile_tabs.account_type'), value: p.value?.account_type || "—" },
    { icon: "fa-user-pen", label: t('profile_tabs.account_holder'), value: p.value?.account_holder_name || "—" },
    { icon: "fa-building-columns", label: t('profile_tabs.bank_name'), value: p.value?.bank_name || "—" },
    { icon: "fa-code-branch", label: t('profile_tabs.branch_name'), value: p.value?.branch_name || "—" },
    { icon: "fa-credit-card", label: t('profile_tabs.account_number'), value: p.value?.account_number || "—" },
]);
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
                <i class="fa-solid fa-file-invoice-dollar mr-2" /> {{ t('profile_tabs.tax_information') }}
            </p>
            <div class="space-y-3">
                <div v-for="item in taxItems" :key="item.label" class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[150px] flex items-center gap-2">
                        <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                    </span>
                    <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
                <i class="fa-solid fa-building-columns mr-2" /> {{ t('profile_tabs.banking_details') }}
            </p>
            <div class="space-y-3">
                <div v-for="item in bankingItems" :key="item.label" class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[150px] flex items-center gap-2">
                        <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                    </span>
                    <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
