<template>
	<div class="capacity" data-test="capacity-view">
		<div class="capacity__header">
			<h2>{{ t('nc_nexus', 'Capacity') }}</h2>
			<span class="capacity__count">{{ n('nc_nexus', '%n pool', '%n pools', rows.length) }}</span>
			<div class="capacity__actions">
				<label class="capacity__from">
					<span>{{ t('nc_nexus', 'from') }}</span>
					<input v-model="from"
						type="month"
						class="nx-input"
						data-test="capacity-from"
						@change="load">
				</label>
				<NcButton v-if="canManage"
					variant="secondary"
					data-test="new-pool"
					@click="openPool(null)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New pool') }}
				</NcButton>
			</div>
		</div>
		<p class="capacity__intro">
			{{ t('nc_nexus', 'Load = person days needed by submitted, open demands ÷ project capacity of the pool. Drafts and deferred demands do not count. Click a cell to see the demands behind it.') }}
		</p>

		<div v-if="loading" class="capacity__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="!rows.length"
			:name="t('nc_nexus', 'No resource pools yet')"
			:description="canManage ? t('nc_nexus', 'Create the pools (skill or role groups) whose capacity you want to plan.') : t('nc_nexus', 'The portfolio office creates the pools.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAccountGroup" />
			</template>
		</NcEmptyContent>
		<div v-else class="capacity__scroll">
			<table class="heatmap" data-test="heatmap">
				<thead>
					<tr>
						<th class="heatmap__pool">
							{{ t('nc_nexus', 'Pool') }}
						</th>
						<th v-for="period in periods" :key="period">
							{{ formatMonth(period) }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.pool.id" :data-test="'heatmap-' + row.pool.code">
						<th class="heatmap__pool">
							<a href="#" @click.prevent="openPool(row.pool)">{{ row.pool.name }}</a>
							<span class="heatmap__code">{{ row.pool.code }}</span>
							<span v-if="row.pool.isBottleneck === 1" class="heatmap__flag">{{ t('nc_nexus', 'bottleneck') }}</span>
						</th>
						<td v-for="cell in row.cells" :key="cell.period">
							<button type="button"
								class="heatmap__cell"
								:class="['heatmap__cell--' + loadLevel(cell), { 'heatmap__cell--active': isSelected(row, cell) }]"
								:data-test="'cell-' + row.pool.code + '-' + cell.period"
								:aria-label="cellLabel(row, cell)"
								@click="select(row, cell)">
								<strong>{{ cell.load === null ? '!' : cell.load + ' %' }}</strong>
								<small>{{ formatNumber(cell.demand) }} / {{ formatNumber(cell.capacity) }}</small>
							</button>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<section v-if="selected" class="capacity__detail" data-test="cell-detail">
			<h3>{{ selected.pool.name }} · {{ formatMonth(selected.period) }}</h3>
			<p class="nx-hint">
				{{ t('nc_nexus', '{demand} PD needed of {capacity} PD project capacity', { demand: formatNumber(selected.cell.demand), capacity: formatNumber(selected.cell.capacity) }) }}
			</p>
			<NcLoadingIcon v-if="detailLoading" :size="24" />
			<template v-else-if="detail">
				<ul v-if="detail.demands.length" class="capacity__list">
					<li v-for="d in detail.demands" :key="d.id">
						<a href="#" @click.prevent="$emit('open', d.id)">{{ d.key }} · {{ d.title }}</a>
						<span>{{ formatNumber(d.personDays) }} {{ t('nc_nexus', 'PD') }}</span>
					</li>
				</ul>
				<p v-if="detail.otherDays > 0" class="nx-hint">
					{{ t('nc_nexus', 'Further {days} PD from demands you cannot see.', { days: formatNumber(detail.otherDays) }) }}
				</p>
				<p v-if="!detail.demands.length && !detail.otherDays" class="nx-hint">
					{{ t('nc_nexus', 'No demand in this month.') }}
				</p>
			</template>
		</section>

		<PoolDialog v-if="poolDialog"
			:key="poolDialog.key"
			:pool="poolDialog.pool"
			:org-units="orgUnits"
			:can-manage="canManage"
			@close="poolDialog = null"
			@created="openPool"
			@changed="load" />
	</div>
</template>

<script>
import { mdiAccountGroup, mdiPlus } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PoolDialog from '../components/PoolDialog.vue'
import { capacityHeatmap, errorMessage, listPools, poolLoad } from '../api.js'
import { formatMonth, formatNumber, loadLevel, thisMonth } from '../labels.js'

export default {
	name: 'CapacityView',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, PoolDialog },
	props: {
		orgUnits: { type: Array, default: () => [] },
		/** Zähler von außen erhöhen, um neu zu laden */
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { mdiAccountGroup, mdiPlus, n, t, formatMonth, formatNumber, loadLevel }
	},
	data() {
		return {
			from: thisMonth(),
			periods: [],
			rows: [],
			canManage: false,
			loading: true,
			selected: null,
			detail: null,
			detailLoading: false,
			poolDialog: null,
			dialogCounter: 0,
		}
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
				const [heatmap, pools] = await Promise.all([capacityHeatmap(this.from || thisMonth(), 12), listPools()])
				this.periods = heatmap.periods
				this.rows = heatmap.pools
				this.canManage = pools.canManage
				if (this.selected) {
					const row = this.rows.find(r => r.pool.id === this.selected.pool.id)
					const cell = row?.cells.find(c => c.period === this.selected.period)
					this.selected = row && cell ? { ...this.selected, cell } : null
				}
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		openPool(pool) {
			this.poolDialog = { pool, key: ++this.dialogCounter }
		},
		isSelected(row, cell) {
			return this.selected?.pool.id === row.pool.id && this.selected?.period === cell.period
		},
		cellLabel(row, cell) {
			return t('nc_nexus', '{pool}, {month}: {demand} of {capacity} person days', {
				pool: row.pool.name, month: formatMonth(cell.period), demand: formatNumber(cell.demand), capacity: formatNumber(cell.capacity),
			})
		},
		async select(row, cell) {
			this.selected = { pool: row.pool, period: cell.period, cell }
			this.detail = null
			this.detailLoading = true
			try {
				this.detail = await poolLoad(row.pool.id, cell.period)
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.detailLoading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.capacity {
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

	&__actions {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-inline-start: auto;
	}

	&__from {
		display: flex;
		align-items: center;
		gap: 6px;
		color: var(--color-text-maxcontrast);

		.nx-input.nx-input {
			width: 170px;
		}
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 40px 0;
	}

	&__scroll {
		overflow-x: auto;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
	}

	&__detail {
		margin-top: 18px;
		padding: 12px 16px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);

		h3 {
			margin: 0 0 4px;
			font-size: 15px;
		}
	}

	&__list {
		margin: 8px 0 0;
		padding: 0;
		list-style: none;

		li {
			display: flex;
			justify-content: space-between;
			gap: 12px;
			padding: 4px 0;
			border-bottom: 1px solid var(--color-border-light, var(--color-border));
		}

		span {
			font-variant-numeric: tabular-nums;
			white-space: nowrap;
		}
	}
}

.heatmap {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;

	th, td {
		padding: 4px;
		border-bottom: 1px solid var(--color-border);
		text-align: center;
	}

	thead th {
		padding: 10px 4px;
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__pool {
		position: sticky;
		left: 0;
		min-width: 200px;
		padding: 8px 12px !important;
		background: var(--color-main-background);
		text-align: start !important;

		a {
			display: block;
			font-weight: 600;
		}
	}

	&__code {
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__flag {
		margin-inline-start: 6px;
		padding: 0 6px;
		border-radius: var(--border-radius-pill);
		background: rgba(var(--color-warning-rgb), 0.14);
		color: var(--color-warning-text);
		font-size: 11px;
	}

	&__cell {
		display: flex;
		flex-direction: column;
		align-items: center;
		width: 100%;
		min-width: 74px;
		min-height: 0;
		margin: 0;
		padding: 6px 4px;
		border: 2px solid transparent;
		border-radius: var(--border-radius, 8px);
		background: transparent;
		color: var(--color-main-text);
		font-weight: normal;
		cursor: pointer;

		strong {
			font-size: 14px;
		}

		small {
			font-size: 11px;
			color: var(--color-text-maxcontrast);
			white-space: nowrap;
		}

		&--ok {
			background: rgba(var(--color-success-rgb), 0.12);
		}

		&--high {
			background: rgba(var(--color-warning-rgb), 0.2);
		}

		&--over {
			background: rgba(var(--color-error-rgb), 0.18);
			color: var(--color-error-text);
		}

		&--active {
			border-color: var(--color-primary-element);
		}

		&:hover,
		&:focus-visible {
			border-color: var(--color-border-maxcontrast, var(--color-border-dark));
		}
	}
}
</style>
