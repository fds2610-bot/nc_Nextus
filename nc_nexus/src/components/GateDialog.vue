<template>
	<NxDialog :title="gateLabel(gate.step) + ': ' + demand.title"
		:subtitle="subtitle"
		data-test="gate-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<div class="nx-section">
			<h3>{{ t('nc_nexus', 'Checklist') }}</h3>
			<ul class="gate__checks">
				<li v-for="c in gate.checks"
					:key="c.key"
					:class="c.ok ? 'gate__ok' : 'gate__fail'"
					:data-test="'check-' + c.key">
					<NcIconSvgWrapper :path="c.ok ? mdiCheckCircle : mdiCloseCircle" :size="18" />
					<span>{{ checkLabel(c.key) }}<small v-if="!c.ok && c.detail"> · {{ c.detail }}</small></span>
				</li>
			</ul>
		</div>
		<div v-if="gate.step === 'g2'" class="nx-section">
			<h3>{{ t('nc_nexus', 'Decision') }}</h3>
			<label v-for="o in g2Outcomes"
				:key="o"
				class="gate__outcome"
				:data-test="'outcome-' + o">
				<input v-model="outcome" type="radio" :value="o">
				<span>{{ outcomeText(o) }}</span>
			</label>
			<label v-if="outcome === 'deferred'" class="nx-field gate__date" data-test="field-gate-resubmission">
				<span>{{ t('nc_nexus', 'Resubmission') }} *</span>
				<input v-model="resubmissionDate"
					type="date"
					class="nx-input"
					:min="today">
			</label>
		</div>
		<label class="nx-field" data-test="field-gate-reason">
			<span>{{ t('nc_nexus', 'Reason') }}{{ reasonRequired ? ' *' : '' }}</span>
			<textarea v-model="reason" class="nx-input" />
		</label>
		<template #actions>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !canSubmit"
				data-test="save-gate"
				@click="save">
				{{ actionText }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { mdiCheckCircle, mdiCloseCircle } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NxDialog from './NxDialog.vue'
import { decideGate, errorMessage } from '../api.js'
import { checkLabel, gateLabel, today } from '../labels.js'

export default {
	name: 'GateDialog',
	components: { NcButton, NcIconSvgWrapper, NxDialog },
	props: {
		demand: { type: Object, required: true },
		gate: { type: Object, required: true },
	},
	emits: ['close', 'decided', 'ready'],
	setup() {
		return { mdiCheckCircle, mdiCloseCircle, t, checkLabel, gateLabel, today: today(), g2Outcomes: ['prioritized', 'deferred', 'rejected'] }
	},
	data() {
		return { busy: false, outcome: 'prioritized', reason: '', resubmissionDate: '' }
	},
	computed: {
		subtitle() {
			return {
				candidate: t('nc_nexus', 'The portfolio office confirms that the required information is complete; the project then competes in the ranking.'),
				g2: t('nc_nexus', 'Prioritizing turns the demand into a project with its own key; the business case is frozen as a version.'),
				g3: t('nc_nexus', 'Release commits the resource needs firmly. If a bottleneck pool lacks capacity, the release is refused.'),
			}[this.gate.step]
		},
		reasonRequired() {
			return this.gate.step === 'g2' && this.outcome !== 'prioritized'
		},
		dirty() {
			return this.reason.trim() !== '' || this.resubmissionDate !== '' || this.outcome !== 'prioritized'
		},
		// Ablehnen und Zurückstellen sind auch bei unvollständigen Angaben möglich
		canSubmit() {
			if (this.reasonRequired && !this.reason.trim()) {
				return false
			}
			if (this.gate.step === 'g2' && this.outcome === 'deferred' && !this.resubmissionDate) {
				return false
			}
			return this.gate.ready || (this.gate.step === 'g2' && this.outcome !== 'prioritized')
		},
		actionText() {
			if (this.gate.step === 'candidate') {
				return t('nc_nexus', 'Put up for prioritization')
			}
			if (this.gate.step === 'g3') {
				return t('nc_nexus', 'Release')
			}
			return t('nc_nexus', 'Decide')
		},
	},
	methods: {
		outcomeText(o) {
			return {
				prioritized: t('nc_nexus', 'Prioritize – becomes a project'),
				deferred: t('nc_nexus', 'Defer – not prioritized now'),
				rejected: t('nc_nexus', 'Reject'),
			}[o]
		},
		async save() {
			this.busy = true
			try {
				const demand = await decideGate(this.demand.id, {
					step: this.gate.step,
					outcome: this.gate.step === 'g2' ? this.outcome : null,
					reason: this.reason || null,
					resubmissionDate: this.resubmissionDate || null,
				})
				showSuccess(t('nc_nexus', 'Decision recorded'))
				this.$emit('decided', demand)
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
.gate__checks {
	margin: 0;
	padding: 0;
	list-style: none;

	li {
		display: flex;
		gap: 8px;
		align-items: center;
		padding: 3px 0;
	}

	small {
		color: var(--color-text-maxcontrast);
	}
}

.gate__ok {
	color: var(--color-success-text);
}

.gate__fail {
	color: var(--color-error-text);
}

.gate__outcome {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 3px 0;
	cursor: pointer;
}

.gate__date {
	max-width: 220px;
	margin-top: 8px;
}
</style>
