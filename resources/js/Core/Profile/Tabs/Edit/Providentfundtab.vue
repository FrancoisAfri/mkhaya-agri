<script setup lang="ts">
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
defineProps<{ form: any }>();
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-5">
        <p class="section-label">{{ t('profile_edit_tabs.provident_fund_details') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <VTextField v-model="form.provident_start_date" :label="t('profile_edit_tabs.start_date')" type="date" variant="outlined" density="compact" />
            <VTextField v-model="form.provident_name" :label="t('profile_edit_tabs.fund_name')" variant="outlined" density="compact" />
            <VTextField v-model="form.provident_fund_product" :label="t('profile_edit_tabs.fund_product')" variant="outlined" density="compact" />
            <VTextField v-model="form.provident_amount" :label="t('profile_edit_tabs.amount')" type="number" variant="outlined" density="compact" />
        </div>
    </div>
</template>

<style scoped>
.section-label { font-size: 11px; font-weight: 700; letter-spacing: 0.6px; color: #94a3b8; text-transform: uppercase; margin: 0 0 16px; }
</style>
