<template>
	<NxDialog :title="set ? set.name : t('nc_nexus', 'New gate set')"
		:subtitle="t('nc_nexus', 'A gate set bundles the gates for a project class (and optionally demand types) with their required checks. Saving creates a new version; projects keep the history.')"
		size="large"
		data-test="gate-set-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<label class="nx-field" data-test="field-set-name">
			<span>{{ t('nc_nexus', 'Name') }} *</span>
			<input v-model="form.name" class="nx-input">
		</label>
		<div class="nx-field-row">
			<fieldset class="nx-field set__choice" data-test="field-set-classes">
				<legend>{{ t('nc_nexus', 'Project classes (none = all)') }}</legend>
				<label v-for="c in classes" :key="c">
					<input v-model="form.projectClasses" type="checkbox" :value="c">
					<span>{{ c }}</span>
				</label>
			</fieldset>
			<fieldset class="nx-field set__choice">
				<legend>{{ t('nc_nexus', 'Demand types (none = all)') }}</legend>
				<label v-for="type in types" :key="type">
					<input v-model="form.demandTypes" type="checkbox" :value="type">
					<span>{{ typeLabel(type) }}</span>
				</label>
			</fieldset>
		</div>

		<div class="nx-section">
			<h3>{{ t('nc_nexus', 'Gates') }}</h3>
			<div v-for="g in form.gates"
				:key="g.code"
				class="set__gate"
				:class="{ 'set__gate--off': !g.enabled }"
				:data-test="'set-gate-' + g.code">
				<label class="set__gate-head">
					<input v-model="g.enabled"
						type="checkbox"
						:disabled="['g2', 'g3'].includes(g.code)"
						data-test="gate-enabled">
					<b>{{ g.code.toUpperCase() }}</b>
					<input v-model="g.name"
						class="nx-input"
						:disabled="!g.enabled"
						:aria-label="t('nc_nexus', 'Name')">
				</label>
				<div v-if="g.enabled" class="set__checks">
					<label v-for="c in checks" :key="c" :data-test="'gate-check-' + c">
						<input v-model="g.requiredChecks" type="checkbox" :value="c">
						<span>{{ checkLabel(c) }}</span>
					</label>
					<label class="set__tranche">
						<input v-model="g.budgetTranche" type="checkbox">
						<span>{{ t('nc_nexus', 'releases a budget tranche') }}</span>
					</label>
				</div>
			</div>
		</div>
		<label class="nx-field">
			<span>{{ t('nc_nexus', 'Change note') }}</span>
			<input v-model="form.note" class="nx-input">
		</label>
		<template #actions>
			<NcButton v-if="set"
				variant="error"
				:disabled="busy"
				data-test="retire-set"
				@click="retire">
				{{ t('nc_nexus', 'Retire') }}
			</NcButton>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !dirty || !form.name.trim()"
				data-test="save-set"
				@click="save">
				{{ set ? t('nc_nexus', 'Save as new version') : t('nc_nexus', 'Save') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from './NxDialog.vue'
import { createGateSet, errorMessage, retireGateSet, updateGateSet } from '../api.js'
import { checkLabel, gateLabel, typeLabel } from '../labels.js'

const GATES = ['g2', 'g3', 'g4', 'g5', 'g6']

export default {
	name: 'GateSetDialog',
	components: { NcButton, NxDialog },
	props: {
		set: { type: Object, default: null },
		checks: { type: Array, required: true },
	},
	emits: ['close', 'changed', 'ready'],
	setup() {
		return { t, checkLabel, typeLabel, classes: ['XS', 'S', 'M', 'L'], types: ['mandatory', 'strategic', 'efficiency', 'lifecycle', 'innovation'] }
	},
	data() {
		const byCode = Object.fromEntries((this.set?.gates ?? []).map(g => [g.code, g]))
		const form = {
			name: this.set?.name ?? '',
			projectClasses: [...(this.set?.projectClasses ?? [])],
			demandTypes: [...(this.set?.demandTypes ?? [])],
			note: '',
			gates: GATES.map(code => ({
				code,
				enabled: !!byCode[code] || (!this.set && ['g2', 'g3'].includes(code)),
				name: byCode[code]?.name ?? gateLabel(code),
				requiredChecks: [...(byCode[code]?.requiredChecks ?? [])],
				budgetTranche: byCode[code]?.budgetTranche === 1,
			})),
		}
		return { busy: false, form, saved: JSON.stringify(form) }
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
				const values = { ...this.form, gates: this.form.gates.filter(g => g.enabled) }
				await (this.set ? updateGateSet(this.set.id, values) : createGateSet(values))
				showSuccess(t('nc_nexus', 'Gate set saved'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async retire() {
			this.busy = true
			try {
				await retireGateSet(this.set.id)
				showSuccess(t('nc_nexus', 'Gate set retired'))
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
.set__choice {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 14px;
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

.set__gate {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.set__gate--off {
	color: var(--color-text-maxcontrast);
}

.set__gate-head {
	display: grid;
	grid-template-columns: auto 34px minmax(0, 1fr);
	gap: 8px;
	align-items: center;
}

.set__checks {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 2px 14px;
	margin: 6px 0 0 30px;
	font-size: 13px;

	label {
		display: flex;
		gap: 6px;
		align-items: center;
	}
}

.set__tranche {
	font-weight: 600;
}
</style>
