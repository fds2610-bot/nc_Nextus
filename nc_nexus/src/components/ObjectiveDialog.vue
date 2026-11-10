<template>
	<NxDialog :title="objective ? t('nc_nexus', 'Edit objective') : t('nc_nexus', 'New strategic objective')"
		:subtitle="objective ? t('nc_nexus', 'Version {v} – every change creates a new version.', { v: objective.version }) : t('nc_nexus', 'Demands are assigned to objectives; the coverage shows which objectives are backed by projects.')"
		data-test="objective-dialog"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<div class="nx-field-row">
				<label class="nx-field" data-test="field-objective-code">
					<span>{{ t('nc_nexus', 'Code') }} *</span>
					<input v-model="form.code"
						class="nx-input"
						maxlength="32"
						:placeholder="t('nc_nexus', 'e.g. S1.2')">
				</label>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Parent objective') }}</span>
					<select v-model="form.parentId" class="nx-input">
						<option :value="null">{{ t('nc_nexus', '– top level –') }}</option>
						<option v-for="o in parentOptions" :key="o.id" :value="o.id">{{ indented({ ...o, name: o.code + ' ' + o.title }) }}</option>
					</select>
				</label>
			</div>
			<label class="nx-field" data-test="field-objective-title">
				<span>{{ t('nc_nexus', 'Title') }} *</span>
				<input v-model="form.title" class="nx-input" maxlength="255">
			</label>
			<label class="nx-field">
				<span>{{ t('nc_nexus', 'Description') }}</span>
				<textarea v-model="form.description" class="nx-input" />
			</label>
			<div class="nx-field-row">
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Target metric') }}</span>
					<input v-model="form.targetMetric"
						class="nx-input"
						maxlength="255"
						:placeholder="t('nc_nexus', 'e.g. processing time < 2 days')">
				</label>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Owner (account)') }}</span>
					<input v-model="form.ownerUserId" class="nx-input" :placeholder="t('nc_nexus', 'Account ID')">
				</label>
			</div>
			<div class="nx-field-row">
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Valid from') }}</span>
					<input v-model="form.validFrom" type="date" class="nx-input">
				</label>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Valid until') }}</span>
					<input v-model="form.validTo" type="date" class="nx-input">
				</label>
			</div>
		</form>
		<template #actions>
			<NcButton v-if="objective"
				class="nx-dialog__left"
				variant="error"
				:disabled="busy"
				data-test="delete-objective"
				@click="remove">
				{{ t('nc_nexus', 'Delete') }}
			</NcButton>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !dirty || !form.code.trim() || !form.title.trim()"
				data-test="save-objective"
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
import { createObjective, deleteObjective, errorMessage, updateObjective } from '../api.js'
import { indented, objectiveTree } from '../labels.js'

export default {
	name: 'ObjectiveDialog',
	components: { NcButton, NxDialog },
	props: {
		objective: { type: Object, default: null },
		objectives: { type: Array, required: true },
	},
	emits: ['close', 'changed'],
	setup() {
		return { t, indented }
	},
	data() {
		const o = this.objective
		const form = {
			code: o?.code ?? '',
			title: o?.title ?? '',
			description: o?.description ?? '',
			parentId: o?.parentId ?? null,
			ownerUserId: o?.ownerUserId ?? '',
			targetMetric: o?.targetMetric ?? '',
			validFrom: o?.validFrom ?? '',
			validTo: o?.validTo ?? '',
		}
		return { busy: false, form, initial: JSON.stringify(form) }
	},
	computed: {
		dirty() {
			return JSON.stringify(this.form) !== this.initial
		},
		// ein Ziel darf nicht unter sich selbst oder eines seiner Unterziele
		parentOptions() {
			const tree = objectiveTree(this.objectives)
			if (!this.objective) {
				return tree
			}
			const own = tree.find(o => o.id === this.objective.id)
			return tree.filter(o => o.id !== this.objective.id && !(own && o.path.startsWith(own.path + ' › ')))
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
			return this.run(
				() => this.objective ? updateObjective(this.objective.id, this.form) : createObjective(this.form),
				t('nc_nexus', 'Objective saved'),
			)
		},
		remove() {
			return this.run(() => deleteObjective(this.objective.id), t('nc_nexus', 'Objective deleted'))
		},
	},
}
</script>
