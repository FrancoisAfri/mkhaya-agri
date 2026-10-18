<script setup lang="ts">
import { ref, watch } from "vue";
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Props { form: any; }
const props = defineProps<Props>();

const password = ref(""); const confirmPassword = ref("");
const showPassword = ref(false); const showConfirm = ref(false);
const passwordError = ref("");

watch([password, confirmPassword], ([pwd, confirm]) => {
    passwordError.value = "";
    if (!pwd) { props.form.password = ""; return; }
    if (pwd.length < 8) { passwordError.value = t('profile_edit_tabs.password_min_length'); props.form.password = ""; return; }
    if (pwd !== confirm) { passwordError.value = t('profile_edit_tabs.passwords_do_not_match'); props.form.password = ""; return; }
    props.form.password = pwd;
});

const clearPassword = () => { password.value = ""; confirmPassword.value = ""; props.form.password = ""; passwordError.value = ""; };
</script>

<template>
    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-6 space-y-6">
        <div><p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-4">{{ t('profile_edit_tabs.security') }}</p></div>
        <div class="border border-slate-100 rounded-xl p-5 space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-slate-700 flex items-center gap-2"><i class="fa-solid fa-lock text-slate-400" />{{ t('profile_edit_tabs.change_password') }}</h3>
                <p class="text-xs text-slate-400 mt-1">{{ t('profile_edit_tabs.leave_blank') }}</p>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">{{ t('profile_edit_tabs.new_password') }}</label>
                <div class="relative">
                    <input v-model="password" :type="showPassword ? 'text' : 'password'" :placeholder="t('profile_edit_tabs.enter_new_password')" class="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" @input="passwordError = ''" />
                    <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors" @click="showPassword = !showPassword"><i :class="`fa-solid ${showPassword ? 'fa-eye-slash' : 'fa-eye'} text-xs`" /></button>
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">{{ t('profile_edit_tabs.confirm_password') }}</label>
                <div class="relative">
                    <input v-model="confirmPassword" :type="showConfirm ? 'text' : 'password'" :placeholder="t('profile_edit_tabs.confirm_new_password')" class="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500" @input="passwordError = ''" />
                    <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors" @click="showConfirm = !showConfirm"><i :class="`fa-solid ${showConfirm ? 'fa-eye-slash' : 'fa-eye'} text-xs`" /></button>
                </div>
            </div>
            <div v-if="passwordError" class="flex items-start gap-2 px-4 py-3 bg-red-50 border border-red-200 rounded-lg"><i class="fa-solid fa-circle-exclamation text-red-500 text-sm mt-0.5 flex-shrink-0" /><p class="text-xs text-red-600">{{ passwordError }}</p></div>
            <div v-if="password" class="text-xs text-slate-500 px-4 py-3 bg-slate-50 rounded-lg">
                <p class="font-semibold mb-1.5">{{ t('profile_edit_tabs.password_requirements') }}</p>
                <ul class="space-y-1">
                    <li :class="password.length >= 8 ? 'text-emerald-600' : 'text-slate-400'"><i :class="`fa-solid ${password.length >= 8 ? 'fa-check' : 'fa-times'} me-1.5`" />{{ t('profile_edit_tabs.at_least_8') }}</li>
                    <li :class="password === confirmPassword && confirmPassword ? 'text-emerald-600' : 'text-slate-400'"><i :class="`fa-solid ${password === confirmPassword && confirmPassword ? 'fa-check' : 'fa-times'} me-1.5`" />{{ t('profile_edit_tabs.passwords_match') }}</li>
                </ul>
            </div>
            <button v-if="password" type="button" class="w-full px-4 py-2 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors" @click="clearPassword">{{ t('profile_edit_tabs.clear_password') }}</button>
        </div>
        <div class="flex items-start gap-3 px-5 py-4 bg-blue-50 border border-blue-200 rounded-xl"><i class="fa-solid fa-circle-info text-blue-500 text-sm mt-0.5 flex-shrink-0" /><p class="text-xs text-blue-600">{{ t('profile_edit_tabs.password_info') }}</p></div>
    </div>
</template>

<style scoped>input { transition: all 0.2s ease; }</style>
