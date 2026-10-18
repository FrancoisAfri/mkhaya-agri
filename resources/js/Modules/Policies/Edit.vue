<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import AppFileInput from '@/@core/components/app-form-elements/AppFileInput.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'
import { normalizeDateForInput, normalizeTags } from '@/utils/policyFormatters'

const route = ziggyRoute
const { t } = useI18n()

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
	policy: any
	categories: Array<{ id: number; name: string }>
	owners: Array<{ id: number; name: string }>
	reviewers: Array<{ id: number; name: string }>
	companyUnits?: CompanyUnitsByType
}>()

const orgLevels = ref<OrgLevel[]>([])
const allOrgUnits = computed<CompanyUnit[]>(() => [
	...(props.companyUnits?.divisions ?? []),
	...(props.companyUnits?.departments ?? []),
	...(props.companyUnits?.sections ?? []),
	...(props.companyUnits?.teams ?? []),
	...(props.companyUnits?.units ?? []),
])
const orgUnitMap = computed(() => {
	const map = new Map<number, CompanyUnit>()
	allOrgUnits.value.forEach(unit => map.set(unit.id, unit))
	return map
})
const orgRoots = computed(() => allOrgUnits.value.filter(unit => unit.parent_id === null))

const initialTags = normalizeTags(props.policy.tags)
const tagsInput = ref(initialTags.join(', '))
const form = useForm({
	name: props.policy.name ?? '',
	description: props.policy.description ?? '',
	category_id: props.policy.category_id ?? null,
	department_id: props.policy.department_id ?? null,
	owner_id: props.policy.owner_id ?? null,
	reviewer_ids: (props.policy.reviewers ?? []).map((entry: any) => entry.user_id),
	effective_date: normalizeDateForInput(props.policy.effective_date),
	review_date: normalizeDateForInput(props.policy.review_date),
	status: props.policy.status ?? 'draft',
	requires_attestation: !!props.policy.requires_attestation,
	tags: initialTags,
	document: null as File | null,
	change_summary: '',
})

const statuses = ['draft', 'active', 'under_review', 'archived']
const canSubmit = computed(() => !!form.name && !!form.category_id && !!form.owner_id && !!form.effective_date)

function formatUnitTypeLabel(type: string) {
	if (!type) return t('policy_edit.unit')
	return type.charAt(0).toUpperCase() + type.slice(1)
}

function levelLabel(level: OrgLevel) {
	const type = level.children[0]?.type ?? 'unit'
	return formatUnitTypeLabel(type)
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

function setOrgSelection(selectedId: number | null) {
	initOrgLevels()
	if (selectedId === null || selectedId === undefined) {
		form.department_id = null
		return
	}

	const path: number[] = []
	let cursor = orgUnitMap.value.get(Number(selectedId))
	let guard = 0

	while (cursor && guard < 12) {
		path.push(cursor.id)
		if (cursor.parent_id === null) break
		cursor = orgUnitMap.value.get(cursor.parent_id)
		guard++
	}

	path.reverse()

	path.forEach((id, index) => {
		if (!orgLevels.value[index]) return
		orgLevels.value[index].selectedId = id

		const children = allOrgUnits.value.filter(unit => unit.parent_id === id)
		if (children.length) {
			orgLevels.value.push({ selectedId: null, children })
		}
	})

	form.department_id = path.length ? path[path.length - 1] : null
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
	form.patch(route('policies.update', props.policy.uuid), { forceFormData: true })
}

function goShow() {
	router.visit(route('policies.show', props.policy.uuid))
}

onMounted(() => {
	setOrgSelection(form.department_id)
})
</script>

<template>
	<section class="bg-[#f8f8ff]">
		<VContainer fluid class="pa-4 pa-sm-6">
			<div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
				<div>
					<h3 class="text-h5 font-weight-semibold mb-1">{{ t('policy_edit.title') }}</h3>
					<p class="text-medium-emphasis mb-0">{{ t('policy_edit.subtitle') }}</p>
				</div>

				<VBtn variant="text" @click="goShow">
					<i class="fa-solid fa-arrow-left me-2" /> {{ t('policy_edit.back') }}
				</VBtn>
			</div>

			<VCard elevation="0" border>
				<VCardText class="pa-5">
					<VRow>
						<VCol cols="12" md="6">
							<AppTextField v-model="form.name" :label="t('policy_edit.field_title')" :error-messages="form.errors.name" />
						</VCol>
						<VCol cols="12" md="6">
							<AppSelect
								v-model="form.category_id"
								:items="categories"
								item-title="name"
								item-value="id"
								:label="t('policy_edit.field_category')"
								:error-messages="form.errors.category_id"
							/>
						</VCol>

						<VCol cols="12" md="6">
							<AppSelect
								v-model="form.owner_id"
								:items="owners"
								item-title="name"
								item-value="id"
								:label="t('policy_edit.field_owner')"
								:error-messages="form.errors.owner_id"
							/>
						</VCol>

						<VCol cols="12" md="6">
							<AppSelect
								v-model="form.reviewer_ids"
								:items="reviewers"
								item-title="name"
								item-value="id"
								:label="t('policy_edit.field_reviewers')"
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
							<AppSelect v-model="form.status" :items="statuses" :label="t('policy_edit.field_status')" :error-messages="form.errors.status" />
						</VCol>

						<VCol cols="12" md="6">
							<AppTextField v-model="form.effective_date" type="date" :label="t('policy_edit.field_effective_date')" :error-messages="form.errors.effective_date" />
						</VCol>
						<VCol cols="12" md="6">
							<AppTextField v-model="form.review_date" type="date" :label="t('policy_edit.field_review_date')" :error-messages="form.errors.review_date" />
						</VCol>

						<VCol cols="12" md="6">
							<AppTextField
								v-model="tagsInput"
								:label="t('policy_edit.field_tags')"
								:error-messages="form.errors.tags"
							/>
						</VCol>

						<VCol cols="12" md="6">
							<AppFileInput
								:label="t('policy_edit.field_document')"
								accept=".pdf,.doc,.docx"
								prepend-icon="tabler-file-upload"
								@update:model-value="onFileChanged"
								:error-messages="form.errors.document"
							/>
						</VCol>

						<VCol cols="12">
							<AppTextarea
								v-model="form.change_summary"
								:label="t('policy_edit.field_change_summary')"
								rows="2"
								:hint="t('policy_edit.change_summary_hint')"
								persistent-hint
								:error-messages="form.errors.change_summary"
							/>
						</VCol>

						<VCol cols="12">
							<VCheckbox v-model="form.requires_attestation" :label="t('policy_edit.field_requires_attestation')" />
						</VCol>

						<VCol cols="12">
							<AppTextarea v-model="form.description" :label="t('policy_edit.field_description')" rows="4" :error-messages="form.errors.description" />
						</VCol>
					</VRow>

					<div class="d-flex flex-wrap gap-2 mt-4">
						<VBtn color="primary" :loading="form.processing" :disabled="!canSubmit" @click="submit">
							{{ t('policy_edit.save_changes') }}
						</VBtn>
						<VBtn variant="text" @click="goShow">{{ t('policy_edit.cancel') }}</VBtn>
					</div>
				</VCardText>
			</VCard>
		</VContainer>
	</section>
</template>
