<template>
	<div class="nx-view" data-test="postcalc-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Post-calculation') }}</h2>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Actual effort of completed projects compared with the original estimate (baseline 1 at G3), per pool and project class. The correction factor (actual ÷ estimate) is shown as experience value when new demands are estimated.') }}
		</p>

		<template v-if="loaded">
			<div class="nx-kpis">
				<div class="nx-kpi" data-test="postcalc-overall">
					<span class="nx-kpi__lab">{{ t('nc_nexus', 'Correction factor overall') }}</span>
					<span class="nx-kpi__num">{{ factorText(overall) }}</span>
					<span class="nx-kpi__sub">{{ t('nc_nexus', '{a} of {e} PD estimated', { a: formatNumber(overall.actual), e: formatNumber(overall.estimate) }) }}</span>
				</div>
				<div class="nx-kpi">
					<span class="nx-kpi__lab">{{ t('nc_nexus', 'Projects evaluated') }}</span>
					<span class="nx-kpi__num" data-test="postcalc-count">{{ overall.count }}</span>
					<span class="nx-kpi__sub">{{ t('nc_nexus', 'completed in the last {n} months', { n: settings.windowMonths }) }}</span>
				</div>
				<div class="nx-kpi">
					<span class="nx-kpi__lab">{{ t('nc_nexus', 'Valid from') }}</span>
					<span class="nx-kpi__num">{{ n('nc_nexus', '%n project', '%n projects', settings.minProjects) }}</span>
					<span class="nx-kpi__sub">{{ t('nc_nexus', 'per pool and class; adjustable in Governance') }}</span>
				</div>
			</div>

			<section class="nx-view__section">
				<div class="nx-view__section-head">
					<h3>{{ t('nc_nexus', 'Correction factors per pool and class') }}</h3>
				</div>
				<p v-if="!poolRows.length" class="nx-view__empty" data-test="postcalc-empty">
					{{ t('nc_nexus', 'No completed project with baseline and recorded actual effort in the time window yet.') }}
				</p>
				<div v-else class="nx-view__table">
					<table class="postcalc__table" data-test="postcalc-matrix">
						<thead>
							<tr>
								<th>{{ t('nc_nexus', 'Pool') }}</th>
								<th v-for="c in classes" :key="c">
									{{ t('nc_nexus', 'Class {c}', { c }) }}
								</th>
								<th>{{ t('nc_nexus', 'All classes') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in poolRows" :key="row.pool.id" :data-test="'postcalc-pool-' + row.pool.code">
								<th>{{ row.pool.name }}</th>
								<td v-for="c in classes"
									:key="c"
									:class="cellClass(row.entry.classes[c])"
									:title="cellTitle(row.entry.classes[c])">
									<template v-if="row.entry.classes[c]">
										{{ formatFactor(row.entry.classes[c].factor) }}
										<small>n={{ row.entry.classes[c].count }}</small>
									</template>
									<template v-else>
										–
									</template>
								</td>
								<td :class="cellClass(row.entry.all)" :title="cellTitle(row.entry.all)" :data-test="'postcalc-all-' + row.pool.code">
									{{ formatFactor(row.entry.all.factor) }}
									<small>n={{ row.entry.all.count }}</small>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<p class="nx-hint postcalc__legend">
					{{ t('nc_nexus', '100 % = estimate met; above 100 % the effort was underestimated. Pale values do not yet reach the minimum number of projects and are not offered as experience value.') }}
				</p>
			</section>

			<section class="nx-view__section">
				<div class="nx-view__section-head">
					<h3>{{ t('nc_nexus', 'Completed projects') }}</h3>
				</div>
				<p v-if="!projects.length" class="nx-view__empty">
					{{ t('nc_nexus', 'No completed projects yet.') }}
				</p>
				<div v-else class="nx-view__table">
					<div v-for="p in projects" :key="p.demandId" :data-test="'postcalc-project-' + p.demandId">
						<div class="nx-row nx-row--click postcalc__row"
							role="button"
							tabindex="0"
							@click="$emit('open', p.demandId)"
							@keydown.enter="$emit('open', p.demandId)">
							<span class="nx-row__name">
								<span class="nx-row__title">{{ p.projectKey || p.demandKey }} · {{ p.title }}</span>
								<span class="nx-row__meta">
									{{ [p.projectClass ? t('nc_nexus', 'Class {c}', { c: p.projectClass }) : null, formatDay(p.completedAt), reason(p)].filter(Boolean).join(' · ') }}
								</span>
							</span>
							<span class="postcalc__num">{{ t('nc_nexus', '{a} / {e} PD', { a: formatNumber(p.actual), e: formatNumber(p.estimate) }) }}</span>
							<span class="postcalc__factor" :class="cellClass({ factor: p.factor, valid: p.counted })">{{ formatFactor(p.factor) }}</span>
						</div>
						<div v-for="r in p.pools" :key="r.poolId" class="nx-row nx-row--sub postcalc__row">
							<span class="nx-row__muted">{{ poolName(r.poolId) }}</span>
							<span class="postcalc__num">
								{{ t('nc_nexus', '{a} / {e} PD', { a: formatNumber(r.actual), e: formatNumber(r.estimate) }) }}
								<small v-if="r.current !== r.estimate">{{ t('nc_nexus', '(current baseline {c})', { c: formatNumber(r.current) }) }}</small>
							</span>
							<span class="postcalc__factor">{{ r.factor === null ? t('nc_nexus', 'unplanned') : formatFactor(r.factor) }}</span>
						</div>
					</div>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { errorMessage, getPostCalculation } from '../api.js'
import { formatFactor, formatNumber } from '../labels.js'

export default {
	name: 'PostCalculationView',
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { n, t, formatFactor, formatNumber }
	},
	data() {
		return {
			loaded: false,
			settings: { windowMonths: 24, minProjects: 3 },
			pools: [],
			projects: [],
			byPool: {},
			overall: { estimate: 0, actual: 0, count: 0, factor: null, valid: false },
			classes: ['XS', 'S', 'M', 'L'],
		}
	},
	computed: {
		poolRows() {
			return this.pools
				.filter(p => this.byPool[p.id])
				.map(p => ({ pool: p, entry: { classes: {}, ...this.byPool[p.id] } }))
		},
	},
	watch: {
		refreshKey() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				const result = await getPostCalculation()
				this.settings = result.settings
				this.pools = result.pools
				this.projects = result.projects
				// PHP liefert leere Objekte als Array
				this.byPool = Array.isArray(result.byPool) ? {} : result.byPool
				this.overall = result.overall
				this.loaded = true
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		poolName(id) {
			return this.pools.find(p => p.id === id)?.name ?? '?'
		},
		factorText(cell) {
			return cell.factor === null ? '–' : formatFactor(cell.factor)
		},
		cellClass(cell) {
			if (!cell || cell.factor === null) {
				return 'postcalc__cell--none'
			}
			const level = cell.factor > 1.3 || cell.factor < 0.7 ? 'red' : (cell.factor > 1.15 || cell.factor < 0.85 ? 'yellow' : 'green')
			return ['postcalc__cell--' + level, { 'postcalc__cell--weak': !cell.valid }]
		},
		cellTitle(cell) {
			if (!cell) {
				return ''
			}
			return t('nc_nexus', '{a} PD actual for {e} PD estimated in {n} projects', { a: formatNumber(cell.actual), e: formatNumber(cell.estimate), n: cell.count })
		},
		formatDay(ts) {
			return ts ? new Date(ts * 1000).toLocaleDateString() : ''
		},
		reason(p) {
			if (!p.inWindow) {
				return t('nc_nexus', 'outside the time window')
			}
			if (!p.counted) {
				return t('nc_nexus', 'no actual effort recorded')
			}
			return null
		},
	},
}
</script>

<style scoped lang="scss">
.postcalc__table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;

	th, td {
		padding: 10px 12px;
		text-align: start;
		border-bottom: 1px solid var(--color-border);
	}

	thead th {
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	tbody tr:last-child th,
	tbody tr:last-child td {
		border-bottom: none;
	}

	small {
		margin-inline-start: 4px;
		color: var(--color-text-maxcontrast);
	}
}

.postcalc__legend {
	margin-top: 8px;
}

.postcalc__row {
	grid-template-columns: minmax(0, 1fr) 220px 80px;
}

.postcalc__num,
.postcalc__factor {
	font-variant-numeric: tabular-nums;
	text-align: end;
}

.postcalc__factor {
	padding-inline-end: 12px;
	font-weight: 600;
}

.postcalc__cell--green {
	color: var(--color-success-text);
}

.postcalc__cell--yellow {
	color: var(--color-warning-text);
}

.postcalc__cell--red {
	color: var(--color-error-text);
}

.postcalc__cell--none {
	color: var(--color-text-maxcontrast);
}

.postcalc__cell--weak {
	opacity: 0.55;
}
</style>
