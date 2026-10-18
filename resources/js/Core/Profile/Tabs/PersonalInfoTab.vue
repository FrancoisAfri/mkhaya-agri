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

const get = (val: any, fallback = "—") => val ?? fallback;

const personalInfoItems = computed<InfoRow[]>(() => [
    { icon: "fa-user-tag", label: t('profile_tabs.title'), value: get(p.value?.title) },
    { icon: "fa-user", label: t('profile_tabs.first_name'), value: get(p.value?.first_name) },
    { icon: "fa-user", label: t('profile_tabs.surname'), value: get(p.value?.surname) },
    { icon: "fa-user", label: t('profile_tabs.middle_name'), value: get(p.value?.middle_name) },
    { icon: "fa-user", label: t('profile_tabs.maiden_name'), value: get(p.value?.maiden_name) },
    { icon: "fa-id-card", label: t('profile_tabs.known_as'), value: get(p.value?.known_as) },
    { icon: "fa-font", label: t('profile_tabs.initials'), value: get(p.value?.initial) },
    { icon: "fa-text", label: t('profile_tabs.aka'), value: get(p.value?.aka) },
    { icon: "fa-venus-mars", label: t('profile_tabs.gender'), value: store.genderLabel },
    { icon: "fa-cake-candles", label: t('profile_tabs.date_of_birth'), value: store.dateOfBirth },
    { icon: "fa-id-card", label: t('profile_tabs.id_number'), value: get(p.value?.id_number) },
    { icon: "fa-passport", label: t('profile_tabs.passport_number'), value: get(p.value?.passport_number) },
    { icon: "fa-ring", label: t('profile_tabs.marital_status'), value: store.maritalStatusLabel },
    { icon: "fa-globe", label: t('profile_tabs.ethnicity'), value: store.ethnicityLabel },
    { icon: "fa-calendar", label: t('profile_tabs.date_of_birth'), value: store.dateOfBirth },
]);

const drivingItems = computed<InfoRow[]>(() => [
    { icon: "fa-car", label: t('profile_tabs.can_drive'), value: store.canDriveLabel },
    { icon: "fa-id-card", label: t('profile_tabs.drivers_licence_number'), value: get(p.value?.drivers_licence_number) },
    { icon: "fa-code", label: t('profile_tabs.drivers_licence_code'), value: get(p.value?.drivers_licence_code) },
    { icon: "fa-calendar-alt", label: t('profile_tabs.licence_expiry_date'), value: store.driversLicenceExpDateFormatted },
    { icon: "fa-car-side", label: t('profile_tabs.own_transport'), value: p.value?.own_transport === 1 ? t('profile_tabs.yes') : p.value?.own_transport === 0 ? t('profile_tabs.no') : "—" },
]);
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
                <i class="fa-solid fa-user-circle mr-2" /> {{ t('profile_tabs.personal_information') }}
            </p>
            <div class="space-y-3">
                <div v-for="item in personalInfoItems" :key="item.label" class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[130px] flex items-center gap-2">
                        <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                    </span>
                    <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">
                <i class="fa-solid fa-car mr-2" /> {{ t('profile_tabs.driving_transport') }}
            </p>
            <div class="space-y-3">
                <div v-for="item in drivingItems" :key="item.label" class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[130px] flex items-center gap-2">
                        <i :class="`fa-solid ${item.icon} text-slate-400 w-4 text-center text-sm`" />{{ item.label }}:
                    </span>
                    <span class="text-sm font-medium text-slate-700 text-right flex-1">{{ item.value }}</span>
                </div>

                <div class="flex justify-between items-start gap-3">
                    <span class="text-sm text-slate-500 min-w-[130px] flex items-center gap-2">
                        <i class="fa-solid fa-file-alt text-slate-400 w-4 text-center text-sm" />{{ t('profile_tabs.proof_drive_permit') }}:
                    </span>
                    <span class="text-sm font-medium text-right flex-1">
                        <template v-if="p?.proof_drive_permit_url">
                            <a :href="p.proof_drive_permit_url" :download="p.proof_drive_permit_original_name ?? 'permit'" class="inline-flex items-center gap-1.5 text-emerald-500 hover:text-emerald-700">
                                <i class="fa-solid fa-download text-xs" />{{ t('profile_tabs.download') }}
                            </a>
                        </template>
                        <span v-else class="text-slate-400">—</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
