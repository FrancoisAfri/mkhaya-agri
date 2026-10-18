<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import AppFileInput from '@/@core/components/app-form-elements/AppFileInput.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

type CompanyUnit = {
	id: number
	name: string
	parent_id: number | null
	type: string
}

type CompanyUnitsByType = {
	divisions?: CompanyUnit[]
	departments?: CompanyUnit[]
	sections?: CompanyUnit[]
	teams?: CompanyUnit[]
	units?: CompanyUnit[]
}

type OrgLevel = {
	selectedId: number | null
	children: CompanyUnit[]
}

const props = defineProps<{
	categories: Array<{ id: number; name: string }>
	owners: Array<{ id: number; name: string }>
	reviewers: Array<{ id: number; name: string }>
	companyUnits?: CompanyUnitsByType
}>()

const tagsInput = ref('')
const orgLevels = ref<OrgLevel[]>([])

const allOrgUnits = computed<CompanyUnit[]>(() => [
	...(props.companyUnits?.divisions ?? []),
	...(props.companyUnits?.departments ?? []),
	...(props.companyUnits?.sections ?? []),
	...(props.companyUnits?.teams ?? []),
	...(props.companyUnits?.units ?? []),
])

const orgRoots = computed(() => allOrgUnits.value.filter(unit => unit.parent_id === null))

const form = useForm({
	name: '',
	description: '',
	category_id: null as number | null,
	department_id: null as number | null,
	owner_id: null as number | null,
	reviewer_ids: [] as number[],
	effective_date: '',
	review_date: '',
	status: 'draft',
	requires_attestation: false,
	tags: [] as string[],
	document: null as File | null,
})

const statuses = ['draft', 'active', 'under_review', 'archived']

const canSubmit = computed(() => !!form.name && !!form.category_id && !!form.owner_id && !!form.effective_date)

function formatUnitTypeLabel(unitType: string) {
	if (!unitType) return t('policy_create.unit')
	return unitType.charAt(0).toUpperCase() + unitType.slice(1)
}

function levelLabel(level: OrgLevel) {
	const unitType = level.children[0]?.type ?? 'unit'
	return formatUnitTypeLabel(unitType)
}

function initOrgLevels() {
	orgLevels.value = orgRoots.value.length
		? [{ selectedId: null, children: orgRoots.value }]
		: []
}

function onOrgLevelSelect(levelIndex: number, selectedId: number | null) {
	orgLevels.value[levelIndex].selectedId = selectedId
	orgLevels.value = orgLevels.value.slice(0, levelIndex + 1)

	if (selectedId !== null) {
		const children = allOrgUnits.value.filter(unit => unit.parent_id === selectedId)
		if (children.length) {
			orgLevels.value.push({ selectedId: null, children })
		}
	}

	const chosenIds = orgLevels.value
		.map(level => level.selectedId)
		.filter((id): id is number => id !== null)

	form.department_id = chosenIds.length ? chosenIds[chosenIds.length - 1] : null
}

function onFileChanged(files: File[] | File | null) {
	if (Array.isArray(files)) {
		form.document = files[0] ?? null
		return
	}
	form.document = files
}

function parseTags() {
	form.tags = tagsInput.value
		.split(',')
		.map(tag => tag.trim())
		.filter(Boolean)
}

function submit() {
	parseTags()
	form.post(route('policies.store'), { forceFormData: true })
}

function goIndex() {
	router.visit(route('policies.index'))
}

onMounted(() => {
	initOrgLevels()
})
</script>

<template>
	<section class="bg-[#f8f8ff]">
		<VContainer fluid class="pa-4 pa-sm-6">
			<div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
				<div>
					<h3 class="text-h5 font-weight-semibold mb-1">{{ t('policy_create.page_title') }}</h3>
					<p class="text-medium-emphasis mb-0">{{ t('policy_create.page_subtitle') }}</p>
				</div>

				<VBtn variant="text" @click="goIndex">
					<i class="fa-solid fa-arrow-left me-2" /> {{ t('policy_create.back') }}
				</VBtn>
			</div>

			<VCard elevation="0" border>
				<VCardText class="pa-5">
					<VRow>
						<VCol cols="12" md="6">
							<AppTextField v-model="form.name" :label="t('policy_create.title_label')" :error-messages="form.errors.name" />
						</VCol>
						<VCol cols="12" md="6">
							<AppSelect
								v-model="form.category_id"
								:items="categories"
								item-title="name"
								item-value="id"
								:label="t('policy_create.category_label')"
								:error-messages="form.errors.category_id"
							/>
						</VCol>

						<VCol cols="12" md="6">
							<AppSelect
								v-model="form.owner_id"
								:items="owners"
								item-title="name"
								item-value="id"
								:label="t('policy_create.owner_label')"
								:error-messages="form.errors.owner_id"
							/>
						</VCol>

						<VCol cols="12" md="6">
							<AppSelect
								v-model="form.reviewer_ids"
								:items="reviewers"
								item-title="name"
								item-value="id"
								:label="t('policy_create.reviewers_label')"
								chips
								multiple
								clearable
								:error-messages="form.errors.reviewer_ids"
							/>
						</VCol>

						<VCol
							v-for="(level, levelIndex) in orgLevels"
							:key="`org-level-${levelIndex}`"
							cols="12"
							md="6"
						>
							<AppSelect
								v-model="level.selectedId"
								:items="level.children"
								item-title="name"
								item-value="id"
								:label="levelLabel(level)"
								clearable
								:error-messages="levelIndex === 0 ? form.errors.department_id : undefined"
								@update:model-value="value => onOrgLevelSelect(levelIndex, (value as number | null) ?? null)"
							/>
						</VCol>
						<VCol cols="12" md="6">
							<AppSelect v-model="form.status" :items="statuses" :label="t('policy_create.status_label')" :error-messages="form.errors.status" />
						</VCol>

						<VCol cols="12" md="6">
							<AppTextField v-model="form.effective_date" type="date" :label="t('policy_create.effective_date_label')" :error-messages="form.errors.effective_date" />
						</VCol>
						<VCol cols="12" md="6">
							<AppTextField v-model="form.review_date" type="date" :label="t('policy_create.review_date_label')" :error-messages="form.errors.review_date" />
						</VCol>

						<VCol cols="12" md="6">
							<AppTextField
								v-model="tagsInput"
								:label="t('policy_create.tags_label')"
								:hint="t('policy_create.tags_hint')"
								persistent-hint
								:error-messages="form.errors.tags"
							/>
						</VCol>

						<VCol cols="12" md="6">
							<AppFileInput
								:label="t('policy_create.document_label')"
								accept=".pdf,.doc,.docx"
								prepend-icon="tabler-file-upload"
								@update:model-value="onFileChanged"
								:error-messages="form.errors.document"
							/>
						</VCol>

						<VCol cols="12">
							<VCheckbox v-model="form.requires_attestation" :label="t('policy_create.requires_attestation_label')" />
						</VCol>

						<VCol cols="12">
							<AppTextarea v-model="form.description" :label="t('policy_create.description_label')" rows="4" :error-messages="form.errors.description" />
						</VCol>
					</VRow>

					<div class="d-flex flex-wrap gap-2 mt-4">
						<VBtn color="primary" :loading="form.processing" :disabled="!canSubmit" @click="submit">
							{{ t('policy_create.create_policy_button') }}
						</VBtn>
						<VBtn variant="text" @click="goIndex">{{ t('policy_create.cancel_button') }}</VBtn>
					</div>
				</VCardText>
			</VCard>
		</VContainer>
	</section>
</template>
