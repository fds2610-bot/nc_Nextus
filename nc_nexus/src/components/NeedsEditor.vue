<template>
	<div class="nx-section" data-test="resource-needs">
		<h3>{{ t('nc_nexus', 'Resource needs') }}</h3>
		<p v-if="!readonly" class="nx-hint needs__hint">
			{{ t('nc_nexus', 'Rough estimate in person days per pool and month. It is saved together with the demand.') }}
		</p>
		<p v-if="!pools.length" class="nx-hint">
			{{ t('nc_nexus', 'No resource pools have been set up yet.') }}
		</p>
		<template v-else>
			<p v-if="readonly && !modelValue.length" class="needs__empty">
				{{ t('nc_nexus', 'No resource needs recorded.') }}
			</p>
			<table v-if="modelValue.length" class="needs__table">
				<thead>
					<tr>
						<th>{{ t('nc_nexus', 'Pool') }}</th>
						<th>{{ t('nc_nexus', 'Month') }}</th>
						<th>{{ t('nc_nexus', 'Person days') }}</th>
						<th v-if="!readonly" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, i) in modelValue" :key="i" :data-test="'need-' + i">
						<td>
							<select v-if="!readonly"
								:value="row.poolId"
								class="nx-input"
								:aria-label="t('nc_nexus', 'Pool')"
								@change="update(i, 'poolId', Number($event.target.value))">
								<option v-for="pool in pools" :key="pool.id" :value="pool.id">
									{{ pool.name }}{{ pool.isBottleneck === 1 ? ' · ' + t('nc_nexus', 'bottleneck') : '' }}
								</option>
							</select>
							<span v-else>{{ poolName(row.poolId) }}</span>
						</td>
						<td>
							<input v-if="!readonly"
								:value="row.period"
								type="month"
								class="nx-input"
								:aria-label="t('nc_nexus', 'Month')"
								@input="update(i, 'period', $event.target.value)">
							<span v-else>{{ formatMonth(row.period) }}</span>
						</td>
						<td>
							<input v-if="!readonly"
								:value="row.personDays"
								type="number"
								min="0"
								step="0.5"
								class="nx-input needs__days"
								:aria-label="t('nc_nexus', 'Person days')"
								@input="update(i, 'personDays', $event.target.value === '' ? '' : Number($event.target.value))">
							<span v-else>{{ formatNumber(row.personDays) }}</span>
						</td>
						<td v-if="!readonly">
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Remove row')"
								@click="remove(i)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="2">
							{{ t('nc_nexus', 'Total') }}
						</th>
						<th>{{ formatNumber(total) }} {{ t('nc_nexus', 'PD') }}</th>
						<th v-if="!readonly" />
					</tr>
				</tfoot>
			</table>
			<NcButton v-if="!readonly"
				variant="tertiary"
				data-test="add-need"
				@click="add">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('nc_nexus', 'Add need') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { mdiClose, mdiPlus } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { formatMonth, formatNumber, thisMonth } from '../labels.js'

export default {
	name: 'NeedsEditor',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		/** Zeilen { poolId, period, personDays } */
		modelValue: { type: Array, required: true },
		pools: { type: Array, default: () => [] },
		readonly: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	setup() {
		return { mdiClose, mdiPlus, t, formatMonth, formatNumber }
	},
	computed: {
		total() {
			return this.modelValue.reduce((sum, r) => sum + (Number(r.personDays) || 0), 0)
		},
	},
	methods: {
		poolName(id) {
			return this.pools.find(p => p.id === id)?.name ?? '?'
		},
		update(i, field, value) {
			const rows = this.modelValue.map((r, j) => j === i ? { ...r, [field]: value } : r)
			this.$emit('update:modelValue', rows)
		},
		add() {
			const last = this.modelValue[this.modelValue.length - 1]
			this.$emit('update:modelValue', [...this.modelValue, {
				poolId: last?.poolId ?? this.pools[0].id,
				period: last?.period ?? thisMonth(),
				personDays: '',
			}])
		},
		remove(i) {
			this.$emit('update:modelValue', this.modelValue.filter((_, j) => j !== i))
		},
	},
}
</script>

<style scoped lang="scss">
.needs__hint {
	margin: -8px 0 10px;
}

.needs__empty {
	color: var(--color-text-maxcontrast);
}

.needs__table {
	width: 100%;
	margin-bottom: 6px;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;

	th, td {
		padding: 4px 6px 4px 0;
		text-align: start;
	}

	thead th {
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	tfoot th {
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
	}
}

.needs__days.nx-input.nx-input {
	width: 110px;
}
</style>
