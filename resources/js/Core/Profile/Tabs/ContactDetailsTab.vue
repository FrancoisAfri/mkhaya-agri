<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from 'vue-i18n'
import { useProfileStore } from "../Store/profileStore";

const { t } = useI18n()
const store = useProfileStore();
const p = computed(() => store.user?.person);

interface InfoRow { icon: string; label: string; value: string | number | null | undefined; }

const contactItems = computed<InfoRow[]>(() => [
    { icon: "fa-envelope", label: t('profile_tabs.email'), value: p.value?.email || store.user?.email || "—" },
    { icon: "fa-mobile-alt", label: t('profile_tabs.cell_number'), value: p.value?.cell_number || "—" },
    { icon: "fa-phone", label: t('profile_tabs.phone_number'), value: p.value?.phone_number || "—" },
    { icon: "fa-phone-volume", label: t('profile_tabs.alternate_number'), value: p.value?.alternate_number || "—" },
]);

const addressItems = computed<InfoRow[]>(() => [
    { icon: "fa-location-dot", label: t('profile_tabs.address'), value: p.value?.res_address || "—" },
    { icon: "fa-map-pin", label: t('profile_tabs.suburb'), value: p.value?.res_suburb || "—" },
    { icon: "fa-city", label: t('profile_tabs.city'), value: p.value?.res_city || "—" },
    { icon: "fa-mailbox", label: t('profile_tabs.postal_code'), value: p.value?.res_postal_code || "—" },
    { icon: "fa-map", label: t('profile_tabs.province'), value: p.value?.res_province_id || "—" },
    { icon: "fa-earth-americas", label: t('profile_tabs.country'), value: p.value?.res_country_id || "—" },
]);
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4"><i class="fa-solid fa-address-book mr-2" />{{ t('profile_tabs.contact_details') }}</p>
            <div class="space-y-3">
                <div v-for="item in contactItems" :key="item.label" class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[120px] flex items-center gap-2"><i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:</span>
                    <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4"><i class="fa-solid fa-home mr-2" />{{ t('profile_tabs.residential_address') }}</p>
            <div class="space-y-3">
                <div v-for="item in addressItems" :key="item.label" class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[120px] flex items-center gap-2"><i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:</span>
                    <span class="text-sm font-medium text-slate-700 text-right flex-1 break-words">{{ item.value }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
