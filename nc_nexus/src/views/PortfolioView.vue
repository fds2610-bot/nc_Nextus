<template>
	<div class="portfolio" data-test="portfolio-view">
		<div class="portfolio__header">
			<h2>{{ t('nc_nexus', 'Portfolio') }}</h2>
			<span v-if="data" class="portfolio__count">{{ n('nc_nexus', '%n project or demand', '%n projects or demands', data.totals.total) }}</span>
		</div>
		<p class="portfolio__intro">
			{{ t('nc_nexus', 'Demands and projects you may see, by organizational unit and portfolio phase. Parent units include their subunits. Click a number to open the list.') }}
		</p>

		<div v-if="loading" class="portfolio__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<template v-else-if="data">
			<section class="portfolio__section">
				<h3>{{ t('nc_nexus', 'By portfolio phase') }}</h3>
				<div class="portfolio__scroll">
					<table class="grid" data-test="portfolio-phases">
						<thead>
							<tr>
								<th class="grid__unit">
									{{ t('nc_nexus', 'Organizational unit') }}
								</th>
								<th v-for="phase in data.phases" :key="phase" :class="{ 'grid__future': isFuture(phase) }">
									{{ phaseLabel(phase) }}
									<small v-if="isFuture(phase)">{{ t('nc_nexus', 'with projects') }}</small>
								</th>
								<th>{{ t('nc_nexus', 'Total') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in rows"
								:key="row.key"
								:class="{ 'grid__row--empty': !row.total }"
								:data-test="'portfolio-row-' + row.name">
								<th class="grid__unit" :style="{ paddingInlineStart: (12 + row.depth * 18) + 'px' }">
									{{ row.name }}
								</th>
								<td v-for="phase in data.phases" :key="phase" :class="{ 'grid__future': isFuture(phase) }">
									<a v-if="row.phases[phase].count"
										href="#"
										:data-test="'portfolio-cell-' + row.name + '-' + phase"
										@click.prevent="$emit('filter', { orgUnitId: row.orgUnitId, phase, label: row.name })">
										<strong>{{ row.phases[phase].count }}</strong>
									</a>
									<span v-else class="grid__zero">0</span>
									<small v-if="row.phases[phase].days">{{ formatNumber(row.phases[phase].days) }} {{ t('nc_nexus', 'PD') }}</small>
								</td>
								<td><strong>{{ row.total }}</strong></td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th class="grid__unit">
									{{ t('nc_nexus', 'Total') }}
								</th>
								<td v-for="phase in data.phases" :key="phase" :class="{ 'grid__future': isFuture(phase) }">
									<strong>{{ data.totals.phases[phase].count }}</strong>
									<small v-if="data.totals.phases[phase].days">{{ formatNumber(data.totals.phases[phase].days) }} {{ t('nc_nexus', 'PD') }}</small>
								</td>
								<td><strong>{{ data.totals.total }}</strong></td>
							</tr>
						</tfoot>
					</table>
				</div>
			</section>

			<section class="portfolio__section">
				<h3>{{ t('nc_nexus', 'Open resource needs by quarter') }}</h3>
				<p class="nx-hint">
					{{ t('nc_nexus', 'Person days of the demands in the phase “Demand”, by the month they are needed in.') }}
				</p>
				<div class="portfolio__scroll">
					<table class="grid" data-test="portfolio-quarters">
						<thead>
							<tr>
								<th class="grid__unit">
									{{ t('nc_nexus', 'Organizational unit') }}
								</th>
								<th v-for="q in data.quarters" :key="q">
									{{ quarterLabel(q) }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in rows" :key="row.key" :class="{ 'grid__row--empty': !rowDays(row) }">
								<th class="grid__unit" :style="{ paddingInlineStart: (12 + row.depth * 18) + 'px' }">
									{{ row.name }}
								</th>
								<td v-for="q in data.quarters" :key="q">
									{{ row.quarters[q] ? formatNumber(row.quarters[q]) + ' ' + t('nc_nexus', 'PD') : '–' }}
								</td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th class="grid__unit">
									{{ t('nc_nexus', 'Total') }}
								</th>
								<td v-for="q in data.quarters" :key="q">
									<strong>{{ formatNumber(data.totals.quarters[q]) }} {{ t('nc_nexus', 'PD') }}</strong>
								</td>
							</tr>
						</tfoot>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { errorMessage, portfolioOverview } from '../api.js'
import { FUTURE_PHASES, formatNumber, orgTree, phaseLabel } from '../labels.js'

export default {
	name: 'PortfolioView',
	components: { NcLoadingIcon },
	props: {
		orgUnits: { type: Array, default: () => [] },
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['filter'],
	setup() {
		return { n, t, formatNumber, phaseLabel }
	},
	data() {
		return { data: null, loading: true }
	},
	computed: {
		// Zeilen in Baum-Reihenfolge, dazu „ohne Org-Einheit“
		rows() {
			if (!this.data) {
				return []
			}
			const byUnit = new Map(this.data.rows.map(r => [r.orgUnitId, r]))
			const rows = orgTree(this.orgUnits)
				.filter(u => byUnit.has(u.id))
				.map(u => ({ ...byUnit.get(u.id), key: u.id, name: u.name, depth: u.depth }))
			const none = byUnit.get(null)
			if (none) {
				rows.push({ ...none, key: 'none', name: t('nc_nexus', 'without organizational unit'), depth: 0 })
			}
			return rows
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
				this.data = await portfolioOverview()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		isFuture(phase) {
			return FUTURE_PHASES.includes(phase)
		},
		quarterLabel(q) {
			const [year, quarter] = q.split('-')
			return `${quarter} ${year}`
		},
		rowDays(row) {
			return Object.values(row.quarters).some(v => v > 0)
		},
	},
}
</script>

<style scoped lang="scss">
.portfolio {
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
	&__intro {
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

	&__section {
		margin-bottom: 24px;

		h3 {
			margin: 0 0 8px;
			font-size: 12px;
			font-weight: 700;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
	}

	&__scroll {
		overflow-x: auto;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
	}
}

.grid {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;

	th, td {
		padding: 8px 10px;
		border-bottom: 1px solid var(--color-border);
		text-align: center;
		white-space: nowrap;
	}

	thead th {
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);

		small {
			display: block;
			font-size: 11px;
		}
	}

	td small {
		display: block;
		font-size: 11px;
		color: var(--color-text-maxcontrast);
	}

	tfoot th, tfoot td {
		border-bottom: none;
		background: var(--color-background-hover);
	}

	&__unit {
		min-width: 220px;
		text-align: start !important;
		font-weight: 600;
	}

	&__future {
		background: var(--color-background-dark);
		opacity: 0.7;
	}

	&__zero {
		color: var(--color-text-maxcontrast);
	}

	&__row--empty {
		color: var(--color-text-maxcontrast);
	}

	a {
		display: inline-block;
		min-width: 28px;
		padding: 2px 6px;
		border-radius: var(--border-radius, 8px);

		&:hover,
		&:focus-visible {
			background: var(--color-primary-element-light);
		}
	}
}
</style>
