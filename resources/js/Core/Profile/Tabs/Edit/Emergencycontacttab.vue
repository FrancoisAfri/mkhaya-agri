<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps<{ form: any }>();

const filterNumericOnly = (value: string): string => value.replace(/[^\d+]/g, "");
const handleNumericInput = (fieldKey: string, value: any) => { if (value) { props.form[fieldKey] = filterNumericOnly(value); } };
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.emergency_contact_information') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <VTextField v-model="form.next_of_kin" :label="t('profile_edit_tabs.name_surname')" variant="outlined" density="compact" />
            <VTextField v-model="form.next_of_kin_number" :label="t('profile_edit_tabs.phone_number')" variant="outlined" density="compact" inputmode="numeric" maxlength="20" counter @input="handleNumericInput('next_of_kin_number', $event.target.value)" />
            <VTextField v-model="form.next_of_kin_work_number" :label="t('profile_edit_tabs.work_number')" variant="outlined" density="compact" inputmode="numeric" maxlength="20" counter @input="handleNumericInput('next_of_kin_work_number', $event.target.value)" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
