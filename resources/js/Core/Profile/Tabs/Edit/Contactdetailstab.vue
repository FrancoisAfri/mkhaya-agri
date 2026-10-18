<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
const props = defineProps<{ form: any }>();

const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const validateEmail = (email: string): true | string => { if (!email) return true; return emailRegex.test(email) || t('profile_edit_tabs.email_valid'); };
const filterNumericOnly = (value: string): string => value.replace(/[^\d+]/g, "");
const handleNumericInput = (fieldKey: string, value: any) => { if (value) { props.form[fieldKey] = filterNumericOnly(value); } };
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.contact_details') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <VTextField v-model="form.email_person" :label="t('profile_edit_tabs.email')" variant="outlined" density="compact" :rules="[validateEmail]" type="email" maxlength="255" counter />
            <VTextField v-model="form.cell_number" :label="t('profile_edit_tabs.cell_number')" variant="outlined" density="compact" inputmode="numeric" maxlength="20" counter @input="handleNumericInput('cell_number', $event.target.value)" />
            <VTextField v-model="form.phone_number" :label="t('profile_edit_tabs.phone_number')" variant="outlined" density="compact" inputmode="numeric" maxlength="20" counter @input="handleNumericInput('phone_number', $event.target.value)" />
            <VTextField v-model="form.alternate_number" :label="t('profile_edit_tabs.alternate_number')" variant="outlined" density="compact" inputmode="numeric" maxlength="20" counter @input="handleNumericInput('alternate_number', $event.target.value)" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
