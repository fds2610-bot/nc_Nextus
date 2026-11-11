<template>
	<NxDialog :title="pool ? pool.name : t('nc_nexus', 'New resource pool')"
		:subtitle="t('nc_nexus', 'Plan pools (skill or role groups), not persons. Project capacity = FTE × working days, minus absence, line work, overhead and emergency reserve.')"
		size="large"
		data-test="pool-dialog"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<div class="nx-section">
				<h3>{{ t('nc_nexus', 'Basic data') }}</h3>
				<fieldset :disabled="!canManage" class="pool__fields">
					<div class="nx-field-row">
						<label class="nx-field" data-test="field-pool-name">
							<span>{{ t('nc_nexus', 'Name') }} *</span>
							<input v-model="form.name" class="nx-input" maxlength="255">
						</label>
						<label class="nx-field" data-test="field-pool-code">
							<span>{{ t('nc_nexus', 'Code') }} *</span>
							<input v-model="form.code" class="nx-input" maxlength="32">
						</label>
					</div>
					<div class="nx-field-row">
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Pool type') }}</span>
							<select v-model="form.poolType" class="nx-input">
								<option v-for="type in types" :key="type" :value="type">{{ poolTypeLabel(type) }}</option>
							</select>
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Organizational unit') }}</span>
							<select v-model="form.orgUnitId" class="nx-input">
								<option :value="null">{{ t('nc_nexus', '– none –') }}</option>
								<option v-for="unit in tree" :key="unit.id" :value="unit.id">{{ indented(unit) }}</option>
							</select>
						</label>
					</div>
					<div class="nx-field-row">
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Pool manager (account)') }}</span>
							<input v-model="form.managerUserId" class="nx-input" :placeholder="t('nc_nexus', 'Account ID')">
						</label>
						<label class="nx-field pool__check" data-test="field-pool-bottleneck">
							<input v-model="form.isBottleneck" type="checkbox">
							<span>{{ t('nc_nexus', 'Bottleneck pool (a firm commitment is required before release)') }}</span>
						</label>
					</div>
					<div class="nx-field-row pool__rates">
						<label v-for="rate in rates" :key="rate.field" class="nx-field">
							<span>{{ rate.label }} (%)</span>
							<NxNumberInput v-model="form[rate.field]"
								:min="0"
								:max="99"
								class="nx-input" />
						</label>
					</div>
					<div class="nx-field-row">
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Key persons (number of people with the critical knowledge)') }}</span>
							<NxNumberInput v-model="form.keyPersons"
								:max-decimals="0"
								:min="0"
								class="nx-input" />
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'WIP limit (parallel projects)') }}</span>
							<NxNumberInput v-model="form.wipLimit"
								:max-decimals="0"
								:min="0"
								class="nx-input" />
						</label>
					</div>
				</fieldset>
			</div>

			<div v-if="pool" class="nx-section" data-test="pool-capacity">
				<h3>{{ t('nc_nexus', 'Capacity per month') }}</h3>
				<p class="nx-hint">
					{{ t('nc_nexus', 'Months without an own entry take over the FTE of the last maintained month (shown in grey).') }}
				</p>
				<table class="pool__table">
					<thead>
						<tr>
							<th>{{ t('nc_nexus', 'Month') }}</th>
							<th>{{ t('nc_nexus', 'FTE') }}</th>
							<th>{{ t('nc_nexus', 'Working days') }}</th>
							<th>{{ t('nc_nexus', 'Gross') }}</th>
							<th>{{ t('nc_nexus', 'Net') }}</th>
							<th>{{ t('nc_nexus', 'Override') }}</th>
							<th>{{ t('nc_nexus', 'Project capacity') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows"
							:key="row.period"
							:class="{ 'pool__row--inherited': row.inherited && !row.changed }"
							:data-test="'capacity-' + row.period">
							<td>{{ formatMonth(row.period) }}</td>
							<td>
								<NxNumberInput v-model="row.fte"
									:min="0"
									class="nx-input pool__num"
									:disabled="!canEdit"
									:aria-label="t('nc_nexus', 'FTE') + ' ' + row.period"
									@input="row.changed = true" />
							</td>
							<td>
								<NxNumberInput v-model="row.workingDays"
									:max-decimals="0"
									:min="0"
									:max="31"
									class="nx-input pool__num"
									:disabled="!canEdit"
									:aria-label="t('nc_nexus', 'Working days') + ' ' + row.period"
									@input="row.changed = true" />
							</td>
							<td>{{ formatNumber(row.gross) }}</td>
							<td>{{ formatNumber(row.net) }}</td>
							<td>
								<NxNumberInput v-model="row.overrideDays"
									:min="0"
									class="nx-input pool__num"
									:disabled="!canEdit"
									:placeholder="'–'"
									:aria-label="t('nc_nexus', 'Override') + ' ' + row.period"
									@input="row.changed = true" />
								<input v-if="row.overrideDays !== null && row.overrideDays !== ''"
									v-model="row.overrideReason"
									class="nx-input pool__reason"
									:disabled="!canEdit"
									:placeholder="t('nc_nexus', 'Reason *')"
									@input="row.changed = true">
							</td>
							<td class="pool__project">
								{{ formatNumber(row.project) }} {{ t('nc_nexus', 'PD') }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</form>
		<template #actions>
			<NcButton v-if="pool && canManage"
				class="nx-dialog__left"
				variant="error"
				:disabled="busy"
				data-test="delete-pool"
				@click="remove">
				{{ t('nc_nexus', 'Delete') }}
			</NcButton>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton v-if="canManage || canEdit"
				variant="primary"
				:disabled="busy || !dirty || !valid"
				data-test="save-pool"
				@click="save">
				{{ t('nc_nexus', 'Save') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from './NxDialog.vue'
import NxNumberInput from './NxNumberInput.vue'
import { createPool, deletePool, errorMessage, poolCapacity, setPoolCapacity, updatePool } from '../api.js'
import { formatMonth, formatNumber, indented, orgTree, poolTypeLabel, thisMonth } from '../labels.js'

const fromPool = (pool) => ({
	name: pool?.name ?? '',
	code: pool?.code ?? '',
	poolType: pool?.poolType ?? 'internal',
	orgUnitId: pool?.orgUnitId ?? null,
	managerUserId: pool?.managerUserId ?? '',
	isBottleneck: pool?.isBottleneck === 1,
	absencePct: pool?.absencePct ?? 16,
	linePct: pool?.linePct ?? 30,
	overheadPct: pool?.overheadPct ?? 12,
	reservePct: pool?.reservePct ?? 5,
	keyPersons: pool?.keyPersons ?? '',
	wipLimit: pool?.wipLimit ?? '',
})

export default {
	name: 'PoolDialog',
	components: { NcButton, NxDialog, NxNumberInput },
	props: {
		pool: { type: Object, default: null },
		orgUnits: { type: Array, default: () => [] },
		canManage: { type: Boolean, default: false },
	},
	emits: ['close', 'changed', 'created'],
	setup() {
		return { t, formatMonth, formatNumber, indented, poolTypeLabel, types: ['internal', 'external', 'business'] }
	},
	data() {
		const form = fromPool(this.pool)
		return { busy: false, form, initial: JSON.stringify(form), rows: [] }
	},
	computed: {
		tree() {
			return orgTree(this.orgUnits)
		},
		canEdit() {
			return this.canManage || !!this.pool?.canEdit
		},
		rates() {
			return [
				{ field: 'absencePct', label: t('nc_nexus', 'Absence') },
				{ field: 'linePct', label: t('nc_nexus', 'Line work / operations') },
				{ field: 'overheadPct', label: t('nc_nexus', 'Overhead') },
				{ field: 'reservePct', label: t('nc_nexus', 'Emergency reserve') },
			]
		},
		changedRows() {
			return this.rows.filter(r => r.changed)
		},
		dirty() {
			return JSON.stringify(this.form) !== this.initial || this.changedRows.length > 0
		},
		valid() {
			return this.form.name.trim() !== '' && this.form.code.trim() !== ''
				&& this.changedRows.every(r => r.overrideDays === null || r.overrideDays === '' || (r.overrideReason || '').trim() !== '')
		},
	},
	async mounted() {
		if (this.pool) {
			await this.loadRows()
		}
	},
	methods: {
		async loadRows() {
			try {
				this.rows = (await poolCapacity(this.pool.id, thisMonth(), 12)).map(r => ({ ...r, changed: false }))
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		payload() {
			const f = this.form
			return {
				...f,
				code: f.code.trim().toUpperCase(),
				keyPersons: f.keyPersons === '' ? null : f.keyPersons,
				wipLimit: f.wipLimit === '' ? null : f.wipLimit,
			}
		},
		async save() {
			if (!this.dirty || !this.valid) {
				return
			}
			this.busy = true
			try {
				if (!this.pool) {
					// neuer Pool: danach gleich die Kapazität pflegen (Dialog öffnet sich für den Pool neu)
					const created = await createPool(this.payload())
					showSuccess(t('nc_nexus', 'Pool created – now enter the capacity per month'))
					this.$emit('changed')
					this.$emit('created', created)
					return
				}
				if (JSON.stringify(this.form) !== this.initial) {
					await updatePool(this.pool.id, this.payload())
				}
				for (const row of this.changedRows) {
					const override = row.overrideDays === '' || row.overrideDays === null ? null : row.overrideDays
					await setPoolCapacity(this.pool.id, row.period, {
						fte: row.fte || 0,
						workingDays: row.workingDays === '' ? null : row.workingDays,
						overrideDays: override,
						overrideReason: override === null ? null : row.overrideReason,
					})
				}
				showSuccess(t('nc_nexus', 'Pool saved'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async remove() {
			this.busy = true
			try {
				await deletePool(this.pool.id)
				showSuccess(t('nc_nexus', 'Pool deleted'))
				this.$emit('changed')
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
.pool__fields {
	margin: 0;
	padding: 0;
	border: none;
}

.pool__rates {
	grid-template-columns: repeat(4, 1fr);
}

.pool__check {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 24px;

	> span {
		margin: 0;
		color: var(--color-main-text);
	}
}

.pool__table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;

	th, td {
		padding: 4px 6px;
		text-align: end;
		border-bottom: 1px solid var(--color-border);
		vertical-align: top;
	}

	th:first-child, td:first-child {
		text-align: start;
	}

	th {
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}
}

.pool__num.nx-input.nx-input {
	width: 80px;
	min-height: 30px;
	padding: 2px 6px;
	text-align: end;
}

.pool__reason.nx-input.nx-input {
	display: block;
	width: 160px;
	min-height: 30px;
	margin-top: 4px;
	padding: 2px 6px;
}

.pool__row--inherited .pool__num {
	color: var(--color-text-maxcontrast);
}

.pool__project {
	font-weight: 600;
	white-space: nowrap;
}
</style>
