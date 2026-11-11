<template>
	<div class="model" data-test="scoring-model-view">
		<div class="model__header">
			<h2>{{ t('nc_nexus', 'Assessment model') }}</h2>
			<span v-if="model" class="model__count">{{ t('nc_nexus', 'criteria catalog version {v}', { v: model.set.version }) }}</span>
		</div>
		<p class="model__intro">
			{{ t('nc_nexus', 'Criteria, weights, scale anchors and bucket shares are data, not code. Changing the catalog creates a new version; existing assessments keep their version, so historical ranks stay explainable.') }}
		</p>
		<template v-if="model">
			<section class="model__section">
				<h3>{{ t('nc_nexus', 'Investment buckets') }}</h3>
				<div class="model__table">
					<div v-for="b in buckets"
						:key="b.code"
						class="bucket"
						:data-test="'model-bucket-' + b.code">
						<div class="bucket__name">
							<strong>{{ b.code }} – {{ b.name }}</strong>
							<small>{{ b.description }}</small>
						</div>
						<label class="bucket__field">
							<span>{{ t('nc_nexus', 'Target share (%)') }}</span>
							<NxNumberInput v-model="b.targetSharePct"
								:data-test="'bucket-share-' + b.code"
								:min="0"
								:max="100"
								class="nx-input"
								:disabled="!model.canManage"
								@update:model-value="queueSave(b)" />
						</label>
						<label class="bucket__field">
							<span>{{ t('nc_nexus', 'Budget frame') }}</span>
							<NxNumberInput v-model="b.targetBudget"
								:data-test="'bucket-budget-' + b.code"
								:min="0"
								:max-decimals="0"
								class="nx-input"
								:placeholder="'–'"
								:disabled="!model.canManage"
								@update:model-value="queueSave(b)" />
						</label>
						<span v-if="model.canManage"
							class="bucket__status"
							role="status"
							:data-test="'bucket-status-' + b.code">
							{{ statusText(b.code) }}
						</span>
					</div>
				</div>
				<p class="nx-hint" :class="{ 'model__warn': shareSum !== 100 }" data-test="share-sum">
					{{ t('nc_nexus', 'Sum of the target shares: {s} %', { s: formatNumber(shareSum) }) }}
				</p>
			</section>

			<section class="model__section">
				<div class="model__section-head">
					<h3>{{ t('nc_nexus', 'Criteria catalog') }}</h3>
					<NcButton v-if="model.canManage && !editing"
						variant="secondary"
						data-test="edit-criteria"
						@click="startEdit">
						{{ t('nc_nexus', 'Edit as new version') }}
					</NcButton>
				</div>
				<div v-for="dimension in dimensions" :key="dimension" class="model__dimension">
					<h4>{{ dimensionLabel(dimension) }}</h4>
					<div class="model__table">
						<div v-for="c in criteriaOf(dimension)"
							:key="c.code"
							class="criterion"
							:data-test="'model-criterion-' + c.code">
							<div class="criterion__head">
								<strong>{{ c.code }}</strong>
								<input v-if="editing"
									v-model="c.name"
									class="nx-input"
									:aria-label="t('nc_nexus', 'Name')">
								<span v-else class="criterion__name">{{ c.name }}</span>
								<label v-if="dimension === 'benefit'" class="criterion__weight">
									<span>{{ t('nc_nexus', 'Weight (%)') }}</span>
									<NxNumberInput v-if="editing"
										v-model="c.weight"
										:min="0"
										:max="100"
										class="nx-input" />
									<strong v-else>{{ formatNumber(c.weight) }}</strong>
								</label>
							</div>
							<ol class="criterion__anchors">
								<li v-for="step in model.scale" :key="step">
									<span class="criterion__step">{{ step }}</span>
									<input v-if="editing"
										v-model="c.anchors[step]"
										class="nx-input"
										:aria-label="c.code + ' ' + step">
									<span v-else>{{ c.anchors[step] }}</span>
								</li>
							</ol>
						</div>
					</div>
				</div>
				<div v-if="editing" class="model__edit">
					<p class="nx-hint" :class="{ 'model__warn': weightSum !== 100 }" data-test="weight-sum">
						{{ t('nc_nexus', 'Sum of the benefit weights: {s} % (must be 100)', { s: formatNumber(weightSum) }) }}
					</p>
					<label class="nx-field">
						<span>{{ t('nc_nexus', 'Reason for the change') }}</span>
						<input v-model="note" class="nx-input" data-test="criteria-note">
					</label>
					<div class="model__edit-actions">
						<NcButton variant="tertiary" :disabled="busy" @click="editing = false">
							{{ t('nc_nexus', 'Cancel') }}
						</NcButton>
						<NcButton variant="primary"
							:disabled="busy || weightSum !== 100"
							data-test="save-criteria"
							@click="saveCriteria">
							{{ t('nc_nexus', 'Save as version {v}', { v: model.set.version + 1 }) }}
						</NcButton>
					</div>
				</div>
			</section>

			<section class="model__section">
				<h3>{{ t('nc_nexus', 'Versions') }}</h3>
				<ul class="model__versions" data-test="criteria-versions">
					<li v-for="s in model.sets" :key="s.id">
						<strong>{{ t('nc_nexus', 'Version {v}', { v: s.version }) }}</strong>
						<span class="nx-chip" :class="s.status === 'active' ? 'nx-chip--accepted' : 'nx-chip--draft'">{{ s.status === 'active' ? t('nc_nexus', 'active') : t('nc_nexus', 'replaced') }}</span>
						<span>{{ formatDate(s.createdAt) }} · {{ s.createdBy }}</span>
						<span v-if="s.note" class="model__note">{{ s.note }}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { errorMessage, saveCriteria, updateBucket } from '../api.js'
import NxNumberInput from '../components/NxNumberInput.vue'
import { dimensionLabel, formatDate, formatNumber } from '../labels.js'

export default {
	name: 'ScoringModelView',
	components: { NcButton, NxNumberInput },
	props: {
		model: { type: Object, default: null },
	},
	emits: ['changed'],
	setup() {
		return { t, dimensionLabel, formatDate, formatNumber, dimensions: ['benefit', 'urgency', 'risk'] }
	},
	data() {
		return { busy: false, editing: false, note: '', draft: [], buckets: [], saved: {}, status: {}, timers: {} }
	},
	computed: {
		criteria() {
			return this.editing ? this.draft : (this.model?.set.criteria ?? [])
		},
		weightSum() {
			return Math.round(this.criteria.filter(c => c.dimension === 'benefit').reduce((s, c) => s + (Number(c.weight) || 0), 0) * 100) / 100
		},
		shareSum() {
			return Math.round(this.buckets.reduce((s, b) => s + (Number(b.targetSharePct) || 0), 0) * 100) / 100
		},
	},
	watch: {
		model: {
			immediate: true,
			handler(model) {
				this.buckets = (model?.buckets ?? []).map(b => ({ ...b }))
				this.saved = Object.fromEntries(this.buckets.map(b => [b.code, { share: b.targetSharePct, budget: b.targetBudget ?? null }]))
			},
		},
	},
	methods: {
		criteriaOf(dimension) {
			return this.criteria.filter(c => c.dimension === dimension)
		},
		bucketChanged(b) {
			const saved = this.saved[b.code]
			return saved.share !== (b.targetSharePct ?? 0) || saved.budget !== (b.targetBudget ?? null)
		},
		startEdit() {
			this.draft = JSON.parse(JSON.stringify(this.model.set.criteria))
			this.note = ''
			this.editing = true
		},
		async run(action, message) {
			this.busy = true
			try {
				await action()
				showSuccess(message)
				this.$emit('changed')
				return true
			} catch (e) {
				showError(errorMessage(e))
				return false
			} finally {
				this.busy = false
			}
		},
		statusText(code) {
			return {
				pending: t('nc_nexus', 'Changed …'),
				saving: t('nc_nexus', 'Saving …'),
				saved: t('nc_nexus', 'Saved'),
				error: t('nc_nexus', 'Not saved'),
			}[this.status[code]] ?? ''
		},
		/**
		 * Änderung nach kurzer Tipppause automatisch speichern.
		 * @param {object} b Bucket mit den eingegebenen Werten
		 */
		queueSave(b) {
			clearTimeout(this.timers[b.code])
			this.status[b.code] = 'pending'
			this.timers[b.code] = setTimeout(() => this.autoSave(b), 600)
		},
		async autoSave(b) {
			if (this.status[b.code] === 'saving') {
				return this.queueSave(b)
			}
			if (!this.bucketChanged(b)) {
				this.status[b.code] = ''
				return
			}
			this.status[b.code] = 'saving'
			const values = { targetSharePct: b.targetSharePct ?? 0, targetBudget: b.targetBudget ?? null }
			try {
				await updateBucket(b.code, values)
				this.saved[b.code] = { share: values.targetSharePct, budget: values.targetBudget }
				this.status[b.code] = this.bucketChanged(b) ? 'pending' : 'saved'
				if (this.status[b.code] === 'pending') {
					this.queueSave(b)
				}
			} catch (e) {
				this.status[b.code] = 'error'
				showError(errorMessage(e))
			}
		},
		async saveCriteria() {
			if (await this.run(() => saveCriteria(this.draft, this.note), t('nc_nexus', 'New catalog version is active'))) {
				this.editing = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.model {
	padding: 8px 20px 20px 50px;

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: var(--default-clickable-area, 34px);
		margin: 4px 0 8px;

		h2 {
			margin: 0;
			font-size: 20px;
			font-weight: 600;
		}
	}

	&__count,
	&__intro,
	&__note {
		color: var(--color-text-maxcontrast);
	}

	&__intro {
		margin: 0 0 16px;
		font-size: 13px;
	}

	&__section {
		margin-bottom: 26px;

		h3 {
			margin: 0 0 8px;
			font-size: 12px;
			font-weight: 700;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		h4 {
			margin: 14px 0 6px;
			font-size: 14px;
		}
	}

	&__section-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}

	&__warn {
		color: var(--color-error-text);
		font-weight: 600;
	}

	&__edit {
		margin-top: 12px;
	}

	&__edit-actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
	}

	&__versions {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 10px;
			padding: 4px 0;
		}
	}
}

.bucket {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 150px 170px 110px;
	align-items: end;
	gap: 12px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&__name small {
		display: block;
		color: var(--color-text-maxcontrast);
	}

	&__field span {
		display: block;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__status {
		min-width: 90px;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}
}

.criterion {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	&__name {
		flex: 1;
		font-weight: 600;
	}

	&__weight {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-inline-start: auto;
		font-size: 13px;
		color: var(--color-text-maxcontrast);

		.nx-input.nx-input {
			width: 80px;
		}

		strong {
			color: var(--color-main-text);
		}
	}

	&__anchors {
		margin: 6px 0 0;
		padding: 0;
		list-style: none;
		font-size: 13px;

		li {
			display: grid;
			grid-template-columns: 22px minmax(0, 1fr);
			gap: 8px;
			align-items: center;
			padding: 1px 0;
		}
	}

	&__step {
		font-weight: 700;
		color: var(--color-text-maxcontrast);
	}
}
</style>
