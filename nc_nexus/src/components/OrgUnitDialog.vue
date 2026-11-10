<template>
	<NxDialog :title="unit ? t('nc_nexus', 'Edit organizational unit') : t('nc_nexus', 'New organizational unit')"
		:subtitle="unit ? unitPath : t('nc_nexus', 'Areas such as departments or business units; roles apply to the unit and all subunits.')"
		data-test="org-unit-dialog"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<label class="nx-field" data-test="field-unit-name">
				<span>{{ t('nc_nexus', 'Name') }} *</span>
				<input v-model="form.name" class="nx-input" maxlength="255">
			</label>
			<label class="nx-field" data-test="field-unit-parent">
				<span>{{ t('nc_nexus', 'Parent unit') }}</span>
				<select v-model="form.parentId" class="nx-input">
					<option :value="null">{{ t('nc_nexus', '– top level –') }}</option>
					<option v-for="option in parentOptions" :key="option.id" :value="option.id">
						{{ indented(option) }}
					</option>
				</select>
			</label>
			<label class="nx-field">
				<span>{{ t('nc_nexus', 'Cost center') }}</span>
				<input v-model="form.costCenter" class="nx-input" maxlength="64">
			</label>
		</form>
		<template #actions>
			<NcButton v-if="unit"
				class="nx-dialog__left"
				variant="error"
				:disabled="busy"
				data-test="delete-unit"
				@click="remove">
				{{ t('nc_nexus', 'Delete') }}
			</NcButton>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ t('nc_nexus', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !form.name.trim()"
				data-test="save-unit"
				@click="save">
				{{ t('nc_nexus', 'Save') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from './NxDialog.vue'
import { createOrgUnit, deleteOrgUnit, errorMessage, updateOrgUnit } from '../api.js'
import { indented, orgTree } from '../labels.js'

export default {
	name: 'OrgUnitDialog',
	components: { NcButton, NxDialog },
	props: {
		unit: { type: Object, default: null },
		units: { type: Array, required: true },
	},
	emits: ['close', 'changed'],
	setup() {
		return { indented, t }
	},
	data() {
		return {
			busy: false,
			form: {
				name: this.unit?.name ?? '',
				parentId: this.unit?.parentId ?? null,
				costCenter: this.unit?.costCenter ?? '',
			},
		}
	},
	computed: {
		tree() {
			return orgTree(this.units)
		},
		unitPath() {
			return this.tree.find(u => u.id === this.unit?.id)?.path ?? ''
		},
		// Eine Einheit darf nicht unter sich selbst oder eine ihrer Untereinheiten
		parentOptions() {
			if (!this.unit) {
				return this.tree
			}
			const own = this.tree.find(u => u.id === this.unit.id)
			return this.tree.filter(u => u.id !== this.unit.id && !(own && u.path.startsWith(own.path + ' › ')))
		},
	},
	methods: {
		async run(action, message) {
			this.busy = true
			try {
				await action()
				showSuccess(message)
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		save() {
			if (!this.form.name.trim()) {
				return
			}
			return this.run(
				() => this.unit ? updateOrgUnit(this.unit.id, this.form) : createOrgUnit(this.form),
				t('nc_nexus', 'Organizational unit saved'),
			)
		},
		remove() {
			return this.run(() => deleteOrgUnit(this.unit.id), t('nc_nexus', 'Organizational unit deleted'))
		},
	},
}
</script>
