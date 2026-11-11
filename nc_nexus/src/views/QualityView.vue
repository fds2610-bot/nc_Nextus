<template>
	<div class="nx-view" data-test="quality-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Data quality') }}</h2>
			<span v-if="cockpit" class="nx-view__count">{{ n('nc_nexus', '%n finding', '%n findings', cockpit.findings) }}</span>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'No decision without data: which required information is missing where, which deadlines are overdue – with the data owner who has to act.') }}
		</p>
		<div v-if="cockpit" class="nx-kpis qual__kpis">
			<div class="nx-kpi" data-test="quality-score">
				<span class="nx-kpi__lab">{{ t('nc_nexus', 'Demands without findings') }}</span>
				<span class="nx-kpi__num">{{ cockpit.score }} %</span>
				<span class="nx-kpi__sub">{{ t('nc_nexus', '{clean} of {open} open demands', { clean: cockpit.cleanDemands, open: cockpit.openDemands }) }}</span>
			</div>
			<div class="nx-kpi">
				<span class="nx-kpi__lab">{{ t('nc_nexus', 'Rules with findings') }}</span>
				<span class="nx-kpi__num">{{ cockpit.rules.filter(r => r.count).length }}</span>
				<span class="nx-kpi__sub">{{ t('nc_nexus', 'of {n} rules', { n: cockpit.rules.length }) }}</span>
			</div>
		</div>
		<div v-if="cockpit" class="nx-view__table">
			<template v-for="r in cockpit.rules" :key="r.key">
				<div class="nx-row qual__rule"
					:class="{ 'nx-row--click': r.count }"
					:data-test="'rule-' + r.key"
					:aria-expanded="open.has(r.key)"
					@click="r.count && toggle(r.key)">
					<NcIconSvgWrapper class="nx-row__chevron" :path="r.count ? (open.has(r.key) ? mdiChevronDown : mdiChevronRight) : mdiCheck" />
					<div class="nx-row__name">
						<span class="nx-row__title">{{ ruleLabel(r.key) }}</span>
						<span class="nx-row__meta">{{ t('nc_nexus', 'Data owner: {owner}', { owner: ownerLabel(r.owner) }) }}</span>
					</div>
					<span class="qual__count" :class="{ 'qual__count--bad': r.count }" data-test="rule-count">{{ r.count }}</span>
				</div>
				<template v-if="open.has(r.key)">
					<div v-for="(i, k) in r.items"
						:key="k"
						class="nx-row nx-row--sub qual__item"
						:class="{ 'nx-row--click': i.type !== 'objective' && i.type !== 'pool' }"
						@click="i.type === 'demand' || i.type === 'condition' ? $emit('open', i.id) : null">
						<span class="nx-row__meta">{{ i.key }}</span>
						<span>{{ i.title }}</span>
						<span class="nx-row__meta">{{ i.status ? statusLabel(i.status) : '' }}</span>
						<span class="nx-row__meta">{{ i.detail }}</span>
					</div>
				</template>
			</template>
		</div>
	</div>
</template>

<script>
import { mdiCheck, mdiChevronDown, mdiChevronRight } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { dataQuality, errorMessage } from '../api.js'
import { statusLabel } from '../labels.js'

export default {
	name: 'QualityView',
	components: { NcIconSvgWrapper },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { mdiCheck, mdiChevronDown, mdiChevronRight, n, t }
	},
	data() {
		return { cockpit: null, open: new Set() }
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
				this.cockpit = await dataQuality()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		toggle(key) {
			const open = new Set(this.open)
			if (!open.delete(key)) {
				open.add(key)
			}
			this.open = open
		},
		statusLabel(status) {
			return { condition: t('nc_nexus', 'Condition'), action: t('nc_nexus', 'Action') }[status] ?? statusLabel(status)
		},
		ruleLabel(key) {
			return {
				missing_org_unit: t('nc_nexus', 'Demands without organizational unit'),
				missing_class: t('nc_nexus', 'Qualified demands without project class'),
				missing_review: t('nc_nexus', 'Candidates without review by the portfolio office'),
				business_case_incomplete: t('nc_nexus', 'Incomplete business cases in the portfolio'),
				missing_needs: t('nc_nexus', 'Demands in qualification without resource needs'),
				missing_objectives: t('nc_nexus', 'Projects without strategic objective'),
				resubmission_overdue: t('nc_nexus', 'Resubmission or resume date overdue'),
				analysis_overdue: t('nc_nexus', 'Analyses overdue'),
				query_overdue: t('nc_nexus', 'Queries to requestors overdue'),
				stale: t('nc_nexus', 'Open demands not updated for 90 days'),
				no_planning_link: t('nc_nexus', 'Released projects not connected with the planning tool'),
				conditions_overdue: t('nc_nexus', 'Conditions and actions overdue'),
				pool_capacity_missing: t('nc_nexus', 'Pools without capacity in the next 3 months'),
				objectives_uncovered: t('nc_nexus', 'Strategic objectives without a running project'),
			}[key] ?? key
		},
		ownerLabel(owner) {
			return {
				requestor: t('nc_nexus', 'Requestor'),
				portfolio_office: t('nc_nexus', 'Portfolio office'),
				sponsor: t('nc_nexus', 'Sponsor / benefit owner'),
				analysis_owner: t('nc_nexus', 'Analysis owner'),
				project_manager: t('nc_nexus', 'Project manager'),
				condition_owner: t('nc_nexus', 'Owner of the condition'),
				pool_manager: t('nc_nexus', 'Pool manager'),
				executive: t('nc_nexus', 'Management (maintained by the portfolio office)'),
			}[owner] ?? owner
		},
	},
}
</script>

<style scoped lang="scss">
.qual__kpis {
	margin-bottom: 16px;
}

.qual__rule {
	grid-template-columns: auto minmax(0, 1fr) 60px;
}

.qual__item {
	grid-template-columns: 130px minmax(0, 1fr) 140px minmax(0, 1fr);
}

.qual__count {
	font-size: 18px;
	font-weight: 700;
	text-align: end;
	color: var(--color-success-text);
	padding-inline-end: 12px;
}

.qual__count--bad {
	color: var(--color-error-text);
}
</style>
