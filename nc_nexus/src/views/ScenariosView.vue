<template>
	<div class="nx-view" data-test="scenarios-view">
		<!-- Liste -->
		<template v-if="!current">
			<div class="nx-view__header">
				<h2>{{ t('nc_nexus', 'Scenarios') }}</h2>
				<span class="nx-view__count">{{ n('nc_nexus', '%n scenario', '%n scenarios', scenarios.length) }}</span>
				<span class="nx-view__spacer" />
				<NcButton v-if="canEdit"
					variant="primary"
					data-test="new-scenario"
					@click="openCreate(null)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New scenario') }}
				</NcButton>
			</div>
			<p class="nx-view__hint">
				{{ t('nc_nexus', 'What-if analysis: a scenario changes a copy of the current portfolio – add or pause projects, shift starts, change ranks, capacity, budget or bucket shares. The capacity cut runs on the copy and is compared with the current state. Scenarios never change commitments or ranks; an adopted scenario creates actions that implement the changes regularly.') }}
			</p>
			<p v-if="loaded && !scenarios.length" class="nx-view__empty" data-test="scenarios-empty">
				{{ t('nc_nexus', 'No scenarios yet.') }}
			</p>
			<div v-else-if="loaded" class="nx-view__table">
				<div v-for="s in scenarios"
					:key="s.id"
					class="nx-row nx-row--click scen-list__row"
					role="button"
					tabindex="0"
					:data-test="'scenario-' + s.id"
					@click="open(s.id)"
					@keydown.enter="open(s.id)">
					<span class="nx-row__name">
						<span class="nx-row__title">{{ s.name }}</span>
						<span class="nx-row__meta">
							{{ [n('nc_nexus', '%n change', '%n changes', s.changeCount), s.createdByName, formatDay(s.createdAt)].join(' · ') }}
						</span>
					</span>
					<span class="scen-list__summary">
						<template v-if="s.summary">{{ summaryText(s.summary) }}</template>
					</span>
					<span class="nx-chip" :class="'nx-chip--' + scenarioStatusChip(s.status)">{{ scenarioStatusLabel(s.status) }}</span>
				</div>
			</div>
		</template>

		<!-- Detail -->
		<template v-else>
			<div class="nx-view__header">
				<NcButton variant="tertiary"
					:aria-label="t('nc_nexus', 'Back to the scenarios')"
					data-test="scenario-back"
					@click="close">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" />
					</template>
				</NcButton>
				<h2 data-test="scenario-name">
					{{ current.name }}
				</h2>
				<span class="nx-chip" :class="'nx-chip--' + scenarioStatusChip(current.status)" data-test="scenario-status">{{ scenarioStatusLabel(current.status) }}</span>
				<span class="nx-view__spacer" />
				<NcButton v-if="current.can.edit"
					variant="tertiary"
					data-test="edit-scenario"
					@click="openEdit">
					{{ t('nc_nexus', 'Edit') }}
				</NcButton>
				<NcButton v-if="canEdit"
					variant="tertiary"
					data-test="copy-scenario"
					@click="openCreate(current)">
					{{ t('nc_nexus', 'Copy') }}
				</NcButton>
				<NcButton v-if="current.can.delete"
					variant="tertiary"
					data-test="delete-scenario"
					@click="remove">
					{{ t('nc_nexus', 'Delete') }}
				</NcButton>
				<NcButton v-if="current.can.discard"
					variant="secondary"
					data-test="discard-scenario"
					@click="openDecide('discard')">
					{{ t('nc_nexus', 'Discard') }}
				</NcButton>
				<NcButton v-if="current.can.reopen"
					variant="secondary"
					data-test="reopen-scenario"
					@click="action('reopen')">
					{{ t('nc_nexus', 'Back to draft') }}
				</NcButton>
				<NcButton v-if="current.can.present"
					variant="primary"
					:disabled="!current.changes.length || busy"
					data-test="present-scenario"
					@click="action('present')">
					{{ t('nc_nexus', 'Present') }}
				</NcButton>
				<NcButton v-if="current.can.adopt"
					variant="primary"
					data-test="adopt-scenario"
					@click="openDecide('adopt')">
					{{ t('nc_nexus', 'Adopt') }}
				</NcButton>
			</div>
			<p class="nx-view__hint" data-test="scenario-basis">
				{{ basisText }}
			</p>
			<p v-if="current.description" class="scen__description">
				{{ current.description }}
			</p>
			<p v-if="current.decidedAt" class="nx-hint scen__decision" data-test="scenario-decision">
				{{ t('nc_nexus', '{status} by {name} on {date}', { status: scenarioStatusLabel(current.status), name: current.decidedByName, date: formatDay(current.decidedAt) }) }}<template v-if="current.decisionNote">
					– {{ current.decisionNote }}
				</template>
			</p>

			<section class="nx-view__section">
				<div class="nx-view__section-head">
					<h3>{{ t('nc_nexus', 'Changes') }}</h3>
					<span class="nx-view__spacer" />
					<NcButton v-if="current.can.edit"
						variant="secondary"
						data-test="add-change"
						@click="openChange">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" />
						</template>
						{{ t('nc_nexus', 'Add change') }}
					</NcButton>
				</div>
				<p v-if="!current.changes.length" class="nx-view__empty" data-test="changes-empty">
					{{ t('nc_nexus', 'No changes yet: the scenario equals the status quo.') }}
				</p>
				<div v-else class="nx-view__table">
					<div v-for="c in current.changes"
						:key="c.id"
						class="nx-row scen__change"
						:data-test="'change-' + c.changeType">
						<span class="nx-row__name">
							<span class="nx-row__title">{{ scenarioChangeText(c) }}</span>
							<span class="nx-row__meta">{{ scenarioChangeLabel(c.changeType) }}</span>
						</span>
						<NcButton v-if="current.can.edit"
							variant="tertiary"
							:aria-label="t('nc_nexus', 'Remove change')"
							data-test="remove-change"
							@click="removeChange(c)">
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" />
							</template>
						</NcButton>
					</div>
				</div>
			</section>

			<template v-if="cmp">
				<div class="nx-kpis" data-test="scenario-summary">
					<div v-for="k in kpis"
						:key="k.effect"
						class="nx-kpi"
						:data-test="'summary-' + k.effect">
						<span class="nx-kpi__lab">{{ k.label }}</span>
						<span class="nx-kpi__num">{{ cmp.summary[k.effect] }}</span>
					</div>
				</div>

				<section class="nx-view__section">
					<div class="nx-view__section-head">
						<h3>{{ t('nc_nexus', 'Start plan: current state and scenario') }}</h3>
						<span class="nx-view__spacer" />
						<NcCheckboxRadioSwitch v-model="showAll" type="switch" data-test="show-all">
							{{ t('nc_nexus', 'Show unchanged projects') }}
						</NcCheckboxRadioSwitch>
					</div>
					<p v-if="!visibleItems.length" class="nx-view__empty" data-test="items-empty">
						{{ cmp.items.length ? t('nc_nexus', 'The scenario does not change the start plan.') : t('nc_nexus', 'No projects in the ranking.') }}
					</p>
					<div v-else class="nx-view__table">
						<div class="scen-item scen-item--head" aria-hidden="true">
							<span>{{ t('nc_nexus', 'Rank') }}</span>
							<span>{{ t('nc_nexus', 'Project') }}</span>
							<span>{{ t('nc_nexus', 'Current state') }}</span>
							<span>{{ t('nc_nexus', 'Scenario') }}</span>
							<span>{{ t('nc_nexus', 'Effect') }}</span>
						</div>
						<div v-for="item in visibleItems"
							:key="item.id"
							class="scen-item"
							:data-test="'item-' + item.key">
							<span class="scen-item__rank">
								{{ rankText(item) }}
							</span>
							<span class="nx-row__name">
								<a href="#" class="nx-row__title" @click.prevent="$emit('open', item.id)">{{ item.title }}</a>
								<span class="nx-row__meta">{{ item.key }} · {{ t('nc_nexus', 'Bucket {b}', { b: item.bucketScenario || item.bucketBase }) }} · {{ t('nc_nexus', '{d} PD', { d: formatNumber(item.personDays) }) }}</span>
							</span>
							<span><span v-if="item.base" class="nx-chip" :class="'nx-chip--' + planChip(item.base)">{{ planText(item.base) }}</span><template v-else>–</template></span>
							<span><span v-if="item.scenario"
								class="nx-chip"
								:class="'nx-chip--' + planChip(item.scenario)"
								data-test="plan-scenario">{{ planText(item.scenario) }}</span><template v-else>–</template></span>
							<span><span class="nx-chip" :class="'nx-chip--' + scenarioEffectChip(item.effect)" data-test="effect">{{ scenarioEffectLabel(item.effect) }}</span></span>
						</div>
					</div>
				</section>

				<section class="nx-view__section">
					<div class="nx-view__section-head">
						<h3>{{ t('nc_nexus', 'Bucket balance') }}</h3>
					</div>
					<div class="nx-view__table">
						<table class="scen__table" data-test="bucket-balance">
							<thead>
								<tr>
									<th>{{ t('nc_nexus', 'Bucket') }}</th>
									<th>{{ t('nc_nexus', 'Startable') }}</th>
									<th>{{ t('nc_nexus', 'Person days') }}</th>
									<th>{{ t('nc_nexus', 'One-time cost') }}</th>
									<th>{{ t('nc_nexus', 'Target share') }}</th>
									<th>{{ t('nc_nexus', 'Budget frame') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="b in cmp.buckets" :key="b.code" :data-test="'balance-' + b.code">
									<th>{{ b.code }} – {{ b.name }}</th>
									<td>{{ delta(b.base.placed + ' / ' + b.base.total, b.scenario.placed + ' / ' + b.scenario.total) }}</td>
									<td>{{ delta(formatNumber(b.base.personDays), formatNumber(b.scenario.personDays)) }}</td>
									<td>{{ delta(formatMoney(b.base.cost), formatMoney(b.scenario.cost)) }}</td>
									<td>{{ b.scored ? delta(formatNumber(b.shareBase) + ' %', formatNumber(b.shareScenario) + ' %') : '–' }}</td>
									<td>{{ delta(money(b.budgetBase), money(b.budgetScenario)) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</section>

				<section class="nx-view__section">
					<div class="nx-view__section-head">
						<h3>{{ t('nc_nexus', 'Bottleneck pools in the horizon') }}</h3>
					</div>
					<p v-if="!cmp.pools.length" class="nx-view__empty">
						{{ t('nc_nexus', 'No bottleneck pools: the capacity cut only checks bottleneck pools.') }}
					</p>
					<div v-else class="nx-view__table">
						<table class="scen__table" data-test="pool-balance">
							<thead>
								<tr>
									<th>{{ t('nc_nexus', 'Pool') }}</th>
									<th>{{ t('nc_nexus', 'Free project capacity (PD)') }}</th>
									<th>{{ t('nc_nexus', 'Left after the cut (PD)') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="p in cmp.pools" :key="p.id" :data-test="'pool-' + p.code">
									<th>{{ p.code }} – {{ p.name }}</th>
									<td>{{ delta(formatNumber(p.availableBase), formatNumber(p.availableScenario)) }}</td>
									<td>{{ delta(formatNumber(p.remainingBase), formatNumber(p.remainingScenario)) }}</td>
								</tr>
							</tbody>
						</table>
					</div>
					<p class="nx-hint">
						{{ t('nc_nexus', 'Horizon {from} to {to}; free capacity = project capacity minus firm commitments.', { from: formatMonth(cmp.horizon[0]), to: formatMonth(cmp.horizon[cmp.horizon.length - 1]) }) }}
					</p>
				</section>

				<section v-if="cmp.tasks && cmp.tasks.length" class="nx-view__section">
					<div class="nx-view__section-head">
						<h3>{{ t('nc_nexus', 'Implementation tasks') }}</h3>
					</div>
					<p class="nx-hint">
						{{ t('nc_nexus', 'Changes to projects became actions in the decision follow-up; changes to capacity and buckets are tracked here.') }}
					</p>
					<div class="nx-view__table">
						<div v-for="(task, i) in cmp.tasks"
							:key="i"
							class="nx-row"
							:data-test="'task-' + i">
							<NcCheckboxRadioSwitch :model-value="task.done"
								:disabled="!current.can.tasks || busy"
								@update:model-value="setTask(i, $event)">
								<span :class="{ 'scen__task--done': task.done }">{{ task.title }}</span>
							</NcCheckboxRadioSwitch>
							<span class="nx-row__muted">{{ formatIsoDay(task.dueDate) }}</span>
						</div>
					</div>
				</section>
			</template>
		</template>

		<!-- Name und Beschreibung -->
		<NxDialog v-if="form"
			:title="form.id ? t('nc_nexus', 'Edit scenario') : (form.copyOf ? t('nc_nexus', 'Copy scenario') : t('nc_nexus', 'New scenario'))"
			:subtitle="form.copyOf ? t('nc_nexus', 'The changes of “{name}” are taken over.', { name: form.copyName }) : ''"
			data-test="scenario-dialog"
			@close="form = null">
			<label class="nx-field" data-test="field-scenario-name">
				<span>{{ t('nc_nexus', 'Name') }} *</span>
				<input v-model="form.name" class="nx-input" maxlength="255">
			</label>
			<label class="nx-field" data-test="field-scenario-description">
				<span>{{ t('nc_nexus', 'Description') }}</span>
				<textarea v-model="form.description" class="nx-input" rows="4" />
			</label>
			<template #actions>
				<NcButton variant="tertiary" :disabled="busy" @click="form = null">
					{{ t('nc_nexus', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="busy || !form.name.trim()"
					data-test="save-scenario"
					@click="saveForm">
					{{ t('nc_nexus', 'Save') }}
				</NcButton>
			</template>
		</NxDialog>

		<!-- Änderung -->
		<NxDialog v-if="change"
			:title="t('nc_nexus', 'Add change')"
			:subtitle="t('nc_nexus', 'A second change of the same kind for the same target replaces the first one.')"
			data-test="change-dialog"
			@close="change = null">
			<label class="nx-field" data-test="field-change-type">
				<span>{{ t('nc_nexus', 'Kind of change') }} *</span>
				<select v-model="change.changeType" class="nx-input" @change="change.targetRef = ''">
					<option v-for="type in SCENARIO_CHANGE_TYPES" :key="type" :value="type">{{ scenarioChangeLabel(type) }}</option>
				</select>
			</label>
			<label class="nx-field" data-test="field-change-target">
				<span>{{ targetLabel }} *</span>
				<select v-model="change.targetRef" class="nx-input" @change="onTarget">
					<option value="" disabled>{{ t('nc_nexus', 'Please choose') }}</option>
					<option v-for="o in targetOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
				</select>
			</label>
			<p v-if="!targetOptions.length" class="nx-hint" data-test="no-targets">
				{{ noTargetText }}
			</p>
			<div v-if="change.changeType === 'add_project'" class="nx-field-row">
				<label class="nx-field" data-test="field-change-bucket">
					<span>{{ t('nc_nexus', 'Bucket') }}</span>
					<select v-model="change.bucket" class="nx-input">
						<option v-for="b in options.buckets" :key="b.code" :value="b.code">{{ b.code }} – {{ b.name }}</option>
					</select>
				</label>
				<label class="nx-field" data-test="field-change-density">
					<span>{{ t('nc_nexus', 'Assumed value density') }}</span>
					<NxNumberInput v-model="change.density" :min="0" class="nx-input" />
				</label>
			</div>
			<p v-if="change.changeType === 'add_project'" class="nx-hint">
				{{ t('nc_nexus', 'Without an assessment the project counts as not assessed; an assumed value density places it in the ranking. The capacity cut uses the resource needs recorded on the demand.') }}
			</p>
			<label v-if="change.changeType === 'change_rank'" class="nx-field" data-test="field-change-rank">
				<span>{{ t('nc_nexus', 'New rank in the bucket') }} *</span>
				<NxNumberInput v-model="change.rank"
					:min="1"
					:max-decimals="0"
					class="nx-input" />
			</label>
			<label v-if="change.changeType === 'shift_start'" class="nx-field" data-test="field-change-months">
				<span>{{ t('nc_nexus', 'Shift by months') }} *</span>
				<NxNumberInput v-model="change.months"
					:min="1"
					:max="12"
					:max-decimals="0"
					class="nx-input" />
			</label>
			<div v-if="change.changeType === 'change_capacity'" class="nx-field-row">
				<label class="nx-field" data-test="field-change-fte">
					<span>{{ t('nc_nexus', 'Change in FTE (negative = less)') }} *</span>
					<NxNumberInput v-model="change.fte" class="nx-input" />
				</label>
				<label class="nx-field" data-test="field-change-from">
					<span>{{ t('nc_nexus', 'From month (lead time)') }}</span>
					<select v-model="change.from" class="nx-input">
						<option v-for="p in options.periods" :key="p" :value="p">{{ formatMonth(p) }}</option>
					</select>
				</label>
			</div>
			<label v-if="change.changeType === 'pause'" class="nx-field" data-test="field-change-from">
				<span>{{ t('nc_nexus', 'Pause from month') }}</span>
				<select v-model="change.from" class="nx-input">
					<option v-for="p in options.periods" :key="p" :value="p">{{ formatMonth(p) }}</option>
				</select>
			</label>
			<label v-if="change.changeType === 'change_budget'" class="nx-field" data-test="field-change-budget">
				<span>{{ t('nc_nexus', 'Budget frame (empty = none)') }}</span>
				<NxNumberInput v-model="change.budget"
					:min="0"
					:max-decimals="0"
					class="nx-input" />
			</label>
			<label v-if="change.changeType === 'change_quota'" class="nx-field" data-test="field-change-share">
				<span>{{ t('nc_nexus', 'Target share in %') }} *</span>
				<NxNumberInput v-model="change.sharePct"
					:min="0"
					:max="100"
					:max-decimals="1"
					class="nx-input" />
			</label>
			<template #actions>
				<NcButton variant="tertiary" :disabled="busy" @click="change = null">
					{{ t('nc_nexus', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="busy || !change.targetRef"
					data-test="save-change"
					@click="saveChange">
					{{ t('nc_nexus', 'Add') }}
				</NcButton>
			</template>
		</NxDialog>

		<!-- Annehmen / Verwerfen -->
		<NxDialog v-if="decide"
			:title="decide.kind === 'adopt' ? t('nc_nexus', 'Adopt scenario: {name}', { name: current.name }) : t('nc_nexus', 'Discard scenario: {name}', { name: current.name })"
			:subtitle="decide.kind === 'adopt'
				? t('nc_nexus', 'Adopting changes nothing directly: every change to a project becomes an action in the decision follow-up, changes to capacity and buckets become implementation tasks of the scenario.')
				: t('nc_nexus', 'The scenario stays as documentation.')"
			data-test="decide-dialog"
			@close="decide = null">
			<label class="nx-field" data-test="field-decide-reason">
				<span>{{ t('nc_nexus', 'Reason') }}<template v-if="decide.kind === 'adopt'"> *</template></span>
				<textarea v-model="decide.reason" class="nx-input" rows="3" />
			</label>
			<div v-if="decide.kind === 'adopt'" class="nx-field-row">
				<div class="nx-field" data-test="field-decide-owner">
					<span>{{ t('nc_nexus', 'Responsible for the actions') }} *</span>
					<UserSelect v-model="decide.ownerId" :label="t('nc_nexus', 'Responsible for the actions')" />
				</div>
				<label class="nx-field" data-test="field-decide-due">
					<span>{{ t('nc_nexus', 'Due date') }} *</span>
					<input v-model="decide.dueDate" type="date" class="nx-input">
				</label>
			</div>
			<template #actions>
				<NcButton variant="tertiary" :disabled="busy" @click="decide = null">
					{{ t('nc_nexus', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="busy || (decide.kind === 'adopt' && (!decide.reason.trim() || !decide.ownerId || !decide.dueDate))"
					data-test="confirm-decide"
					@click="confirmDecide">
					{{ decide.kind === 'adopt' ? t('nc_nexus', 'Adopt') : t('nc_nexus', 'Discard') }}
				</NcButton>
			</template>
		</NxDialog>
	</div>
</template>

<script>
import { mdiArrowLeft, mdiClose, mdiPlus } from '@mdi/js'
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NxDialog from '../components/NxDialog.vue'
import NxNumberInput from '../components/NxNumberInput.vue'
import UserSelect from '../components/UserSelect.vue'
import {
	addScenarioChange, createScenario, deleteScenario, errorMessage, getScenario, listScenarios,
	removeScenarioChange, scenarioAction, setScenarioTask, updateScenario,
} from '../api.js'
import {
	formatDay, formatIsoDay, formatMoney, formatMonth, formatNumber, planChip, planText, SCENARIO_CHANGE_TYPES,
	scenarioChangeLabel, scenarioChangeText, scenarioEffectChip, scenarioEffectLabel, scenarioStatusChip, scenarioStatusLabel,
	statusLabel, today,
} from '../labels.js'

export default {
	name: 'ScenariosView',
	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper, NxDialog, NxNumberInput, UserSelect },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open', 'changed'],
	setup() {
		return {
			mdiArrowLeft,
			mdiClose,
			mdiPlus,
			n,
			t,
			formatDay,
			formatIsoDay,
			formatMoney,
			formatMonth,
			formatNumber,
			planChip,
			planText,
			SCENARIO_CHANGE_TYPES,
			scenarioChangeLabel,
			scenarioChangeText,
			scenarioEffectChip,
			scenarioEffectLabel,
			scenarioStatusChip,
			scenarioStatusLabel,
		}
	},
	data() {
		return {
			scenarios: [],
			canEdit: false,
			canAdopt: false,
			loaded: false,
			current: null,
			showAll: false,
			busy: false,
			form: null,
			change: null,
			decide: null,
		}
	},
	computed: {
		cmp() {
			return this.current?.comparison || null
		},
		options() {
			return this.current?.options || { ranked: [], addable: [], pausable: [], pools: [], buckets: [], periods: [] }
		},
		visibleItems() {
			return (this.cmp?.items || []).filter(i => this.showAll || i.effect !== 'unchanged')
		},
		kpis() {
			return [
				{ effect: 'rises', label: t('nc_nexus', 'Rise above the line') },
				{ effect: 'falls', label: t('nc_nexus', 'Fall below the line') },
				{ effect: 'earlier', label: t('nc_nexus', 'Start earlier') },
				{ effect: 'later', label: t('nc_nexus', 'Start later') },
				{ effect: 'added', label: t('nc_nexus', 'New') },
				{ effect: 'removed', label: t('nc_nexus', 'Taken out') },
			]
		},
		basisText() {
			if (this.current.status === 'draft') {
				return t('nc_nexus', 'Draft: calculated live on the current portfolio. Presenting freezes the result as the basis for the decision.')
			}
			return t('nc_nexus', 'Result frozen on {date}; later changes in the portfolio are not included.', { date: formatDay(this.current.baseSnapshotAt) })
		},
		targetLabel() {
			return {
				change_capacity: t('nc_nexus', 'Bottleneck pool'),
				change_budget: t('nc_nexus', 'Bucket'),
				change_quota: t('nc_nexus', 'Bucket'),
			}[this.change?.changeType] || t('nc_nexus', 'Project')
		},
		targetOptions() {
			const o = this.options
			const demand = (d) => ({ value: String(d.id), label: `${d.key} · ${d.title}` })
			switch (this.change?.changeType) {
			case 'add_project': return o.addable.map(d => ({ value: String(d.id), label: `${d.key} · ${d.title} (${statusLabel(d.status)})` }))
			case 'pause': return o.pausable.map(d => ({ value: String(d.id), label: `${d.key} · ${d.title} (${statusLabel(d.status)})` }))
			case 'remove_project':
			case 'shift_start':
			case 'change_rank': return o.ranked.map(d => ({ ...demand(d), label: `${d.bucket} ${d.rank}. ${d.key} · ${d.title}` }))
			case 'change_capacity': return o.pools.map(p => ({ value: String(p.id), label: `${p.code} – ${p.name}` }))
			case 'change_budget': return o.buckets.map(b => ({ value: b.code, label: `${b.code} – ${b.name}` }))
			case 'change_quota': return o.buckets.filter(b => b.scored).map(b => ({ value: b.code, label: `${b.code} – ${b.name}` }))
			default: return []
			}
		},
		noTargetText() {
			return {
				add_project: t('nc_nexus', 'No submitted, deferred or further analysed demand outside the ranking.'),
				pause: t('nc_nexus', 'No project with firm commitments in the horizon.'),
				change_capacity: t('nc_nexus', 'No bottleneck pools.'),
			}[this.change?.changeType] || t('nc_nexus', 'No projects in the ranking.')
		},
	},
	watch: {
		refreshKey() {
			this.current ? this.open(this.current.id) : this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				const result = await listScenarios()
				this.scenarios = result.scenarios
				this.canEdit = result.canEdit
				this.canAdopt = result.canAdopt
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loaded = true
			}
		},
		async open(id) {
			try {
				this.current = await getScenario(id)
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		close() {
			this.current = null
			this.showAll = false
			this.load()
		},
		async run(fn, success) {
			this.busy = true
			try {
				const result = await fn()
				if (success) {
					showSuccess(success)
				}
				return result
			} catch (e) {
				showError(errorMessage(e))
				return undefined
			} finally {
				this.busy = false
			}
		},
		summaryText(s) {
			const parts = []
			if (s.rises) parts.push(t('nc_nexus', '{n} rise', { n: s.rises }))
			if (s.falls) parts.push(t('nc_nexus', '{n} fall', { n: s.falls }))
			if (s.earlier) parts.push(t('nc_nexus', '{n} earlier', { n: s.earlier }))
			if (s.later) parts.push(t('nc_nexus', '{n} later', { n: s.later }))
			return parts.join(' · ')
		},
		rankText(item) {
			const b = item.rankBase ?? '–'
			const s = item.rankScenario ?? '–'
			return b === s ? String(b) : `${b} → ${s}`
		},
		delta(a, b) {
			return a === b ? a : `${a} → ${b}`
		},
		money(v) {
			return v === null || v === undefined ? '–' : formatMoney(v)
		},
		openCreate(copy) {
			this.form = copy
				? { id: null, copyOf: copy.id, copyName: copy.name, name: t('nc_nexus', '{name} (copy)', { name: copy.name }), description: copy.description || '' }
				: { id: null, copyOf: null, name: '', description: '' }
		},
		openEdit() {
			this.form = { id: this.current.id, copyOf: null, name: this.current.name, description: this.current.description || '' }
		},
		async saveForm() {
			const f = this.form
			const payload = { name: f.name, description: f.description }
			const result = await this.run(() => f.id
				? updateScenario(f.id, payload)
				: createScenario({ ...payload, copyOf: f.copyOf }), t('nc_nexus', 'Scenario saved'))
			if (result) {
				this.form = null
				this.current = result
			}
		},
		async remove() {
			if (!window.confirm(t('nc_nexus', 'Delete the scenario “{name}”?', { name: this.current.name }))) {
				return
			}
			const id = this.current.id
			if (await this.run(async () => { await deleteScenario(id); return true }, t('nc_nexus', 'Scenario deleted'))) {
				this.close()
			}
		},
		openChange() {
			this.change = {
				changeType: 'add_project',
				targetRef: '',
				bucket: 'C',
				density: null,
				rank: 1,
				months: 1,
				fte: 1,
				from: this.options.periods[0] || '',
				budget: null,
				sharePct: null,
			}
		},
		onTarget() {
			const c = this.change
			if (c.changeType === 'add_project') {
				c.bucket = this.options.addable.find(d => String(d.id) === c.targetRef)?.bucket || 'C'
			} else if (c.changeType === 'change_budget' || c.changeType === 'change_quota') {
				const b = this.options.buckets.find(x => x.code === c.targetRef)
				c.budget = b?.targetBudget ?? null
				c.sharePct = b?.targetSharePct ?? null
			} else if (c.changeType === 'change_rank') {
				c.rank = this.options.ranked.find(d => String(d.id) === c.targetRef)?.rank || 1
			}
		},
		async saveChange() {
			const c = this.change
			const parameters = {
				add_project: { bucket: c.bucket, density: c.density === '' ? null : c.density },
				remove_project: {},
				pause: { from: c.from },
				shift_start: { months: c.months },
				change_rank: { rank: c.rank },
				change_capacity: { fte: c.fte, from: c.from },
				change_budget: { budget: c.budget === '' ? null : c.budget },
				change_quota: { sharePct: c.sharePct },
			}[c.changeType]
			const result = await this.run(() => addScenarioChange(this.current.id, { changeType: c.changeType, targetRef: c.targetRef, parameters }))
			if (result) {
				this.change = null
				this.current = result
			}
		},
		async removeChange(c) {
			const result = await this.run(() => removeScenarioChange(this.current.id, c.id))
			if (result) {
				this.current = result
			}
		},
		async action(name) {
			const messages = {
				present: t('nc_nexus', 'Scenario presented; the result is frozen'),
				reopen: t('nc_nexus', 'Scenario is a draft again'),
			}
			const result = await this.run(() => scenarioAction(this.current.id, name), messages[name])
			if (result) {
				this.current = result
			}
		},
		openDecide(kind) {
			const due = new Date(today() + 'T12:00:00')
			due.setDate(due.getDate() + 14)
			this.decide = { kind, reason: '', ownerId: getCurrentUser()?.uid || '', dueDate: due.toISOString().slice(0, 10) }
		},
		async confirmDecide() {
			const d = this.decide
			const result = await this.run(
				() => scenarioAction(this.current.id, d.kind, d.kind === 'adopt' ? { reason: d.reason, ownerId: d.ownerId, dueDate: d.dueDate } : { reason: d.reason }),
				d.kind === 'adopt' ? t('nc_nexus', 'Scenario adopted; actions created') : t('nc_nexus', 'Scenario discarded'),
			)
			if (result) {
				this.decide = null
				this.current = result
				this.$emit('changed')
			}
		},
		async setTask(index, done) {
			const result = await this.run(() => setScenarioTask(this.current.id, index, done))
			if (result) {
				this.current = result
			}
		},
	},
}
</script>

<style scoped lang="scss">
.scen-list__row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto auto;
	gap: 12px;
}

.scen-list__summary {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.scen__description {
	margin: 0 0 12px;
	white-space: pre-wrap;
}

.scen__decision {
	margin: 0 0 12px;
}

.scen__change {
	display: flex;
	align-items: center;
	gap: 12px;

	.nx-row__name {
		flex: 1;
		min-width: 0;
	}
}

.scen__task--done {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.scen-item {
	display: grid;
	grid-template-columns: 70px minmax(0, 1fr) 200px 200px 170px;
	align-items: center;
	gap: 10px;
	min-height: 52px;
	padding: 4px 12px;
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

	&__rank {
		font-weight: 600;
	}
}

.scen__table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;

	th,
	td {
		padding: 8px 12px;
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
}
</style>
