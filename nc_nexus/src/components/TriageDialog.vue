<template>
	<NxDialog :title="t('nc_nexus', 'Triage: {title}', { title: demand.title })"
		:subtitle="demand.key + ' · ' + t('nc_nexus', 'by {user}', { user: demand.requestorId })"
		data-test="triage-dialog"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<fieldset class="triage__codes">
				<legend class="nx-hidden-visually">
					{{ t('nc_nexus', 'Decision') }}
				</legend>
				<label v-for="option in options"
					:key="option"
					class="triage__code"
					:class="{ 'triage__code--active': code === option }"
					:data-test="'code-' + option">
					<input v-model="code" type="radio" :value="option">
					<span>
						<strong>{{ codeLabel(option) }}</strong>
						<small>{{ codeHint(option) }}</small>
					</span>
				</label>
			</fieldset>

			<div v-if="code" class="nx-section triage__details">
				<label v-if="code === 'accepted'" class="nx-field" data-test="field-class">
					<span>{{ t('nc_nexus', 'Project class') }}</span>
					<select v-model="form.projectClass" class="nx-input">
						<option value="">{{ t('nc_nexus', '– not yet –') }}</option>
						<option v-for="c in classes" :key="c" :value="c">{{ classLabel(c) }}</option>
					</select>
				</label>

				<template v-if="code === 'further_analysis'">
					<div class="nx-field" data-test="field-analysis-owner">
						<span>{{ t('nc_nexus', 'Analysis by') }} *</span>
						<NcSelect v-model="owner"
							:options="ownerOptions"
							:loading="searching"
							:filterable="false"
							:placeholder="t('nc_nexus', 'Search accounts …')"
							:aria-label-combobox="t('nc_nexus', 'Analysis by')"
							label="label"
							@search="searchOwner" />
					</div>
					<label class="nx-field" data-test="field-analysis-due">
						<span>{{ t('nc_nexus', 'Analysis due') }} *</span>
						<input v-model="form.analysisDue"
							type="date"
							class="nx-input"
							:min="today">
					</label>
				</template>

				<label v-if="code === 'deferred'" class="nx-field" data-test="field-resubmission">
					<span>{{ t('nc_nexus', 'Resubmission') }} *</span>
					<input v-model="form.resubmissionDate"
						type="date"
						class="nx-input"
						:min="today">
				</label>

				<label v-if="code === 'duplicate'" class="nx-field" data-test="field-duplicate">
					<span>{{ t('nc_nexus', 'Leading demand') }} *</span>
					<select v-model="form.duplicateOfId" class="nx-input">
						<option :value="null">{{ t('nc_nexus', '– choose –') }}</option>
						<option v-for="d in duplicateCandidates" :key="d.id" :value="d.id">{{ d.key }} · {{ d.title }}</option>
					</select>
				</label>

				<template v-if="code === 'query'">
					<label class="nx-field" data-test="field-question">
						<span>{{ t('nc_nexus', 'Question to the requestor') }} *</span>
						<textarea v-model="form.question" class="nx-input" />
					</label>
					<label class="nx-field" data-test="field-query-due">
						<span>{{ t('nc_nexus', 'Answer due') }} *</span>
						<input v-model="form.due"
							type="date"
							class="nx-input"
							:min="today">
					</label>
				</template>

				<label v-else class="nx-field" data-test="field-reason">
					<span>{{ t('nc_nexus', 'Reason') }}{{ code === 'rejected' ? ' *' : '' }}</span>
					<textarea v-model="form.reason"
						class="nx-input"
						:placeholder="code === 'rejected' ? t('nc_nexus', 'Why is the demand not accepted?') : ''" />
				</label>
			</div>
		</form>
		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !complete"
				data-test="save-triage"
				@click="save">
				{{ code === 'query' ? t('nc_nexus', 'Send query') : t('nc_nexus', 'Decide') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NxDialog from './NxDialog.vue'
import { errorMessage, queryDemand, searchPrincipals, triageDemand } from '../api.js'
import { codeHint, codeLabel, today, TRIAGE_CODES } from '../labels.js'

export default {
	name: 'TriageDialog',
	components: { NcButton, NcSelect, NxDialog },
	props: {
		demand: { type: Object, required: true },
		demands: { type: Array, default: () => [] },
	},
	emits: ['close', 'decided'],
	setup() {
		return { t, codeHint, codeLabel, today: today(), classes: loadState('nc_nexus', 'project_classes', []) }
	},
	data() {
		return {
			busy: false,
			searching: false,
			code: null,
			owner: null,
			ownerOptions: [],
			form: { reason: '', projectClass: '', analysisDue: '', resubmissionDate: '', duplicateOfId: null, question: '', due: '' },
		}
	},
	computed: {
		options() {
			return [...TRIAGE_CODES, 'query']
		},
		dirty() {
			return this.code !== null
		},
		duplicateCandidates() {
			return this.demands.filter(d => d.id !== this.demand.id && !['draft', 'duplicate'].includes(d.status))
		},
		complete() {
			switch (this.code) {
			case 'accepted': return true
			case 'further_analysis': return !!this.owner && !!this.form.analysisDue
			case 'deferred': return !!this.form.resubmissionDate
			case 'rejected': return this.form.reason.trim() !== ''
			case 'duplicate': return this.form.duplicateOfId !== null
			case 'query': return this.form.question.trim() !== '' && !!this.form.due
			default: return false
			}
		},
	},
	methods: {
		classLabel(c) {
			return {
				XS: t('nc_nexus', 'XS – service request'),
				S: t('nc_nexus', 'S – small project'),
				M: t('nc_nexus', 'M – project'),
				L: t('nc_nexus', 'L – major project / programme'),
			}[c] || c
		},
		async searchOwner(query) {
			if (!query.trim()) {
				return
			}
			this.searching = true
			try {
				this.ownerOptions = (await searchPrincipals(query.trim())).filter(p => p.type === 'user')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.searching = false
			}
		},
		async save() {
			if (!this.complete) {
				return
			}
			this.busy = true
			try {
				const result = this.code === 'query'
					? await queryDemand(this.demand.id, { question: this.form.question, due: this.form.due })
					: await triageDemand(this.demand.id, {
						code: this.code,
						reason: this.form.reason || null,
						projectClass: this.form.projectClass || null,
						analysisOwner: this.owner?.id ?? null,
						analysisDue: this.form.analysisDue || null,
						resubmissionDate: this.form.resubmissionDate || null,
						duplicateOfId: this.form.duplicateOfId,
					})
				showSuccess(this.code === 'query' ? t('nc_nexus', 'Query sent') : t('nc_nexus', 'Decision recorded'))
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
.triage__codes {
	display: grid;
	gap: 6px;
	margin: 0;
	padding: 0;
	border: none;
}

.triage__code {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}

	&--active {
		border-color: var(--color-primary-element);
		background: var(--color-primary-element-light);
	}

	input {
		margin-top: 4px;
	}

	span {
		display: flex;
		flex-direction: column;
	}

	small {
		color: var(--color-text-maxcontrast);
		font-size: 13px;
	}
}

.triage__details {
	margin-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
