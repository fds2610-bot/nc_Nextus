<template>
	<NxDialog :title="right ? t('nc_nexus', 'Edit decision right') : t('nc_nexus', 'New decision right')"
		:subtitle="t('nc_nexus', 'Who decides at which gate? A right for a specific project class beats a right for all classes. Without a right, the board or the portfolio office of the area decides directly. Changes create a new version.')"
		data-test="right-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<div class="nx-field-row">
			<label class="nx-field" data-test="field-right-gate">
				<span>{{ t('nc_nexus', 'Gate') }} *</span>
				<select v-model="form.gateCode" class="nx-input">
					<option v-for="g in gates" :key="g" :value="g">{{ gateLabel(g) }}</option>
				</select>
			</label>
			<label class="nx-field" data-test="field-right-body">
				<span>{{ t('nc_nexus', 'Decided by') }} *</span>
				<select v-model="form.bodyId" class="nx-input">
					<option :value="null">{{ t('nc_nexus', 'Board or portfolio office of the area (directly)') }}</option>
					<option v-for="b in bodies" :key="b.id" :value="b.id">{{ b.name }}</option>
				</select>
			</label>
		</div>
		<fieldset class="nx-field right__classes" data-test="field-right-classes">
			<legend>{{ t('nc_nexus', 'Project classes (none = all)') }}</legend>
			<label v-for="c in classes" :key="c">
				<input v-model="form.projectClasses" type="checkbox" :value="c">
				<span>{{ c }}</span>
			</label>
		</fieldset>
		<label v-if="form.bodyId" class="nx-field right__quorum" data-test="field-right-quorum">
			<span>{{ t('nc_nexus', 'Quorum (empty = default of the body)') }}</span>
			<NxNumberInput v-model="form.quorumMin"
				:min="1"
				:max="99"
				:max-decimals="0"
				class="nx-input" />
		</label>
		<template #actions>
			<NcButton v-if="right"
				variant="error"
				:disabled="busy"
				data-test="end-right"
				@click="end">
				{{ t('nc_nexus', 'End right') }}
			</NcButton>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !dirty"
				data-test="save-right"
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
import NxNumberInput from './NxNumberInput.vue'
import { createRight, endRight, errorMessage, updateRight } from '../api.js'
import { gateLabel } from '../labels.js'

export default {
	name: 'RightDialog',
	components: { NcButton, NxDialog, NxNumberInput },
	props: {
		right: { type: Object, default: null },
		bodies: { type: Array, required: true },
	},
	emits: ['close', 'changed', 'ready'],
	setup() {
		return { t, gateLabel, gates: ['g2', 'g3', 'g4', 'g5', 'g6', 'change'], classes: ['XS', 'S', 'M', 'L'] }
	},
	data() {
		const form = {
			gateCode: this.right?.gateCode ?? 'g2',
			bodyId: this.right?.bodyId ?? (this.bodies[0]?.id ?? null),
			projectClasses: [...(this.right?.projectClasses ?? [])],
			quorumMin: this.right?.quorumMin ?? null,
		}
		return { busy: false, form, saved: this.right ? JSON.stringify(form) : '' }
	},
	computed: {
		dirty() {
			return JSON.stringify(this.form) !== this.saved
		},
	},
	methods: {
		async save() {
			this.busy = true
			try {
				const values = { ...this.form, quorumMin: this.form.bodyId ? this.form.quorumMin : null }
				await (this.right ? updateRight(this.right.id, values) : createRight(values))
				showSuccess(t('nc_nexus', 'Decision right saved'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async end() {
			this.busy = true
			try {
				await endRight(this.right.id)
				showSuccess(t('nc_nexus', 'Decision right ended'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.right__classes {
	display: flex;
	flex-wrap: wrap;
	gap: 14px;
	border: none;
	padding: 0;

	legend {
		margin-bottom: 4px;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	label {
		display: flex;
		gap: 4px;
		align-items: center;
	}
}

.right__quorum {
	max-width: 260px;
}
</style>
