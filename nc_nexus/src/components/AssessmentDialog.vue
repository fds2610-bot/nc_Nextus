<template>
	<NxDialog :title="kind === 'review' ? t('nc_nexus', 'Review: {title}', { title: demand.title }) : t('nc_nexus', 'Self-assessment: {title}', { title: demand.title })"
		:subtitle="t('nc_nexus', 'Criteria catalog version {v} · scale 0 / 1 / 3 / 5 / 8. An assessment cannot be changed afterwards; a new estimate is a new assessment.', { v: model.set.version })"
		size="large"
		data-test="assessment-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<div v-if="kind === 'review'" class="nx-section">
				<h3>{{ t('nc_nexus', 'Bucket') }}</h3>
				<label class="nx-field" data-test="field-bucket">
					<span class="nx-hidden-visually">{{ t('nc_nexus', 'Bucket') }}</span>
					<select v-model="form.bucket" class="nx-input">
						<option v-for="b in model.buckets" :key="b.code" :value="b.code">{{ b.code }} – {{ b.name }}</option>
					</select>
					<p v-if="!bucketScored" class="nx-hint">{{ t('nc_nexus', 'Mandatory projects need no benefit score; they are ranked by deadline and effort.') }}</p>
				</label>
			</div>

			<div v-for="dimension in dimensions" :key="dimension" class="nx-section">
				<h3>{{ dimensionLabel(dimension) }}</h3>
				<p v-if="dimension === 'benefit' && !bucketScored" class="nx-hint assess__skip">
					{{ t('nc_nexus', 'Optional in the mandatory bucket.') }}
				</p>
				<fieldset v-for="c in criteriaOf(dimension)"
					:key="c.code"
					class="assess__criterion"
					:data-test="'criterion-' + c.code">
					<legend>
						<strong>{{ c.code }} {{ c.name }}</strong>
						<span v-if="dimension === 'benefit'" class="assess__weight">{{ formatNumber(c.weight) }} %</span>
					</legend>
					<label v-for="step in model.scale"
						:key="step"
						class="assess__step"
						:class="{ 'assess__step--active': form.scores[c.code] === step }">
						<input v-model="form.scores[c.code]"
							type="radio"
							:value="step"
							:name="'score-' + c.code">
						<span class="assess__value">{{ step }}</span>
						<span>{{ c.anchors[step] }}</span>
					</label>
				</fieldset>
			</div>

			<div class="nx-section">
				<h3>{{ t('nc_nexus', 'Effort') }}</h3>
				<div class="nx-field-row">
					<label class="nx-field" data-test="field-effort">
						<span>{{ t('nc_nexus', 'Effort class') }} *</span>
						<select v-model.number="form.effort" class="nx-input">
							<option v-for="e in model.efforts" :key="e" :value="e">{{ effortLabel(e) }}</option>
						</select>
						<p class="nx-hint">{{ t('nc_nexus', 'Suggested from the recorded resource needs: {e}', { e: suggestedEffort }) }}</p>
					</label>
					<label class="nx-field">
						<span>{{ t('nc_nexus', 'Estimate quality') }}</span>
						<select v-model="form.estimateQuality" class="nx-input">
							<option v-for="q in model.qualities" :key="q" :value="q">{{ qualityLabel(q) }}</option>
						</select>
					</label>
				</div>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Comment') }}</span>
					<textarea v-model="form.comment" class="nx-input" />
				</label>
			</div>
		</form>
		<template #actions>
			<span class="nx-dialog__left assess__preview" data-test="assess-preview">
				{{ t('nc_nexus', 'Value density {d}', { d: preview === null ? '–' : formatNumber2(preview.density) }) }}
				<small v-if="preview">{{ t('nc_nexus', 'benefit {b} · urgency {u} · risk {r}', { b: formatNumber2(preview.benefit), u: preview.urgency, r: formatNumber2(preview.risk) }) }}</small>
			</span>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !complete"
				data-test="save-assessment"
				@click="save">
				{{ t('nc_nexus', 'Save assessment') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCanonicalLocale, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from './NxDialog.vue'
import { assessDemand, errorMessage } from '../api.js'
import { dimensionLabel, effortLabel, formatNumber, qualityLabel } from '../labels.js'

export default {
	name: 'AssessmentDialog',
	components: { NcButton, NxDialog },
	props: {
		demand: { type: Object, required: true },
		kind: { type: String, required: true },
		model: { type: Object, required: true },
		suggestedEffort: { type: Number, default: 3 },
		bucket: { type: String, default: 'C' },
		/** letzte Bewertung derselben Art als Vorbelegung */
		previous: { type: Object, default: null },
	},
	emits: ['close', 'saved', 'ready'],
	setup() {
		return { t, dimensionLabel, effortLabel, formatNumber, qualityLabel, dimensions: ['benefit', 'urgency', 'risk'] }
	},
	data() {
		const form = {
			bucket: this.bucket,
			scores: { ...(this.previous?.scores ?? {}) },
			effort: this.previous?.effort ?? this.suggestedEffort,
			estimateQuality: this.previous?.estimateQuality ?? 'rough',
			comment: '',
		}
		return { busy: false, form, initial: JSON.stringify(form) }
	},
	computed: {
		dirty() {
			return JSON.stringify(this.form) !== this.initial
		},
		bucketScored() {
			return this.model.buckets.find(b => b.code === this.form.bucket)?.scored ?? true
		},
		complete() {
			return this.model.set.criteria.every(c =>
				this.form.scores[c.code] !== undefined || (c.dimension === 'benefit' && !this.bucketScored))
		},
		// dieselbe Formel wie der Server (Kapitel 03 §3), nur zur Vorschau
		preview() {
			if (!this.complete) {
				return null
			}
			let benefit = 0
			let urgency = 0
			const risks = []
			for (const c of this.model.set.criteria) {
				const v = this.form.scores[c.code]
				if (v === undefined) {
					continue
				}
				if (c.dimension === 'benefit') {
					benefit += v * c.weight / 100
				} else if (c.dimension === 'urgency') {
					urgency = Math.max(urgency, v)
				} else {
					risks.push(v)
				}
			}
			const risk = risks.length ? risks.reduce((a, b) => a + b, 0) / risks.length : 0
			return { benefit, urgency, risk, density: (benefit * (1 + urgency / 8)) / (this.form.effort * (1 + risk / 16)) }
		},
	},
	methods: {
		criteriaOf(dimension) {
			return this.model.set.criteria.filter(c => c.dimension === dimension)
		},
		formatNumber2(value) {
			return Number(value).toLocaleString(getCanonicalLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		},
		async save() {
			if (!this.complete) {
				return
			}
			this.busy = true
			try {
				const result = await assessDemand(this.demand.id, { kind: this.kind, ...this.form })
				showSuccess(t('nc_nexus', 'Assessment saved'))
				this.$emit('saved', result)
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
.assess__criterion {
	margin: 0 0 14px;
	padding: 0;
	border: none;

	legend {
		display: flex;
		gap: 10px;
		align-items: baseline;
		margin-bottom: 4px;
		padding: 0;
	}
}

.assess__weight {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.assess__step {
	display: grid;
	grid-template-columns: auto 22px minmax(0, 1fr);
	align-items: baseline;
	gap: 8px;
	padding: 3px 8px;
	border-radius: var(--border-radius, 8px);
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}

	&--active {
		background: var(--color-primary-element-light);
	}
}

.assess__value {
	font-weight: 700;
	font-variant-numeric: tabular-nums;
}

.assess__skip {
	margin: -8px 0 8px;
}

.assess__preview {
	display: flex;
	flex-direction: column;
	font-weight: 700;

	small {
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}
}
</style>
