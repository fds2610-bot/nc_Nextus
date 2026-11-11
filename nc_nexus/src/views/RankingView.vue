<template>
	<div class="ranking" data-test="ranking-view">
		<div class="ranking__header">
			<h2>{{ t('nc_nexus', 'Prioritization') }}</h2>
			<span class="ranking__count">{{ n('nc_nexus', '%n project in the ranking', '%n projects in the ranking', total) }}</span>
			<NcButton class="ranking__cut"
				:variant="cut ? 'secondary' : 'primary'"
				:disabled="cutting"
				data-test="run-cut"
				@click="runCut">
				{{ cut ? t('nc_nexus', 'Recalculate capacity cut') : t('nc_nexus', 'Calculate capacity cut') }}
			</NcButton>
		</div>
		<p class="ranking__intro">
			{{ t('nc_nexus', 'Competition takes place within a bucket. Rank = value density (benefit per unit of capacity); the mandatory bucket is ranked by deadline and effort. Scoring supports the decision, it does not replace it.') }}
		</p>
		<div v-if="loading" class="ranking__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<section v-for="bucket in buckets"
			v-else
			:key="bucket.code"
			class="ranking__bucket"
			:data-test="'bucket-' + bucket.code">
			<div class="ranking__bucket-head">
				<h3>{{ bucket.code }} – {{ bucket.name }}</h3>
				<span class="ranking__quota">{{ t('nc_nexus', 'target share {p} % of capacity', { p: formatNumber(bucket.targetSharePct) }) }}</span>
			</div>
			<p v-if="!bucket.items.length" class="ranking__empty">
				{{ t('nc_nexus', 'No projects in this bucket.') }}
			</p>
			<div v-else class="ranking__table">
				<div class="rank rank--head" aria-hidden="true">
					<span>{{ t('nc_nexus', 'Rank') }}</span>
					<span>{{ t('nc_nexus', 'Project') }}</span>
					<span>{{ bucket.scored ? t('nc_nexus', 'Value density') : t('nc_nexus', 'Deadline') }}</span>
					<span>{{ t('nc_nexus', 'Benefit') }}</span>
					<span>{{ t('nc_nexus', 'Urgency') }}</span>
					<span>{{ t('nc_nexus', 'Effort') }}</span>
					<span>{{ t('nc_nexus', 'Risk') }}</span>
					<span>{{ t('nc_nexus', 'PD') }}</span>
					<span>{{ t('nc_nexus', 'Start plan') }}</span>
					<span />
				</div>
				<div v-for="item in bucket.items"
					:key="item.id"
					class="rank"
					:class="{ 'rank--unrated': !item.assessment }"
					:data-test="'rank-' + item.key">
					<span class="rank__no">
						<strong data-test="rank-no">{{ item.rank }}</strong>
						<small v-if="item.rankOverride !== null" :title="item.overrideReason" data-test="rank-overridden">{{ t('nc_nexus', 'board: was {r}', { r: item.computedRank }) }}</small>
					</span>
					<span class="rank__name">
						<a href="#" @click.prevent="$emit('open', item.id)">{{ item.title }}</a>
						<small>{{ item.key }} · {{ statusLabel(item.status) }}<template v-if="item.rankOverride !== null"> · {{ item.overrideReason }}</template></small>
					</span>
					<template v-if="item.assessment">
						<span v-if="bucket.scored" class="rank__density">
							<strong>{{ f2(item.assessment.density) }}</strong>
							<small v-if="deviates(item.assessment)" :title="t('nc_nexus', 'Self-assessment and review differ noticeably')">{{ t('nc_nexus', 'self {s}', { s: f2(item.assessment.selfDensity) }) }}</small>
						</span>
						<span v-else>{{ item.deadline ? formatIsoDay(item.deadline) : '–' }}</span>
						<span>{{ f2(item.assessment.benefit) }}</span>
						<span>{{ item.assessment.urgency }}</span>
						<span>{{ item.assessment.effort }}</span>
						<span>{{ f2(item.assessment.risk) }}</span>
					</template>
					<span v-else class="rank__missing">{{ t('nc_nexus', 'not assessed yet') }}</span>
					<span>{{ item.personDays ? formatNumber(item.personDays) : '–' }}</span>
					<span class="rank__plan" :data-test="'plan-' + item.key">
						<span v-if="cut && cut.results[item.id]" class="nx-chip" :class="'nx-chip--' + planChip(cut.results[item.id])">{{ planText(cut.results[item.id]) }}</span>
						<span v-else class="rank__muted">–</span>
					</span>
					<span class="rank__actions">
						<NcButton v-if="item.canOverride"
							variant="tertiary"
							:aria-label="t('nc_nexus', 'Override rank of {title}', { title: item.title })"
							:data-test="'override-' + item.key"
							@click="openOverride(item)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiSwapVertical" />
							</template>
						</NcButton>
					</span>
				</div>
			</div>
		</section>

		<NxDialog v-if="dialog"
			:title="t('nc_nexus', 'Override rank: {title}', { title: dialog.item.title })"
			:subtitle="t('nc_nexus', 'Board correction: stored as its own field with reason and shown in the ranking. A high correction rate means the criteria catalog needs readjusting.')"
			data-test="override-dialog"
			@close="dialog = null">
			<div class="nx-field-row">
				<label class="nx-field" data-test="field-override-rank">
					<span>{{ t('nc_nexus', 'Rank in the bucket') }} *</span>
					<NxNumberInput v-model="dialog.rank"
						:max-decimals="0"
						:min="1"
						class="nx-input" />
				</label>
				<div class="nx-field">
					<span>{{ t('nc_nexus', 'Calculated rank') }}</span>
					<strong>{{ dialog.item.computedRank }}</strong>
				</div>
			</div>
			<label class="nx-field" data-test="field-override-reason">
				<span>{{ t('nc_nexus', 'Reason') }} *</span>
				<textarea v-model="dialog.reason" class="nx-input" />
			</label>
			<template #actions>
				<NcButton v-if="dialog.item.rankOverride !== null"
					class="nx-dialog__left"
					variant="secondary"
					:disabled="busy"
					data-test="clear-override"
					@click="save(null)">
					{{ t('nc_nexus', 'Remove correction') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="busy" @click="dialog = null">
					{{ dialogDirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="busy || !dialog.rank || dialog.rank < 1 || !dialog.reason.trim()"
					data-test="save-override"
					@click="save(dialog.rank)">
					{{ t('nc_nexus', 'Save') }}
				</NcButton>
			</template>
		</NxDialog>
	</div>
</template>

<script>
import { mdiSwapVertical } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NxDialog from '../components/NxDialog.vue'
import NxNumberInput from '../components/NxNumberInput.vue'
import { capacityCut, errorMessage, getRanking, overrideRank } from '../api.js'
import { formatIsoDay, formatMonth, formatNumber, statusLabel } from '../labels.js'

export default {
	name: 'RankingView',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, NxDialog, NxNumberInput },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { mdiSwapVertical, n, t, formatIsoDay, formatNumber, statusLabel }
	},
	data() {
		return { buckets: [], loading: true, dialog: null, busy: false, cut: null, cutting: false }
	},
	computed: {
		total() {
			return this.buckets.reduce((sum, b) => sum + b.items.length, 0)
		},
		dialogDirty() {
			return this.dialog && (this.dialog.reason !== (this.dialog.item.overrideReason ?? '') || this.dialog.rank !== (this.dialog.item.rankOverride ?? this.dialog.item.rank))
		},
	},
	watch: {
		refreshKey: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.buckets = await getRanking()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		async runCut() {
			this.cutting = true
			try {
				this.cut = await capacityCut()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.cutting = false
			}
		},
		planChip(r) {
			return { startable: 'accepted', shifted: 'on-hold', waiting: 'rejected', budget: 'rejected', unassessed: 'draft' }[r.state] || 'draft'
		},
		planText(r) {
			switch (r.state) {
			case 'startable': return t('nc_nexus', 'startable')
			case 'shifted': return t('nc_nexus', 'startable from {month}', { month: formatMonth(r.start) })
			case 'waiting': return r.pool ? t('nc_nexus', 'waiting for pool {pool}', { pool: r.pool }) : t('nc_nexus', 'waiting')
			case 'budget': return t('nc_nexus', 'waiting for budget')
			default: return t('nc_nexus', 'not assessed yet')
			}
		},
		f2(value) {
			return value === null || value === undefined ? '–' : Number(value).toLocaleString(getCanonicalLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 })
		},
		// Selbst- und Fremdbewertung weichen um mehr als ein Viertel ab
		deviates(a) {
			return a.selfDensity !== null && a.reviewDensity !== null && Math.abs(a.selfDensity - a.reviewDensity) > 0.25 * Math.max(a.selfDensity, a.reviewDensity, 0.01)
		},
		openOverride(item) {
			this.dialog = { item, rank: item.rankOverride ?? item.rank, reason: item.overrideReason ?? '' }
		},
		async save(rank) {
			this.busy = true
			try {
				await overrideRank(this.dialog.item.id, rank, rank === null ? null : this.dialog.reason)
				showSuccess(rank === null ? t('nc_nexus', 'Correction removed') : t('nc_nexus', 'Rank overridden'))
				this.dialog = null
				await this.load()
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
.ranking {
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

	&__cut {
		margin-inline-start: auto;
	}

	&__count,
	&__intro,
	&__quota,
	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__intro {
		margin: 0 0 16px;
		font-size: 13px;
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 40px 0;
	}

	&__bucket {
		margin-bottom: 24px;
	}

	&__bucket-head {
		display: flex;
		align-items: baseline;
		gap: 12px;
		margin-bottom: 8px;

		h3 {
			margin: 0;
			font-size: 15px;
		}
	}

	&__quota {
		font-size: 13px;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}
}

.rank {
	display: grid;
	grid-template-columns: 70px minmax(0, 1fr) 100px 70px 80px 70px 60px 60px 190px 44px;
	align-items: center;
	gap: 10px;
	min-height: 52px;
	padding: 4px 4px 4px 12px;
	border-bottom: 1px solid var(--color-border);
	font-variant-numeric: tabular-nums;

	&:last-child {
		border-bottom: none;
	}

	&--head {
		min-height: 0;
		padding-top: 8px;
		padding-bottom: 8px;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&--unrated {
		color: var(--color-text-maxcontrast);
	}

	small {
		display: block;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__no strong {
		font-size: 16px;
	}

	&__name {
		min-width: 0;

		a {
			display: block;
			overflow: hidden;
			font-weight: 600;
			white-space: nowrap;
			text-overflow: ellipsis;
		}

		small {
			overflow: hidden;
			white-space: nowrap;
			text-overflow: ellipsis;
		}
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__missing {
		grid-column: span 5;
		font-style: italic;
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
	}
}
</style>
