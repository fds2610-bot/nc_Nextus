<template>
	<NxDialog :title="t('nc_nexus', 'Business case: {title}', { title: demand.title })"
		:subtitle="t('nc_nexus', 'A structured object, not a presentation. Every benefit item needs a named owner and a metric with a baseline – without a baseline no benefit review is possible.')"
		size="large"
		data-test="business-case-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<div class="nx-summary__facts bc__kpis" data-test="bc-kpis">
			<div class="nx-fact">
				{{ t('nc_nexus', 'One-time cost') }}<b>{{ formatMoney(kpis.oneTimeCost) }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Running cost p. a.') }}<b>{{ formatMoney(kpis.annualCost) }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Benefit p. a.') }}<b data-test="bc-benefit">{{ formatMoney(kpis.benefitPerYear) }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'TCO 5 years') }}<b>{{ formatMoney(kpis.tco) }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Payback') }}<b data-test="bc-payback">{{ kpis.paybackYears === null ? '–' : t('nc_nexus', '{y} years', { y: formatNumber(kpis.paybackYears) }) }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Net present value 5 years') }}<b>{{ formatMoney(kpis.npv) }}</b>
			</div>
		</div>

		<fieldset :disabled="!canEdit" class="bc__fields">
			<div class="nx-section">
				<h3>{{ t('nc_nexus', 'Costs') }}</h3>
				<table v-if="content.costs.length" class="bc__table">
					<tbody>
						<tr v-for="(c, i) in content.costs" :key="i" :data-test="'cost-' + i">
							<td>
								<input v-model="c.label"
									class="nx-input"
									:placeholder="t('nc_nexus', 'e.g. licences')"
									:aria-label="t('nc_nexus', 'Label')">
							</td>
							<td>
								<select v-model="c.kind" class="nx-input" :aria-label="t('nc_nexus', 'Kind')">
									<option v-for="k in costKinds" :key="k" :value="k">
										{{ costKindLabel(k) }}
									</option>
								</select>
							</td>
							<td>
								<input v-model.number="c.amount"
									type="number"
									min="0"
									step="100"
									class="nx-input bc__amount"
									:aria-label="t('nc_nexus', 'Amount (€)')">
							</td>
							<td><RemoveButton v-if="canEdit" @click="content.costs.splice(i, 1)" /></td>
						</tr>
					</tbody>
				</table>
				<NcButton v-if="canEdit"
					variant="tertiary"
					data-test="add-cost"
					@click="content.costs.push({ label: '', kind: 'capex', amount: 0 })">
					{{ t('nc_nexus', 'Add cost item') }}
				</NcButton>
			</div>

			<div class="nx-section">
				<h3>{{ t('nc_nexus', 'Benefit items') }}</h3>
				<div v-for="(b, i) in content.benefits"
					:key="i"
					class="bc__benefit"
					:data-test="'benefit-' + i">
					<div class="nx-field-row bc__row3">
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Type') }}</span>
							<select v-model="b.type" class="nx-input">
								<option v-for="ty in benefitTypes" :key="ty" :value="ty">{{ benefitTypeLabel(ty) }}</option>
							</select>
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Title') }} *</span>
							<input v-model="b.title" class="nx-input" data-test="benefit-title">
						</label>
						<label v-if="b.type !== 'qualitative'" class="nx-field">
							<span>{{ t('nc_nexus', 'Amount per year (€)') }}</span>
							<input v-model.number="b.amount"
								type="number"
								min="0"
								step="1000"
								class="nx-input"
								data-test="benefit-amount">
						</label>
					</div>
					<div class="nx-field-row bc__row3">
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Metric') }} *</span>
							<input v-model="b.metric"
								class="nx-input"
								:placeholder="t('nc_nexus', 'e.g. processing time per invoice')"
								data-test="benefit-metric">
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Baseline') }} *</span>
							<input v-model="b.baseline" class="nx-input" data-test="benefit-baseline">
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Target') }}</span>
							<input v-model="b.target" class="nx-input">
						</label>
					</div>
					<div class="nx-field-row bc__row3">
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Benefit owner (account)') }} *</span>
							<input v-model="b.ownerUserId"
								class="nx-input"
								:placeholder="t('nc_nexus', 'Account ID')"
								data-test="benefit-owner">
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Measurement method') }}</span>
							<input v-model="b.method" class="nx-input">
						</label>
						<label class="nx-field">
							<span>{{ t('nc_nexus', 'Effective from') }}</span>
							<input v-model="b.from" type="month" class="nx-input">
						</label>
					</div>
					<NcButton v-if="canEdit" variant="tertiary" @click="content.benefits.splice(i, 1)">
						{{ t('nc_nexus', 'Remove benefit item') }}
					</NcButton>
				</div>
				<NcButton v-if="canEdit"
					variant="tertiary"
					data-test="add-benefit"
					@click="addBenefit">
					{{ t('nc_nexus', 'Add benefit item') }}
				</NcButton>
			</div>

			<div class="nx-section">
				<h3>{{ t('nc_nexus', 'Assumptions') }}</h3>
				<p class="nx-hint bc__hint">
					{{ t('nc_nexus', 'Checked at every phase gate: does the assumption still hold?') }}
				</p>
				<table v-if="content.assumptions.length" class="bc__table">
					<tbody>
						<tr v-for="(a, i) in content.assumptions" :key="i">
							<td>
								<input v-model="a.text"
									class="nx-input"
									:placeholder="t('nc_nexus', 'Assumption')"
									:aria-label="t('nc_nexus', 'Assumption')">
							</td>
							<td>
								<input v-model="a.rationale"
									class="nx-input"
									:placeholder="t('nc_nexus', 'Rationale')"
									:aria-label="t('nc_nexus', 'Rationale')">
							</td>
							<td>
								<input v-model="a.ownerUserId"
									class="nx-input bc__owner"
									:placeholder="t('nc_nexus', 'Account ID')"
									:aria-label="t('nc_nexus', 'Owner')">
							</td>
							<td>
								<input v-model="a.checkDate"
									type="date"
									class="nx-input"
									:aria-label="t('nc_nexus', 'Check date')">
							</td>
							<td><RemoveButton v-if="canEdit" @click="content.assumptions.splice(i, 1)" /></td>
						</tr>
					</tbody>
				</table>
				<NcButton v-if="canEdit" variant="tertiary" @click="content.assumptions.push({ text: '', rationale: '', ownerUserId: '', checkDate: '' })">
					{{ t('nc_nexus', 'Add assumption') }}
				</NcButton>
				<label class="nx-field bc__rate">
					<span>{{ t('nc_nexus', 'Discount rate (%)') }}</span>
					<input v-model.number="content.discountRate"
						type="number"
						min="0"
						max="50"
						step="0.5"
						class="nx-input">
				</label>
			</div>
		</fieldset>

		<div v-if="versions.length" class="nx-section">
			<h3>{{ t('nc_nexus', 'Versions') }}</h3>
			<ul class="bc__versions">
				<li v-for="v in versions" :key="v.id">
					{{ t('nc_nexus', 'Version {v}', { v: v.version }) }} ·
					{{ v.status === 'draft' ? t('nc_nexus', 'draft') : t('nc_nexus', 'frozen at {gate}', { gate: v.frozenReason }) }} ·
					{{ formatDate(v.updatedAt) }} · {{ v.updatedBy }}
				</li>
			</ul>
		</div>

		<template #actions>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton v-if="canEdit"
				variant="primary"
				:disabled="busy || !dirty"
				data-test="save-business-case"
				@click="save">
				{{ t('nc_nexus', 'Save business case') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { mdiClose } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { h } from 'vue'
import NxDialog from './NxDialog.vue'
import { errorMessage, saveBusinessCase } from '../api.js'
import { benefitTypeLabel, costKindLabel, formatDate, formatMoney, formatNumber } from '../labels.js'

// kleiner Entfernen-Knopf für Tabellenzeilen
const RemoveButton = (props, { emit }) => h(NcButton, {
	variant: 'tertiary',
	'aria-label': t('nc_nexus', 'Remove row'),
	onClick: () => emit('click'),
}, { icon: () => h(NcIconSvgWrapper, { path: mdiClose }) })
RemoveButton.emits = ['click']

export default {
	name: 'BusinessCaseDialog',
	components: { NcButton, NxDialog, RemoveButton },
	props: {
		demand: { type: Object, required: true },
		businessCase: { type: Object, required: true },
	},
	emits: ['close', 'saved', 'ready'],
	setup() {
		return {
			t,
			benefitTypeLabel,
			costKindLabel,
			formatDate,
			formatMoney,
			formatNumber,
			costKinds: ['capex', 'opex_once', 'opex_annual'],
			benefitTypes: ['cost_saving', 'capacity', 'revenue', 'avoided_cost', 'qualitative'],
		}
	},
	data() {
		const content = JSON.parse(JSON.stringify(this.businessCase.content))
		return { busy: false, content, initial: JSON.stringify(content) }
	},
	computed: {
		canEdit() {
			return this.businessCase.canEdit
		},
		versions() {
			return this.businessCase.versions
		},
		dirty() {
			return JSON.stringify(this.content) !== this.initial
		},
		// Vorschau der Kennzahlen wie auf dem Server (Kapitel 03 §4)
		kpis() {
			let oneTime = 0
			let annual = 0
			for (const c of this.content.costs) {
				if (c.kind === 'opex_annual') {
					annual += Number(c.amount) || 0
				} else {
					oneTime += Number(c.amount) || 0
				}
			}
			const benefit = this.content.benefits.filter(b => b.type !== 'qualitative').reduce((s, b) => s + (Number(b.amount) || 0), 0)
			const net = benefit - annual
			const rate = (Number(this.content.discountRate) || 0) / 100
			let npv = -oneTime
			for (let y = 1; y <= 5; y++) {
				npv += net / ((1 + rate) ** y)
			}
			return { oneTimeCost: oneTime, annualCost: annual, benefitPerYear: benefit, tco: oneTime + 5 * annual, paybackYears: net > 0 ? Math.round(oneTime / net * 10) / 10 : null, npv }
		},
	},
	methods: {
		addBenefit() {
			this.content.benefits.push({ type: 'cost_saving', title: '', amount: 0, metric: '', baseline: '', target: '', ownerUserId: getCurrentUser()?.uid ?? '', method: '', from: '' })
		},
		async save() {
			this.busy = true
			try {
				const result = await saveBusinessCase(this.demand.id, this.content)
				showSuccess(t('nc_nexus', 'Business case saved'))
				this.$emit('saved', result)
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
.bc__kpis {
	margin: 0 0 6px;
}

.bc__fields {
	margin: 0;
	padding: 0;
	border: none;
}

.bc__table {
	width: 100%;
	margin-bottom: 4px;
	border-collapse: collapse;

	td {
		padding: 3px 6px 3px 0;
	}
}

.bc__amount.nx-input.nx-input {
	width: 140px;
}

.bc__owner.nx-input.nx-input {
	width: 130px;
}

.bc__benefit {
	margin-bottom: 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
}

.bc__row3 {
	grid-template-columns: 1fr 1fr 1fr;
}

.bc__hint {
	margin: -8px 0 8px;
}

.bc__rate {
	max-width: 200px;
	margin-top: 12px;
}

.bc__versions {
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
