<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

const form = useForm({
	name: '',
	description: '',
	status: true,
})

function submit() {
	form.post(route('policy-categories.store'))
}

function goIndex() {
	router.visit(route('policy-categories.index'))
}
</script>

<template>
	<section class="bg-[#f8f8ff]">
		<VContainer fluid class="pa-4 pa-sm-6">
			<div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
				<div>
					<h3 class="text-h5 font-weight-semibold mb-1">{{ t('policy_categories_create.page_title') }}</h3>
					<p class="text-medium-emphasis mb-0">{{ t('policy_categories_create.page_subtitle') }}</p>
				</div>

				<VBtn variant="text" @click="goIndex">
					<i class="fa-solid fa-arrow-left me-2" /> {{ t('policy_categories_create.back') }}
				</VBtn>
			</div>

			<VCard elevation="0" border>
				<VCardText class="pa-5">
					<VRow>
						<VCol cols="12" md="6">
							  <AppTextField v-model="form.name" :label="t('policy_categories_create.name')" :error-messages="form.errors.name" />
						</VCol>

						<VCol cols="12" md="6" class="d-flex align-center">
							<VSwitch v-model="form.status" :label="t('policy_categories_create.active')" color="success" hide-details />
						</VCol>

						<VCol cols="12">
							<AppTextarea v-model="form.description" :label="t('policy_categories_create.description')" rows="4" :error-messages="form.errors.description" />
						</VCol>
					</VRow>

					<div class="d-flex gap-2 mt-4">
						<VBtn color="primary" :loading="form.processing" @click="submit">{{ t('policy_categories_create.create') }}</VBtn>
						<VBtn variant="text" @click="goIndex">{{ t('policy_categories_create.cancel') }}</VBtn>
					</div>
				</VCardText>
			</VCard>
		</VContainer>
	</section>
</template>
