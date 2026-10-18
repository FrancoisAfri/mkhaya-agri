<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps<{ form: any }>();

const yesNoOptions = [
    { text: t('profile_edit_tabs.yes'), value: 1 },
    { text: t('profile_edit_tabs.no'), value: 2 },
];
const fileRules = [(v: File | File[] | null) => { if (!v) return true; const file = Array.isArray(v) ? v[0] : v; if (!file) return true; const maxSize = 50 * 1024 * 1024; return file.size <= maxSize || t('profile_edit_tabs.file_max_50mb'); }];
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.driving_transport') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <VTextField v-model="form.drivers_licence_number" :label="t('profile_edit_tabs.drivers_licence_number')" variant="outlined" density="compact" />
            <VTextField v-model="form.drivers_licence_code" :label="t('profile_edit_tabs.licence_code')" variant="outlined" density="compact" />
            <VTextField v-model="form.drivers_licence_exp_date" :label="t('profile_edit_tabs.licence_expiry_date')" type="date" variant="outlined" density="compact" />
            <VFileInput v-model="form.proof_drive_permit" :label="t('profile_edit_tabs.proof_of_licence')" variant="outlined" density="compact" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt" prepend-icon="tabler-paperclip" show-size :rules="fileRules" />
            <VSelect v-model="form.own_transport" :items="yesNoOptions" item-title="text" item-value="value" :label="t('profile_edit_tabs.own_transport')" variant="outlined" density="compact" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
