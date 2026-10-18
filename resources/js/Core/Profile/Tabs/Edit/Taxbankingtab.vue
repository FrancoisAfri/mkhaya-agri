<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps<{ form: any }>();
</script>

<template>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="section-label">{{ t('profile_edit_tabs.tax_information') }}</p>
            <div class="space-y-4">
                <VTextField v-model="form.income_tax_number" :label="t('profile_edit_tabs.income_tax_number')" variant="outlined" density="compact" />
                <VTextField v-model="form.tax_office" :label="t('profile_edit_tabs.tax_office')" variant="outlined" density="compact" />
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
            <p class="section-label">{{ t('profile_edit_tabs.banking_details') }}</p>
            <div class="grid grid-cols-1 gap-4">
                <VTextField v-model="form.account_type" :label="t('profile_edit_tabs.account_type')" variant="outlined" density="compact" />
                <VTextField v-model="form.account_holder_name" :label="t('profile_edit_tabs.account_holder_name')" variant="outlined" density="compact" />
                <VTextField v-model="form.bank_name" :label="t('profile_edit_tabs.bank_name')" variant="outlined" density="compact" />
                <VTextField v-model="form.branch_name" :label="t('profile_edit_tabs.branch_name')" variant="outlined" density="compact" />
                <VTextField v-model="form.account_number" :label="t('profile_edit_tabs.account_number')" variant="outlined" density="compact" />
            </div>
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
