<template>
	<NxDialog :title="gateLabel(step) + ': ' + title"
		:subtitle="subtitle"
		data-test="gate-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<div v-if="checks.length" class="nx-section">
			<h3>{{ t('nc_nexus', 'Checklist') }}</h3>
			<ul class="gate__checks">
				<li v-for="c in checks"
					:key="c.key"
					:class="c.ok ? 'gate__ok' : 'gate__fail'"
					:data-test="'check-' + c.key">
					<NcIconSvgWrapper :path="c.ok ? mdiCheckCircle : mdiCloseCircle" :size="18" />
					<span>{{ checkLabel(c.key) }}<small v-if="!c.ok && c.detail"> · {{ c.detail }}</small></span>
				</li>
			</ul>
		</div>
		<div v-if="step !== 'candidate'" class="nx-section">
			<h3>{{ t('nc_nexus', 'Decision') }}</h3>
			<label v-for="o in outcomes"
				:key="o"
				class="gate__outcome"
				:data-test="'outcome-' + o">
				<input v-model="outcome" type="radio" :value="o">
				<span>{{ gateOutcomeLabel(step, o) }}</span>
			</label>
			<p v-if="go && !ready" class="nx-hint gate__blocked">
				{{ t('nc_nexus', 'An approval needs all items of the checklist. Defer, rework or reject are possible anyway.') }}
			</p>
			<label v-if="needsDate" class="nx-field gate__date" data-test="field-gate-resubmission">
				<span>{{ outcome === 'rework' ? t('nc_nexus', 'Rework due') : (step === 'g4' ? t('nc_nexus', 'Resume on') : t('nc_nexus', 'Resubmission')) }} *</span>
				<input v-model="resubmissionDate"
					type="date"
					class="nx-input"
					:min="today">
			</label>
		</div>

		<div v-if="needsConditions" class="nx-section" data-test="gate-conditions">
			<h3>{{ conditionKind === 'action' ? t('nc_nexus', 'Actions') : t('nc_nexus', 'Conditions') }}</h3>
			<p class="nx-hint">
				{{ conditionKind === 'action'
					? t('nc_nexus', 'Who does what by when? Actions are tracked until they are done.')
					: t('nc_nexus', 'Conditions have an owner and a due date. Blocking conditions lock the next gate until they are fulfilled.') }}
			</p>
			<div v-for="(c, i) in conditions"
				:key="i"
				class="gate__condition"
				:data-test="'condition-' + i">
				<label class="nx-field gate__what">
					<span>{{ t('nc_nexus', 'What') }} *</span>
					<input v-model="c.title" class="nx-input" data-test="condition-title">
				</label>
				<div class="nx-field" data-test="condition-owner">
					<span>{{ t('nc_nexus', 'Owner') }} *</span>
					<UserSelect v-model="c.ownerId" :label="t('nc_nexus', 'Owner')" />
				</div>
				<label class="nx-field gate__due">
					<span>{{ t('nc_nexus', 'Due') }} *</span>
					<input v-model="c.dueDate"
						type="date"
						class="nx-input"
						:min="today"
						data-test="condition-due">
				</label>
				<label v-if="conditionKind === 'condition'" class="gate__blocking">
					<input v-model="c.blocking" type="checkbox" data-test="condition-blocking">
					<span>{{ t('nc_nexus', 'blocks the next gate') }}</span>
				</label>
				<NcButton variant="tertiary"
					:aria-label="t('nc_nexus', 'Remove row')"
					@click="conditions.splice(i, 1)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</div>
			<NcButton variant="tertiary" data-test="add-condition" @click="addCondition">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ conditionKind === 'action' ? t('nc_nexus', 'Add action') : t('nc_nexus', 'Add condition') }}
			</NcButton>
		</div>

		<div v-if="tranche && go" class="nx-section" data-test="gate-tranche">
			<h3>{{ t('nc_nexus', 'Budget tranche') }}</h3>
			<p class="nx-hint">
				{{ t('nc_nexus', 'Budget is released in tranches per gate, so each decision risks only the next stage. Optional.') }}
			</p>
			<div class="nx-field-row">
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Amount (€)') }}</span>
					<NxNumberInput v-model="trancheAmount"
						:min="0"
						:max-decimals="0"
						class="nx-input"
						data-test="tranche-amount" />
				</label>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Fiscal year') }}</span>
					<NxNumberInput v-model="trancheYear"
						:min="2000"
						:max="2100"
						:max-decimals="0"
						:grouping="false"
						class="nx-input" />
				</label>
			</div>
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
import { mdiCheckCircle, mdiClose, mdiCloseCircle, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NxDialog from './NxDialog.vue'
import NxNumberInput from './NxNumberInput.vue'
import UserSelect from './UserSelect.vue'
import { decideGate, decideItem, errorMessage } from '../api.js'
import { checkLabel, gateLabel, gateOutcomeLabel, today } from '../labels.js'

/**
 * Beschluss an einem Gate – direkt (PMO/Board des Bereichs) oder als Protokoll eines
 * Tagesordnungspunkts in der Sitzung (item gesetzt).
 */
export default {
	name: 'GateDialog',
	components: { NcButton, NcIconSvgWrapper, NxDialog, NxNumberInput, UserSelect },
	props: {
		/** Vorhaben-ID (Direktbeschluss) */
		demandId: { type: Number, default: null },
		title: { type: String, required: true },
		step: { type: String, required: true },
		checks: { type: Array, default: () => [] },
		outcomes: { type: Array, default: () => [] },
		ready: { type: Boolean, default: true },
		tranche: { type: Boolean, default: false },
		/** Tagesordnungspunkt (Beschluss in der Sitzung) */
		itemId: { type: Number, default: null },
	},
	emits: ['close', 'decided', 'ready'],
	setup() {
		return { mdiCheckCircle, mdiClose, mdiCloseCircle, mdiPlus, t, checkLabel, gateLabel, gateOutcomeLabel, today: today() }
	},
	data() {
		return {
			busy: false,
			outcome: this.outcomes[0] ?? 'approved',
			reason: '',
			resubmissionDate: '',
			conditions: [],
			trancheAmount: null,
			trancheYear: Number(today().slice(0, 4)),
		}
	},
	computed: {
		subtitle() {
			return {
				candidate: t('nc_nexus', 'The portfolio office confirms that the required information is complete; the project then competes in the ranking.'),
				g2: t('nc_nexus', 'Prioritizing turns the demand into a project with its own key; the business case is frozen as a version.'),
				g3: t('nc_nexus', 'Release commits the resource needs firmly. If a bottleneck pool lacks capacity, the release is refused.'),
				g4: t('nc_nexus', 'Do the findings justify the next investment stage? Pausing needs a resume date; cancelling releases all commitments.'),
				g5: t('nc_nexus', 'Is the result ready for handover? Acceptance releases the commitments from next month on.'),
				g6: t('nc_nexus', 'Has the promised benefit materialized? A missed or partial benefit needs actions.'),
			}[this.step]
		},
		go() {
			return ['approved', 'approved_with_conditions'].includes(this.outcome) && this.step !== 'candidate'
		},
		needsDate() {
			return ['deferred', 'rework'].includes(this.outcome)
		},
		conditionKind() {
			return this.step === 'g6' || this.outcome === 'rework' ? 'action' : 'condition'
		},
		needsConditions() {
			return this.outcome === 'approved_with_conditions' || (this.step === 'g6' && this.outcome === 'rejected') || this.outcome === 'rework'
		},
		conditionsRequired() {
			return this.outcome === 'approved_with_conditions' || (this.step === 'g6' && this.outcome === 'rejected')
		},
		reasonRequired() {
			return this.step !== 'candidate' && this.outcome !== 'approved'
		},
		filledConditions() {
			return this.conditions.filter(c => c.title.trim() || c.ownerId || c.dueDate)
		},
		conditionsValid() {
			return this.filledConditions.every(c => c.title.trim() && c.ownerId && c.dueDate)
		},
		dirty() {
			return this.reason.trim() !== '' || this.resubmissionDate !== '' || this.outcome !== (this.outcomes[0] ?? 'approved')
				|| this.filledConditions.length > 0 || this.trancheAmount !== null
		},
		canSubmit() {
			if (this.reasonRequired && !this.reason.trim()) {
				return false
			}
			if (this.needsDate && !this.resubmissionDate) {
				return false
			}
			if (!this.conditionsValid || (this.conditionsRequired && this.filledConditions.length === 0)) {
				return false
			}
			return this.ready || !this.go
		},
		actionText() {
			if (this.step === 'candidate') {
				return t('nc_nexus', 'Put up for prioritization')
			}
			return this.itemId ? t('nc_nexus', 'Record decision') : t('nc_nexus', 'Decide')
		},
	},
	watch: {
		needsConditions(value) {
			if (value && this.conditions.length === 0) {
				this.addCondition()
			}
		},
	},
	methods: {
		addCondition() {
			this.conditions.push({ title: '', ownerId: '', dueDate: '', blocking: false })
		},
		async save() {
			this.busy = true
			const payload = {
				step: this.step,
				outcome: this.step === 'candidate' ? null : this.outcome,
				reason: this.reason || null,
				resubmissionDate: this.needsDate ? this.resubmissionDate : null,
				conditions: this.needsConditions ? this.filledConditions : [],
				tranche: this.go && this.tranche && this.trancheAmount ? { amount: this.trancheAmount, fiscalYear: this.trancheYear } : null,
			}
			try {
				const result = this.itemId ? await decideItem(this.itemId, payload) : await decideGate(this.demandId, payload)
				showSuccess(t('nc_nexus', 'Decision recorded'))
				this.$emit('decided', result)
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

.gate__blocked {
	margin-top: 6px;
}

.gate__condition {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 160px auto auto;
	gap: 6px 10px;
	align-items: end;
	margin-bottom: 10px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);

	.nx-field {
		margin-bottom: 0;
	}
}

// „Was“ über die ganze Breite, darunter Verantwortlich, Frist, Sperre
.gate__what {
	grid-column: 1 / -1;
}

.gate__blocking {
	display: flex;
	gap: 6px;
	align-items: center;
	padding-bottom: 10px;
	font-size: 13px;
	white-space: nowrap;
}
</style>
