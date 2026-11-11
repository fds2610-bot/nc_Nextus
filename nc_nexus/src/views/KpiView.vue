<template>
	<div class="nx-view" data-test="kpi-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Key figures') }}</h2>
			<span class="nx-view__spacer" />
			<NcButton v-if="canManage"
				variant="secondary"
				data-test="new-kpi"
				@click="edit(null)">
				{{ t('nc_nexus', 'New key figure') }}
			</NcButton>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Every key figure has a definition, a data source, an owner and thresholds for the traffic light. A traffic light without a traceable threshold is not a steering instrument.') }}
		</p>
		<div class="kpi__grid">
			<button v-for="k in kpis.filter(x => x.active)"
				:key="k.id"
				type="button"
				class="kpi__tile"
				:class="'kpi__tile--' + (k.level || 'none')"
				:disabled="!canManage"
				:data-test="'kpi-' + k.code"
				:title="k.definition || ''"
				@click="edit(k)">
				<span class="kpi__name">{{ k.name }}</span>
				<span class="kpi__value">{{ k.value === null ? (k.computed ? '–' : t('nc_nexus', 'external')) : formatNumber(k.value) + (k.unit ? ' ' + k.unit : '') }}</span>
				<span class="kpi__meta">
					{{ k.direction === 'info'
						? t('nc_nexus', 'monitored')
						: (k.direction === 'up'
							? t('nc_nexus', 'target at least {green}, red below {red}', { green: formatNumber(k.thresholdGreen), red: formatNumber(k.thresholdRed) })
							: t('nc_nexus', 'target at most {green}, red above {red}', { green: formatNumber(k.thresholdGreen), red: formatNumber(k.thresholdRed) })) }}
				</span>
				<span class="kpi__meta">{{ k.dataSource }}{{ k.ownerId ? ' · ' + k.ownerId : '' }}</span>
			</button>
		</div>
		<FormDialog v-if="form"
			:key="form.key"
			v-bind="form.props"
			@close="form = null"
			@saved="onSaved" />
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import FormDialog from '../components/FormDialog.vue'
import { addKpi, errorMessage, listKpis, updateKpi } from '../api.js'
import { formatNumber } from '../labels.js'

export default {
	name: 'KpiView',
	components: { FormDialog, NcButton },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	setup() {
		return { t, formatNumber }
	},
	data() {
		return { kpis: [], canManage: false, form: null, key: 0 }
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
				const result = await listKpis()
				this.kpis = result.kpis
				this.canManage = result.canManage
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		onSaved(result) {
			this.kpis = result.kpis
		},
		edit(k) {
			const directions = [
				{ value: 'up', label: t('nc_nexus', 'higher is better') },
				{ value: 'down', label: t('nc_nexus', 'lower is better') },
				{ value: 'info', label: t('nc_nexus', 'monitored only') },
			]
			this.form = {
				key: ++this.key,
				props: {
					title: k ? k.name : t('nc_nexus', 'New key figure'),
					subtitle: k && k.computed ? t('nc_nexus', 'Calculated by Nexus; you can adjust name, definition, owner and thresholds.') : t('nc_nexus', 'Key figures without calculation are shown as external.'),
					dataTest: 'kpi-dialog',
					initial: k ? { ...k, active: k.active === 1 } : { direction: 'up', active: true },
					fields: [
						{ key: 'name', label: t('nc_nexus', 'Name'), required: true, wide: true },
						{ key: 'definition', label: t('nc_nexus', 'Definition'), type: 'textarea' },
						{ key: 'dataSource', label: t('nc_nexus', 'Data source'), wide: true },
						{ key: 'direction', label: t('nc_nexus', 'Direction'), type: 'select', options: directions },
						{ key: 'unit', label: t('nc_nexus', 'Unit') },
						{ key: 'thresholdGreen', label: t('nc_nexus', 'Threshold green'), type: 'number', show: (v) => v.direction !== 'info' },
						{ key: 'thresholdRed', label: t('nc_nexus', 'Threshold red'), type: 'number', show: (v) => v.direction !== 'info' },
						{ key: 'ownerId', label: t('nc_nexus', 'Owner'), type: 'user' },
						{ key: 'active', label: t('nc_nexus', 'active'), type: 'checkbox' },
					],
					submit: (v) => k ? updateKpi(k.id, v) : addKpi(v),
					successText: t('nc_nexus', 'Key figure saved'),
				},
			}
		},
	},
}
</script>

<style scoped lang="scss">
.kpi__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 12px;
}

.kpi__tile {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: flex-start;
	min-height: 130px;
	margin: 0;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-inline-start-width: 6px;
	border-radius: var(--border-radius-container-large, 12px);
	background: var(--color-main-background);
	font-weight: normal;
	text-align: start;
	white-space: normal;
	cursor: pointer;

	&:disabled {
		cursor: default;
		opacity: 1;
	}

	&--green {
		border-inline-start-color: var(--color-element-success, var(--color-success-text));
	}

	&--yellow {
		border-inline-start-color: var(--color-element-warning, var(--color-warning-text));
	}

	&--red {
		border-inline-start-color: var(--color-element-error, var(--color-error-text));
	}
}

.kpi__name {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.kpi__value {
	font-size: 26px;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.kpi__meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
