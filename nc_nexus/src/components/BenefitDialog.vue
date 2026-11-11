<template>
	<NxDialog :title="item.title"
		:subtitle="item.demandKey + ' · ' + item.demandTitle"
		size="large"
		data-test="benefit-dialog"
		@close="$emit('close')">
		<section class="nx-section">
			<h3>{{ t('nc_nexus', 'Benefit item') }}</h3>
			<div class="bd__facts">
				<span>{{ t('nc_nexus', 'Type') }}</span>
				<span>{{ benefitTypeLabel(item.type) }}</span>
				<template v-if="item.amount !== null">
					<span>{{ t('nc_nexus', 'Committed per year') }}</span>
					<span>{{ formatMoney(item.amount) }}</span>
				</template>
				<span>{{ t('nc_nexus', 'Metric') }}</span>
				<span>{{ item.metric }}: {{ item.baseline }} → {{ item.target || '?' }}</span>
				<template v-if="item.method">
					<span>{{ t('nc_nexus', 'Measurement method') }}</span>
					<span>{{ item.method }}</span>
				</template>
				<span>{{ t('nc_nexus', 'Benefit owner') }}</span>
				<span>{{ item.ownerUserId }}<template v-if="item.sponsorId"> · {{ t('nc_nexus', 'Sponsor') }}: {{ item.sponsorId }}</template></span>
			</div>
			<p v-if="factor" class="nx-hint" data-test="benefit-factor">
				{{ t('nc_nexus', 'So far, benefits of this type were realized at {f} of the commitment ({n} reviews).', { f: formatFactor(factor.factor), n: factor.count }) }}
			</p>
		</section>

		<section class="nx-section" data-test="benefit-tracking">
			<h3>{{ t('nc_nexus', 'Forecast and review dates') }}</h3>
			<p v-if="!item.committed" class="nx-hint">
				{{ t('nc_nexus', 'Benefits are tracked from the release (G3) on.') }}
			</p>
			<template v-else>
				<div class="nx-field-row">
					<label class="nx-field">
						<span>{{ t('nc_nexus', 'Realization status') }}</span>
						<select v-model="tracking.realizationStatus"
							class="nx-input"
							:disabled="!item.canTrack"
							data-test="benefit-status">
							<option v-for="s in statuses" :key="s" :value="s">{{ realizationStatusLabel(s) }}</option>
						</select>
					</label>
					<label v-if="item.amount !== null" class="nx-field">
						<span>{{ t('nc_nexus', 'Forecast per year (€)') }}</span>
						<NxNumberInput v-model="tracking.forecastAmount"
							:min="0"
							:max-decimals="2"
							class="nx-input"
							:disabled="!item.canTrack"
							data-test="benefit-forecast-amount" />
					</label>
				</div>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Forecast of the metric') }}</span>
					<input v-model="tracking.forecast"
						class="nx-input"
						:disabled="!item.canTrack"
						:placeholder="t('nc_nexus', 'Expected value of {metric}', { metric: item.metric })"
						data-test="benefit-forecast">
				</label>
				<div class="nx-field">
					<span>{{ t('nc_nexus', 'Review dates') }}</span>
					<div v-for="(d, i) in tracking.reviewDates" :key="i" class="bd__date">
						<input v-model="tracking.reviewDates[i]"
							type="date"
							class="nx-input"
							:disabled="!item.canTrack"
							:data-test="'benefit-date-' + i">
						<span class="bd__date-state">{{ dateState(i) }}</span>
						<NcButton v-if="item.canTrack && tracking.reviewDates.length > 1"
							variant="tertiary"
							:aria-label="t('nc_nexus', 'Remove')"
							@click="tracking.reviewDates.splice(i, 1)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</div>
					<NcButton v-if="item.canTrack"
						variant="tertiary"
						data-test="benefit-add-date"
						@click="tracking.reviewDates.push('')">
						{{ t('nc_nexus', 'Add review date') }}
					</NcButton>
					<p class="nx-hint">
						{{ t('nc_nexus', 'The first review takes place 6 to 12 months after go-live. Add further dates for benefit that occurs later or in stages; every review uses up one date.') }}
					</p>
				</div>
			</template>
		</section>

		<section class="nx-section" data-test="benefit-reviews">
			<h3>{{ t('nc_nexus', 'Benefit reviews') }}</h3>
			<p v-if="!item.reviews.length" class="nx-hint">
				{{ t('nc_nexus', 'No review yet.') }}
			</p>
			<div v-for="r in [...item.reviews].reverse()"
				:key="r.id"
				class="bd__review"
				:data-test="'benefit-review-' + r.id">
				<div class="bd__review-head">
					<strong>{{ r.reviewDate }}</strong>
					<span class="nx-chip" :class="assessmentChip(r.assessment)">{{ reviewAssessmentLabel(r.assessment) }}</span>
					<span v-if="r.achievementPct !== null">{{ r.achievementPct }} %</span>
					<span class="bd__muted">{{ r.createdBy }}</span>
				</div>
				<div>
					{{ t('nc_nexus', 'Actual value') }}: {{ r.actualValue }}
					<span v-if="r.actualAmount !== null"> · {{ t('nc_nexus', '{actual} of {planned} p. a.', { actual: formatMoney(r.actualAmount), planned: formatMoney(r.plannedAmount) }) }}</span>
				</div>
				<div v-if="r.explanation">
					<span class="bd__muted">{{ t('nc_nexus', 'Deviation analysis') }}:</span> {{ r.explanation }}
				</div>
				<div v-if="r.lessonsLearned">
					<span class="bd__muted">{{ t('nc_nexus', 'Lessons learned') }}:</span> {{ r.lessonsLearned }}
				</div>
			</div>
		</section>

		<FormDialog v-if="reviewForm"
			:title="t('nc_nexus', 'Record benefit review')"
			:subtitle="item.title"
			:fields="reviewFields"
			:initial="reviewInitial"
			:submit="submitReview"
			:submit-text="t('nc_nexus', 'Record review')"
			:success-text="t('nc_nexus', 'Benefit review recorded')"
			data-test="benefit-review-form"
			@close="reviewForm = false"
			@saved="onReviewed" />

		<template #actions>
			<NcButton v-if="item.canTrack"
				data-test="benefit-new-review"
				@click="reviewForm = true">
				{{ t('nc_nexus', 'Record review') }}
			</NcButton>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton v-if="item.canTrack"
				variant="primary"
				:disabled="busy || !dirty"
				data-test="benefit-save"
				@click="save">
				{{ t('nc_nexus', 'Save') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { mdiClose } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import FormDialog from './FormDialog.vue'
import NxDialog from './NxDialog.vue'
import NxNumberInput from './NxNumberInput.vue'
import { errorMessage, reviewBenefit, trackBenefit } from '../api.js'
import { benefitTypeLabel, formatFactor, formatMoney, realizationStatusLabel, reviewAssessmentLabel } from '../labels.js'

const today = () => new Date().toISOString().slice(0, 10)

const fromItem = (item) => ({
	realizationStatus: item.realizationStatus,
	forecast: item.forecast || '',
	forecastAmount: item.forecastAmount,
	reviewDates: item.reviewDates.length ? [...item.reviewDates] : [''],
})

/**
 * Nutzenposition verfolgen (BEN-002): Prognose, Realisierungsstatus, Messzeitpunkte und Reviews.
 */
export default {
	name: 'BenefitDialog',
	components: { FormDialog, NcButton, NcIconSvgWrapper, NxDialog, NxNumberInput },
	props: {
		row: { type: Object, required: true },
		factors: { type: Object, default: null },
	},
	emits: ['close', 'changed'],
	setup() {
		return {
			mdiClose,
			t,
			benefitTypeLabel,
			formatFactor,
			formatMoney,
			realizationStatusLabel,
			reviewAssessmentLabel,
			statuses: ['not_started', 'on_track', 'at_risk', 'realized', 'missed'],
		}
	},
	data() {
		const tracking = fromItem(this.row)
		return { item: this.row, tracking, saved: JSON.stringify(tracking), busy: false, reviewForm: false }
	},
	computed: {
		dirty() {
			return this.item.canTrack && JSON.stringify(this.tracking) !== this.saved
		},
		factor() {
			const f = this.factors?.byType?.[this.item.type]
			return f && f.count ? f : null
		},
		monetary() {
			return this.item.amount !== null
		},
		reviewInitial() {
			return { reviewDate: this.item.nextReview && this.item.nextReview < today() ? this.item.nextReview : today(), actionOwner: this.item.ownerUserId }
		},
		reviewFields() {
			return [
				{ key: 'reviewDate', label: t('nc_nexus', 'Review date'), type: 'date', required: true },
				{ key: 'actualValue', label: t('nc_nexus', 'Measured value of {metric}', { metric: this.item.metric }), required: true, hint: t('nc_nexus', 'Baseline {b}, target {t}', { b: this.item.baseline, t: this.item.target || '?' }) },
				this.monetary
					? { key: 'actualAmount', label: t('nc_nexus', 'Realized per year (€)'), type: 'number', min: 0, required: true, hint: t('nc_nexus', 'Committed: {a}', { a: formatMoney(this.item.amount) }) }
					: { key: 'achievementPct', label: t('nc_nexus', 'Target achievement (%)'), type: 'number', min: 0, max: 1000, decimals: 0 },
				{
					key: 'assessment',
					label: t('nc_nexus', 'Assessment'),
					type: 'select',
					empty: t('nc_nexus', 'From the target achievement'),
					options: ['achieved', 'partial', 'missed'].map(a => ({ value: a, label: reviewAssessmentLabel(a) })),
					hint: t('nc_nexus', 'From 90 % achieved, from 50 % partially achieved, below missed.'),
				},
				{ key: 'explanation', label: t('nc_nexus', 'Deviation analysis'), type: 'textarea', hint: t('nc_nexus', 'Required if the benefit was not fully achieved: why does the actual value deviate?') },
				{ key: 'lessonsLearned', label: t('nc_nexus', 'Lessons learned'), type: 'textarea' },
				{ key: 'h-action', type: 'heading', label: t('nc_nexus', 'Action'), hint: t('nc_nexus', 'A missed benefit needs at least one action.') },
				{ key: 'actionTitle', label: t('nc_nexus', 'Action'), wide: true, required: false },
				{ key: 'actionOwner', label: t('nc_nexus', 'Responsible'), type: 'user' },
				{ key: 'actionDue', label: t('nc_nexus', 'Due date'), type: 'date' },
			]
		},
	},
	methods: {
		reset(item) {
			this.item = item
			this.tracking = fromItem(item)
			this.saved = JSON.stringify(this.tracking)
		},
		dateState(i) {
			const d = this.tracking.reviewDates[i]
			if (i < this.item.reviews.length) {
				return t('nc_nexus', 'reviewed')
			}
			if (d && d <= today()) {
				return t('nc_nexus', 'due')
			}
			return ''
		},
		assessmentChip(a) {
			return { achieved: 'nx-chip--accepted', partial: 'nx-chip--on-hold', missed: 'nx-chip--rejected' }[a]
		},
		async save() {
			this.busy = true
			try {
				this.reset(await trackBenefit(this.item.demandId, this.item.key, {
					...this.tracking,
					reviewDates: this.tracking.reviewDates.filter(Boolean),
				}))
				showSuccess(t('nc_nexus', 'Saved'))
				this.$emit('changed', this.item)
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async submitReview(v) {
			const actions = v.actionTitle ? [{ title: v.actionTitle, ownerId: v.actionOwner, dueDate: v.actionDue }] : []
			return reviewBenefit(this.item.demandId, this.item.key, {
				reviewDate: v.reviewDate,
				actualValue: v.actualValue,
				actualAmount: v.actualAmount,
				achievementPct: v.achievementPct,
				assessment: v.assessment,
				explanation: v.explanation,
				lessonsLearned: v.lessonsLearned,
				actions,
			})
		},
		onReviewed(item) {
			this.reset(item)
			this.$emit('changed', item)
		},
	},
}
</script>

<style scoped lang="scss">
.bd__facts {
	display: grid;
	grid-template-columns: max-content minmax(0, 1fr);
	gap: 6px 16px;

	> span:nth-child(odd) {
		color: var(--color-text-maxcontrast);
	}
}

.bd__date {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;

	.nx-input {
		max-width: 200px;
	}
}

.bd__date-state,
.bd__muted {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.bd__review {
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

.bd__review-head {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 4px;
}
</style>
