<template>
	<div class="benefits" data-test="benefit-register">
		<div class="benefits__header">
			<h2>{{ t('nc_nexus', 'Benefit register') }}</h2>
			<span class="benefits__count">{{ n('nc_nexus', '%n benefit item', '%n benefit items', rows.length) }} · {{ t('nc_nexus', '{sum} p. a.', { sum: formatMoney(total) }) }}</span>
		</div>
		<p class="benefits__intro">
			{{ t('nc_nexus', 'The portfolio-wide view of promised benefit: all benefit items of the business cases you may see. The benefit owner stays responsible after the project ends.') }}
		</p>

		<div v-if="rows.length" class="nx-kpis" data-test="benefit-balance">
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					{{ t('nc_nexus', 'Committed benefit') }}
				</div>
				<div class="nx-kpi__num">
					{{ formatMoney(balance.total.committed) }}
				</div>
				<div class="nx-kpi__sub">
					{{ t('nc_nexus', 'per year, released projects') }}
				</div>
			</div>
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					{{ t('nc_nexus', 'Forecast') }}
				</div>
				<div class="nx-kpi__num" data-test="balance-forecast">
					{{ formatMoney(balance.total.forecast) }}
				</div>
				<div class="nx-kpi__sub">
					{{ t('nc_nexus', 'per year, by the benefit owners') }}
				</div>
			</div>
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					{{ t('nc_nexus', 'Realized') }}
				</div>
				<div class="nx-kpi__num" data-test="balance-realized">
					{{ formatMoney(balance.total.realized) }}
				</div>
				<div class="nx-kpi__sub">
					{{ balance.total.reviewedCommitted
						? t('nc_nexus', '{pct} of {sum} reviewed', { pct: formatFactor(balance.total.realized / balance.total.reviewedCommitted), sum: formatMoney(balance.total.reviewedCommitted) })
						: t('nc_nexus', 'no review yet') }}
				</div>
			</div>
			<button type="button"
				class="nx-kpi nx-kpi--button"
				:class="{ 'nx-kpi--pressed': filter === 'due' }"
				:aria-pressed="filter === 'due' ? 'true' : 'false'"
				data-test="filter-due"
				@click="toggle('due')">
				<span class="nx-kpi__lab">{{ t('nc_nexus', 'Reviews due') }}</span>
				<span class="nx-kpi__num">{{ dueCount }}</span>
				<span class="nx-kpi__sub">{{ statusSummary }}</span>
			</button>
		</div>

		<div v-if="rows.length" class="benefits__filters">
			<button type="button"
				class="nx-chip benefits__filter"
				:class="{ 'benefits__filter--on': filter === 'mine' }"
				:aria-pressed="filter === 'mine' ? 'true' : 'false'"
				data-test="filter-mine"
				@click="toggle('mine')">
				{{ t('nc_nexus', 'My benefit items') }}
			</button>
			<span v-if="factorList.length" class="benefits__factors" data-test="benefit-factors">
				{{ t('nc_nexus', 'Realization so far:') }}
				<span v-for="f in factorList" :key="f.type">{{ benefitTypeLabel(f.type) }} {{ formatFactor(f.factor) }} ({{ f.count }})</span>
			</span>
		</div>

		<NcEmptyContent v-if="!loading && !rows.length" :name="t('nc_nexus', 'No benefit items yet')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCashMultiple" />
			</template>
		</NcEmptyContent>
		<div v-else-if="rows.length" class="benefits__table">
			<div class="benefit benefit--head" aria-hidden="true">
				<span>{{ t('nc_nexus', 'Project') }}</span>
				<span>{{ t('nc_nexus', 'Benefit item') }}</span>
				<span>{{ t('nc_nexus', 'Per year') }}</span>
				<span>{{ t('nc_nexus', 'Metric: baseline → target') }}</span>
				<span>{{ t('nc_nexus', 'Status') }}</span>
				<span>{{ t('nc_nexus', 'Next review') }}</span>
				<span>{{ t('nc_nexus', 'Benefit owner') }}</span>
			</div>
			<p v-if="!visible.length" class="benefits__none">
				{{ t('nc_nexus', 'No benefit items match the filter.') }}
			</p>
			<div v-for="r in visible"
				:key="r.demandId + ':' + r.key"
				class="benefit benefit--click"
				role="button"
				tabindex="0"
				:data-test="'benefit-row-' + r.demandKey"
				@click="selected = r"
				@keydown.enter="selected = r">
				<span class="benefit__demand">
					<a v-if="r.readable" href="#" @click.prevent.stop="$emit('open', r.demandId)">{{ r.demandKey }}</a>
					<span v-else>{{ r.demandKey }}</span>
					<small>{{ r.demandTitle }}</small>
				</span>
				<span>
					<strong>{{ r.title }}</strong>
					<small>{{ benefitTypeLabel(r.type) }}</small>
				</span>
				<span class="benefit__amount">
					{{ r.amount === null ? '–' : formatMoney(r.amount) }}
					<small v-if="r.forecastAmount !== null && r.forecastAmount !== r.amount">{{ t('nc_nexus', 'forecast {a}', { a: formatMoney(r.forecastAmount) }) }}</small>
				</span>
				<span>
					{{ r.metric }}
					<small>{{ r.baseline }} → {{ r.target || '?' }}{{ r.forecast ? ' · ' + t('nc_nexus', 'forecast {f}', { f: r.forecast }) : '' }}</small>
				</span>
				<span>
					<span v-if="r.committed" class="nx-chip" :class="realizationStatusChip(r.realizationStatus)">{{ realizationStatusLabel(r.realizationStatus) }}</span>
					<small v-else>{{ t('nc_nexus', 'not yet released') }}</small>
				</span>
				<span :class="{ 'benefit__due': r.reviewDue }">
					{{ r.nextReview || (r.reviews.length ? t('nc_nexus', 'all reviewed') : '–') }}
					<small v-if="r.reviewDue">{{ t('nc_nexus', 'due') }}</small>
				</span>
				<span>{{ r.ownerUserId }}</span>
			</div>
		</div>

		<BenefitDialog v-if="selected"
			:row="selected"
			:factors="factors"
			@changed="load"
			@close="selected = null" />
	</div>
</template>

<script>
import { mdiCashMultiple } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import BenefitDialog from '../components/BenefitDialog.vue'
import { benefitRegister, errorMessage } from '../api.js'
import { benefitTypeLabel, formatFactor, formatMoney, realizationStatusChip, realizationStatusLabel } from '../labels.js'

export default {
	name: 'BenefitRegister',
	components: { BenefitDialog, NcEmptyContent, NcIconSvgWrapper },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { mdiCashMultiple, n, t, benefitTypeLabel, formatFactor, formatMoney, realizationStatusChip, realizationStatusLabel }
	},
	data() {
		return {
			rows: [],
			balance: { total: {}, statuses: {} },
			factors: null,
			loading: true,
			filter: null,
			selected: null,
			uid: getCurrentUser()?.uid ?? '',
		}
	},
	computed: {
		total() {
			return this.rows.reduce((s, r) => s + (r.amount || 0), 0)
		},
		visible() {
			if (this.filter === 'due') {
				return this.rows.filter(r => r.reviewDue)
			}
			if (this.filter === 'mine') {
				return this.rows.filter(r => r.ownerUserId === this.uid)
			}
			return this.rows
		},
		dueCount() {
			return this.rows.filter(r => r.reviewDue).length
		},
		statusSummary() {
			const s = this.balance.statuses || {}
			return [
				s.at_risk ? t('nc_nexus', '{n} at risk', { n: s.at_risk }) : null,
				s.missed ? t('nc_nexus', '{n} missed', { n: s.missed }) : null,
				s.realized ? t('nc_nexus', '{n} realized', { n: s.realized }) : null,
			].filter(Boolean).join(' · ') || t('nc_nexus', 'no deviations')
		},
		factorList() {
			const byType = this.factors?.byType || {}
			return Object.keys(byType).map(type => ({ type, ...byType[type] }))
		},
	},
	watch: {
		refreshKey: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		toggle(f) {
			this.filter = this.filter === f ? null : f
		},
		async load() {
			try {
				const result = await benefitRegister()
				this.rows = result.rows
				this.balance = result.balance
				this.factors = result.factors
				if (this.selected) {
					this.selected = this.rows.find(r => r.demandId === this.selected.demandId && r.key === this.selected.key) || this.selected
				}
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.benefits {
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
	&__factors,
	&__none {
		color: var(--color-text-maxcontrast);
	}

	&__intro {
		margin: 0 0 16px;
		font-size: 13px;
	}

	&__filters {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
		margin-bottom: 12px;
	}

	&__filter {
		cursor: pointer;
		border: 1px solid var(--color-border);
		background: var(--color-main-background);

		&--on {
			background: var(--color-primary-element-light);
			border-color: var(--color-primary-element);
		}
	}

	&__factors {
		display: flex;
		flex-wrap: wrap;
		gap: 10px;
		font-size: 13px;
	}

	&__none {
		margin: 0;
		padding: 12px;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}
}

.benefit {
	display: grid;
	grid-template-columns: 170px minmax(0, 1fr) 120px minmax(0, 1fr) 120px 110px 110px;
	align-items: center;
	gap: 12px;
	min-height: 52px;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&--head {
		min-height: 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&--click {
		cursor: pointer;

		&:hover,
		&:focus-visible {
			background: var(--color-background-hover);
		}
	}

	small {
		display: block;
		overflow: hidden;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	&__amount {
		font-variant-numeric: tabular-nums;
		text-align: end;
	}

	&__due {
		color: var(--color-warning-text, var(--color-warning));
		font-weight: 600;
	}
}
</style>
