<template>
	<div v-if="c" class="cockpit" data-test="project-cockpit">
		<div class="nx-view__header">
			<NcButton variant="tertiary"
				:aria-label="t('nc_nexus', 'Back')"
				data-test="cockpit-back"
				@click="$emit('back')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiArrowLeft" />
				</template>
			</NcButton>
			<h2>{{ c.demand.title }}</h2>
			<span class="nx-view__count">{{ c.demand.projectKey || c.demand.key }}</span>
			<span class="nx-chip" :class="statusChipClass">{{ statusLabel(c.demand.status) }}</span>
			<span class="nx-chip" :class="ragChip(c.health.level)" data-test="cockpit-health">{{ t('nc_nexus', 'Health') }}: {{ ragLabel(c.health.level) }}</span>
			<span v-if="c.escalation" class="nx-chip nx-chip--rejected" data-test="cockpit-escalation">{{ escalationLabel(c.escalation) }}</span>
			<span class="nx-view__spacer" />
			<NcButton variant="tertiary" data-test="open-demand" @click="$emit('open-demand', c.demand.id)">
				{{ t('nc_nexus', 'Open demand') }}
			</NcButton>
			<NcButton v-if="c.can.manage && active"
				variant="primary"
				data-test="new-report"
				@click="openReport">
				{{ t('nc_nexus', 'Submit status report') }}
			</NcButton>
		</div>

		<!-- Stammdaten -->
		<div class="nx-summary__facts cockpit__facts">
			<div class="nx-fact">
				{{ t('nc_nexus', 'Project manager') }}<b data-test="cockpit-manager">{{ c.project.managerId || '–' }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Sponsor') }}<b>{{ c.project.sponsorId || '–' }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Planned') }}<b>{{ formatIsoDay(c.project.plannedStart) || '–' }} – {{ formatIsoDay(c.project.plannedEnd) || '–' }}</b>
			</div>
			<div class="nx-fact">
				{{ t('nc_nexus', 'Benefit review') }}<b>{{ formatIsoDay(c.project.benefitReviewDue) || '–' }}</b>
			</div>
			<div v-if="c.reportDue" class="nx-fact" :class="{ 'cockpit__warn': c.reportDue.overdue }">
				{{ t('nc_nexus', 'Next status report') }}<b data-test="cockpit-report-due">{{ formatIsoDay(c.reportDue.due) }}</b>
			</div>
			<NcButton v-if="c.can.manage"
				variant="tertiary"
				data-test="edit-project"
				@click="openProject">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPencil" />
				</template>
				{{ t('nc_nexus', 'Edit') }}
			</NcButton>
		</div>

		<div class="cockpit__grid">
			<!-- Status: gemeldet vs. berechnet -->
			<section class="cockpit__card" data-test="cockpit-status">
				<h3>{{ t('nc_nexus', 'Status') }}</h3>
				<div class="cockpit__twin">
					<div>
						<h4>{{ t('nc_nexus', 'Reported') }}</h4>
						<p v-if="!c.rag" class="nx-hint">
							{{ t('nc_nexus', 'No status report yet.') }}
						</p>
						<ul v-else class="cockpit__rag" data-test="cockpit-rag">
							<li v-for="d in dims" :key="d">
								<span class="cockpit__dot" :class="'cockpit__dot--' + c.rag[d]" />
								{{ dimensionRagLabel(d) }}
								<b v-if="c.contradictions.includes(d)" class="cockpit__contra">{{ t('nc_nexus', 'contradicts the indicator') }}</b>
							</li>
						</ul>
						<p v-if="c.reports[0]" class="cockpit__meta">
							{{ c.reports[0].period }} · {{ c.reports[0].createdBy }}<template v-if="c.reports[0].progressPct !== null">
								· {{ c.reports[0].progressPct }} %
							</template>
						</p>
						<p v-if="c.reports[0] && c.reports[0].contradictionReason" class="cockpit__meta">
							{{ t('nc_nexus', 'Explanation') }}: {{ c.reports[0].contradictionReason }}
						</p>
					</div>
					<div>
						<h4>{{ t('nc_nexus', 'Calculated (health indicator)') }}</h4>
						<p v-if="!c.health.findings.length" class="cockpit__ok">
							{{ t('nc_nexus', 'No findings.') }}
						</p>
						<ul v-else class="cockpit__findings" data-test="cockpit-findings">
							<li v-for="(f, i) in c.health.findings" :key="i">
								<span class="cockpit__dot" :class="'cockpit__dot--' + f.level" />
								<span>{{ healthRuleLabel(f.rule) }}<small> · {{ f.detail }} · {{ healthSourceLabel(f.source) }}</small></span>
							</li>
						</ul>
					</div>
				</div>
			</section>

			<!-- Finanzen und Aufwand -->
			<section class="cockpit__card" data-test="cockpit-finance">
				<h3>{{ t('nc_nexus', 'Budget and effort') }}</h3>
				<table class="cockpit__table">
					<tbody>
						<tr><td>{{ t('nc_nexus', 'Budget frame') }}</td><td>{{ formatMoney(c.finance.frame) }}</td></tr>
						<tr><td>{{ t('nc_nexus', 'Released (tranches)') }}</td><td>{{ formatMoney(c.finance.approved) }}</td></tr>
						<tr><td>{{ t('nc_nexus', 'Committed') }}</td><td>{{ formatMoney(c.finance.committed) }}</td></tr>
						<tr>
							<td>{{ t('nc_nexus', 'Actual cost') }}</td>
							<td data-test="cockpit-actual">
								{{ formatMoney(c.finance.actual) }}
								<small>({{ t('nc_nexus', 'external {ext}, internal {int}', { ext: formatMoney(c.finance.actualExternal), int: formatMoney(c.finance.actualInternal) }) }})</small>
							</td>
						</tr>
						<tr>
							<td>{{ t('nc_nexus', 'Forecast at completion') }}</td>
							<td :class="{ 'cockpit__warn': c.finance.deviation > 0 }" data-test="cockpit-forecast">
								{{ formatMoney(c.finance.forecast) }}
								<small v-if="c.finance.deviationPct !== null">({{ c.finance.deviationPct > 0 ? '+' : '' }}{{ formatNumber(c.finance.deviationPct) }} %)</small>
							</td>
						</tr>
						<tr>
							<td>{{ t('nc_nexus', 'Effort (PD)') }}</td>
							<td>{{ t('nc_nexus', 'planned {p}, to date {d}, actual {a}', { p: formatNumber(c.effort.planned), d: formatNumber(c.effort.plannedToDate), a: c.effort.actual === null ? '–' : formatNumber(c.effort.actual) }) }}</td>
						</tr>
					</tbody>
				</table>
				<div class="cockpit__actions">
					<NcButton v-if="c.can.manage"
						variant="secondary"
						data-test="add-actual"
						@click="openActual">
						{{ t('nc_nexus', 'Record actuals') }}
					</NcButton>
					<NcButton v-if="c.can.import"
						variant="tertiary"
						data-test="import-actuals"
						@click="openImport">
						{{ t('nc_nexus', 'Import CSV') }}
					</NcButton>
				</div>
			</section>
		</div>

		<!-- Meilensteine -->
		<section class="nx-view__section" data-test="cockpit-milestones">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Milestones') }}</h3>
				<NcButton v-if="c.can.manage"
					variant="secondary"
					data-test="add-milestone"
					@click="openMilestone(null)">
					{{ t('nc_nexus', 'Add milestone') }}
				</NcButton>
			</div>
			<p v-if="!c.milestones.length" class="nx-view__empty">
				{{ t('nc_nexus', 'Only governance-relevant milestones (gate dates, external commitments) are kept here; everything else stays in the planning tool.') }}
			</p>
			<div v-else class="nx-view__table">
				<div v-for="m in c.milestones"
					:key="m.id"
					class="nx-row cockpit__ms"
					:class="{ 'nx-row--click': c.can.manage, 'nx-row--warn': !m.actualDate && m.dueDate < today }"
					@click="c.can.manage && openMilestone(m)">
					<span>{{ m.name }}<small v-if="m.external" class="nx-chip nx-chip--submitted">{{ t('nc_nexus', 'external commitment') }}</small></span>
					<span class="nx-row__meta">{{ t('nc_nexus', 'due {date}', { date: formatIsoDay(m.dueDate) }) }}</span>
					<span class="nx-row__meta">{{ m.forecastDate ? t('nc_nexus', 'forecast {date}', { date: formatIsoDay(m.forecastDate) }) : '' }}</span>
					<span class="nx-row__meta">{{ m.actualDate ? t('nc_nexus', 'reached {date}', { date: formatIsoDay(m.actualDate) }) : '' }}</span>
				</div>
			</div>
		</section>

		<!-- Risiken und Issues -->
		<section class="nx-view__section" data-test="cockpit-risks">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Risks and issues') }}</h3>
				<div v-if="c.can.manage" class="cockpit__actions">
					<NcButton variant="secondary" data-test="add-risk" @click="openRisk(null)">
						{{ t('nc_nexus', 'Add risk') }}
					</NcButton>
					<NcButton variant="secondary" data-test="add-issue" @click="openIssue(null)">
						{{ t('nc_nexus', 'Add issue') }}
					</NcButton>
				</div>
			</div>
			<p v-if="!risks.length && !issues.length" class="nx-view__empty">
				{{ t('nc_nexus', 'No risks or issues recorded.') }}
			</p>
			<div v-else class="nx-view__table">
				<div v-for="r in risks"
					:key="'r' + r.id"
					class="nx-row cockpit__risk"
					:class="{ 'nx-row--click': r.can.edit }"
					:data-test="'risk-' + r.id"
					@click="r.can.edit && openRisk(r)">
					<span class="cockpit__score" :class="scoreClass(r.score)">{{ r.score }}</span>
					<div class="nx-row__name">
						<span class="nx-row__title">{{ r.title }}</span>
						<span class="nx-row__meta">
							{{ t('nc_nexus', 'Risk') }} · {{ riskCategoryLabel(r.category) }} · {{ riskStatusLabel(r.status) }} · {{ r.ownerId }}
							<template v-if="r.residualScore !== null"> · {{ t('nc_nexus', 'residual {s}', { s: r.residualScore }) }}</template>
							<template v-if="r.crossProject"> · {{ t('nc_nexus', 'affects {n} projects', { n: r.projects.length }) }}</template>
						</span>
					</div>
					<span class="nx-row__meta">{{ r.mitigation || t('nc_nexus', 'no mitigation') }}</span>
					<span>
						<span v-if="r.escalated" class="nx-chip nx-chip--rejected">{{ t('nc_nexus', 'escalated') }}</span>
						<span v-else-if="r.escalationHint" class="nx-chip nx-chip--on-hold" data-test="risk-hint">{{ t('nc_nexus', 'escalation recommended') }}</span>
					</span>
					<NcButton v-if="c.can.manage"
						variant="tertiary"
						:aria-label="t('nc_nexus', 'Add action')"
						@click.stop="openAction('risk', r.id)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClipboardPlusOutline" />
						</template>
					</NcButton>
				</div>
				<div v-for="i in issues"
					:key="'i' + i.id"
					class="nx-row cockpit__risk"
					:class="{ 'nx-row--click': i.can.edit }"
					:data-test="'issue-' + i.id"
					@click="i.can.edit && openIssue(i)">
					<span class="nx-chip" :class="i.severity === 'critical' ? 'nx-chip--rejected' : 'nx-chip--on-hold'">{{ severityLabel(i.severity) }}</span>
					<div class="nx-row__name">
						<span class="nx-row__title">{{ i.title }}</span>
						<span class="nx-row__meta">{{ t('nc_nexus', 'Issue') }} · {{ riskStatusLabel(i.status) }} · {{ i.ownerId }}{{ i.dueDate ? ' · ' + formatIsoDay(i.dueDate) : '' }}</span>
					</div>
					<span class="nx-row__meta">{{ i.actions.length ? n('nc_nexus', '%n action', '%n actions', i.actions.length) : '' }}</span>
					<span><span v-if="i.escalationLevel" class="nx-chip nx-chip--rejected">{{ escalationLabel(i.escalationLevel) }}</span></span>
					<NcButton v-if="c.can.manage"
						variant="tertiary"
						:aria-label="t('nc_nexus', 'Add action')"
						@click.stop="openAction('issue', i.id)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClipboardPlusOutline" />
						</template>
					</NcButton>
				</div>
			</div>
		</section>

		<!-- Change Requests -->
		<section class="nx-view__section" data-test="cockpit-changes">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Change requests') }}</h3>
				<NcButton v-if="c.can.manage && active"
					variant="secondary"
					data-test="add-change"
					@click="openChange(null)">
					{{ t('nc_nexus', 'New change request') }}
				</NcButton>
			</div>
			<p v-if="!changes.length" class="nx-view__empty">
				{{ t('nc_nexus', 'Changes to scope, schedule, budget, resources or benefit go through a change request with impact analysis.') }}
			</p>
			<div v-else class="nx-view__table">
				<div v-for="ch in changes"
					:key="ch.id"
					class="nx-row cockpit__change"
					:data-test="'change-' + ch.id">
					<span class="nx-chip" :class="{ incorporated: 'nx-chip--accepted', rejected: 'nx-chip--rejected', submitted: 'nx-chip--submitted' }[ch.status] || 'nx-chip--draft'">{{ changeStatusLabel(ch.status) }}</span>
					<div class="nx-row__name">
						<span class="nx-row__title">{{ ch.title }}<small v-if="ch.origin === 'system'"> · {{ t('nc_nexus', 'created automatically') }}</small></span>
						<span class="nx-row__meta">
							{{ changeTypeLabel(ch.changeType) }}
							<template v-if="ch.deltaBudget"> · {{ formatMoney(ch.deltaBudget) }}</template>
							<template v-if="ch.deltaDays"> · {{ t('nc_nexus', '{n} days', { n: ch.deltaDays }) }}</template>
							<template v-if="ch.deltaPersonDays"> · {{ t('nc_nexus', '{n} PD', { n: formatNumber(Object.values(ch.deltaPersonDays).reduce((s, v) => s + v, 0)) }) }}</template>
						</span>
					</div>
					<span class="nx-row__meta" data-test="change-level">
						{{ ch.reasons.length ? t('nc_nexus', 'above threshold: {r}', { r: ch.reasons.map(thresholdReasonLabel).join(', ') }) : t('nc_nexus', 'below threshold – project level decides') }}
						<template v-if="ch.bodyName"> · {{ ch.bodyName }}</template>
					</span>
					<div class="nx-row__actions">
						<NcButton v-if="ch.can.edit" variant="tertiary" @click="openChange(ch)">
							{{ t('nc_nexus', 'Edit') }}
						</NcButton>
						<NcButton v-if="ch.can.submit"
							variant="secondary"
							data-test="submit-change"
							@click="submit(ch)">
							{{ t('nc_nexus', 'Submit') }}
						</NcButton>
						<NcButton v-if="ch.can.decide"
							variant="primary"
							data-test="decide-change"
							@click="openDecision(ch)">
							{{ t('nc_nexus', 'Decide') }}
						</NcButton>
					</div>
				</div>
			</div>
		</section>

		<!-- Berichte und Baselines -->
		<div class="cockpit__grid">
			<section class="cockpit__card" data-test="cockpit-reports">
				<h3>{{ t('nc_nexus', 'Status reports') }}</h3>
				<p v-if="!c.reports.length" class="nx-hint">
					{{ t('nc_nexus', 'No status report yet.') }}
				</p>
				<ul class="cockpit__list">
					<li v-for="r in c.reports" :key="r.id">
						<span class="cockpit__dots">
							<span v-for="d in dims"
								:key="d"
								class="cockpit__dot"
								:class="'cockpit__dot--' + r['rag' + d[0].toUpperCase() + d.slice(1)]"
								:title="dimensionRagLabel(d)" />
						</span>
						<b>{{ r.period }}</b> · {{ r.createdBy }}<template v-if="r.achievements">
							· {{ r.achievements }}
						</template>
					</li>
				</ul>
			</section>
			<section class="cockpit__card" data-test="cockpit-baselines">
				<h3>{{ t('nc_nexus', 'Baselines') }}</h3>
				<ul class="cockpit__list">
					<li v-for="b in c.baselines" :key="b.id">
						<b>{{ t('nc_nexus', 'Version {v}', { v: b.version }) }}</b> · {{ b.note }} · {{ formatMoney(b.budgetFrame) }} · {{ formatNumber(b.personDays) }} {{ t('nc_nexus', 'PD') }} · {{ formatIsoDay(b.plannedEnd) || '–' }}
					</li>
				</ul>
			</section>
		</div>

		<!-- Nachkalkulation -->
		<section v-if="c.postCalculation && c.postCalculation.hasBaseline && c.postCalculation.pools.length"
			class="nx-view__section"
			data-test="cockpit-postcalc">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Post-calculation') }}</h3>
			</div>
			<p class="nx-hint">
				{{ t('nc_nexus', 'Actual person days per pool against the original estimate (baseline 1) and the current baseline. Completed projects feed the correction factors for new estimates.') }}
			</p>
			<table class="cockpit__table cockpit__postcalc">
				<thead>
					<tr>
						<th>{{ t('nc_nexus', 'Pool') }}</th>
						<th>{{ t('nc_nexus', 'Estimate (baseline 1)') }}</th>
						<th>{{ t('nc_nexus', 'Current baseline') }}</th>
						<th>{{ t('nc_nexus', 'Actual') }}</th>
						<th>{{ t('nc_nexus', 'Factor') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="r in c.postCalculation.pools" :key="r.poolId" :data-test="'cockpit-postcalc-' + r.poolId">
						<td>{{ poolName(r.poolId) }}</td>
						<td>{{ formatNumber(r.estimate) }}</td>
						<td>{{ formatNumber(r.current) }}</td>
						<td>{{ formatNumber(r.actual) }}</td>
						<td>{{ r.factor === null ? t('nc_nexus', 'unplanned') : formatFactor(r.factor) }}</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<th>{{ t('nc_nexus', 'Total') }}</th>
						<th>{{ formatNumber(c.postCalculation.estimate) }}</th>
						<th>{{ formatNumber(c.postCalculation.current) }}</th>
						<th>{{ formatNumber(c.postCalculation.actual) }}</th>
						<th data-test="cockpit-postcalc-factor">
							{{ formatFactor(c.postCalculation.factor) }}
						</th>
					</tr>
				</tfoot>
			</table>
		</section>

		<FormDialog v-if="form"
			:key="form.key"
			:title="form.title"
			:subtitle="form.subtitle || ''"
			:fields="form.fields"
			:initial="form.initial || {}"
			:submit="form.submit"
			:submit-text="form.submitText || ''"
			:success-text="form.successText || ''"
			:size="form.size || 'normal'"
			:data-test="form.dataTest"
			@close="form = null"
			@saved="onSaved">
			<template v-if="form.extraDelete" #extra-actions>
				<NcButton variant="error" data-test="delete-milestone" @click="removeMilestone">
					{{ t('nc_nexus', 'Delete') }}
				</NcButton>
			</template>
			<template v-if="form.findings" #before>
				<div class="cockpit__hint-box">
					<b>{{ t('nc_nexus', 'Health indicator') }}: {{ ragLabel(c.health.level) }}</b>
					<ul>
						<li v-for="(f, i) in c.health.findings" :key="i">
							{{ dimensionRagLabel(f.dimension) }}: {{ healthRuleLabel(f.rule) }} ({{ f.detail }})
						</li>
					</ul>
					<p class="nx-hint">
						{{ t('nc_nexus', 'If you report green where the indicator is red, please explain why.') }}
					</p>
				</div>
			</template>
		</FormDialog>
	</div>
</template>

<script>
import { mdiArrowLeft, mdiClipboardPlusOutline, mdiPencil } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import FormDialog from './FormDialog.vue'
import {
	addActual, addAction, addChange, addIssue, addMilestone, addReport, addRisk, decideChange, deleteMilestone, errorMessage,
	getProject, importActuals, projectChanges, projectIssues, projectRisks, reportTemplate, submitChange, updateChange,
	updateIssue, updateMilestone, updateProject, updateRisk,
} from '../api.js'
import {
	changeStatusLabel, changeTypeLabel, dimensionRagLabel, escalationLabel, formatFactor, formatIsoDay, formatMoney, formatNumber, healthRuleLabel,
	healthSourceLabel, ragChip, ragLabel, riskCategoryLabel, riskStatusLabel, severityLabel, statusChip, statusLabel, thresholdReasonLabel, today,
} from '../labels.js'

const RAG = () => ['green', 'yellow', 'red'].map(v => ({ value: v, label: ragLabel(v) }))
const SCALE = () => [1, 2, 3, 4, 5].map(v => ({ value: v, label: String(v) }))

export default {
	name: 'ProjectCockpit',
	components: { FormDialog, NcButton, NcIconSvgWrapper },
	props: {
		projectId: { type: Number, required: true },
		pools: { type: Array, default: () => [] },
	},
	emits: ['back', 'open-demand', 'changed'],
	setup() {
		return {
			mdiArrowLeft,
			mdiClipboardPlusOutline,
			mdiPencil,
			n,
			t,
			changeStatusLabel,
			changeTypeLabel,
			dimensionRagLabel,
			escalationLabel,
			formatFactor,
			formatIsoDay,
			formatMoney,
			formatNumber,
			healthRuleLabel,
			healthSourceLabel,
			ragChip,
			ragLabel,
			riskCategoryLabel,
			riskStatusLabel,
			severityLabel,
			statusLabel,
			thresholdReasonLabel,
			dims: ['scope', 'time', 'cost', 'risk'],
			today: today(),
		}
	},
	data() {
		return { c: null, risks: [], issues: [], changes: [], form: null, formKey: 0 }
	},
	computed: {
		active() {
			return ['released', 'in_execution', 'paused'].includes(this.c?.demand.status)
		},
		statusChipClass() {
			return 'nx-chip--' + statusChip(this.c.demand.status)
		},
	},
	watch: {
		projectId() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		poolName(id) {
			return this.pools.find(p => p.id === id)?.name ?? '?'
		},
		async load() {
			try {
				this.c = await getProject(this.projectId)
				this.risks = await projectRisks(this.projectId)
				this.issues = await projectIssues(this.projectId)
				this.changes = await projectChanges(this.projectId)
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		show(form) {
			this.form = { ...form, key: ++this.formKey }
		},
		async onSaved() {
			await this.load()
			this.$emit('changed')
		},
		scoreClass(score) {
			return score >= 15 ? 'cockpit__score--red' : (score >= 8 ? 'cockpit__score--yellow' : 'cockpit__score--green')
		},
		openProject() {
			const p = this.c.project
			this.show({
				title: t('nc_nexus', 'Project master data'),
				dataTest: 'project-dialog',
				initial: { managerId: p.managerId ?? '', sponsorId: p.sponsorId ?? '', plannedStart: p.plannedStart ?? '', plannedEnd: p.plannedEnd ?? '', benefitReviewDue: p.benefitReviewDue ?? '', committed: p.committed },
				fields: [
					{ key: 'managerId', label: t('nc_nexus', 'Project manager'), type: 'user' },
					{ key: 'sponsorId', label: t('nc_nexus', 'Sponsor'), type: 'user' },
					{ key: 'plannedStart', label: t('nc_nexus', 'Planned start'), type: 'date' },
					{ key: 'plannedEnd', label: t('nc_nexus', 'Planned end'), type: 'date' },
					{ key: 'benefitReviewDue', label: t('nc_nexus', 'Benefit review (G6) due'), type: 'date' },
					{ key: 'committed', label: t('nc_nexus', 'Committed (orders, contracts) €'), type: 'number', min: 0, decimals: 0 },
				],
				submit: (v) => updateProject(this.projectId, v),
				successText: t('nc_nexus', 'Project saved'),
			})
		},
		async openReport() {
			try {
				const tpl = await reportTemplate(this.projectId)
				this.show({
					title: t('nc_nexus', 'Status report'),
					subtitle: t('nc_nexus', 'Prefilled from the previous report; actuals so far: {cost} cost, {effort} PD.', { cost: formatMoney(tpl.prefilled.costActual), effort: tpl.prefilled.effortActual === null ? '–' : formatNumber(tpl.prefilled.effortActual) }),
					size: 'large',
					dataTest: 'report-dialog',
					findings: true,
					allowUnchanged: true,
					initial: { ...tpl, contradictionReason: '' },
					fields: [
						{ key: 'period', label: t('nc_nexus', 'Period'), type: 'month', required: true },
						{ key: 'progressPct', label: t('nc_nexus', 'Progress (%)'), type: 'number', min: 0, max: 100, decimals: 0 },
						{ key: 'ragScope', label: dimensionRagLabel('scope'), type: 'select', options: RAG(), required: true },
						{ key: 'ragTime', label: dimensionRagLabel('time'), type: 'select', options: RAG(), required: true },
						{ key: 'ragCost', label: dimensionRagLabel('cost'), type: 'select', options: RAG(), required: true },
						{ key: 'ragRisk', label: dimensionRagLabel('risk'), type: 'select', options: RAG(), required: true },
						{ key: 'ragComment', label: t('nc_nexus', 'Comment on the traffic lights (required when red)'), type: 'textarea' },
						{ key: 'achievements', label: t('nc_nexus', 'Achievements'), type: 'textarea' },
						{ key: 'nextSteps', label: t('nc_nexus', 'Next steps'), type: 'textarea' },
						{ key: 'impediments', label: t('nc_nexus', 'Impediments'), type: 'textarea' },
						{ key: 'forecastEndDate', label: t('nc_nexus', 'Forecast end date'), type: 'date' },
						{ key: 'forecastCost', label: t('nc_nexus', 'Forecast at completion (€)'), type: 'number', min: 0, decimals: 0 },
						{ key: 'contradictionReason', label: t('nc_nexus', 'Explanation if the report contradicts the indicator'), type: 'textarea' },
					],
					submit: (v) => addReport(this.projectId, v),
					submitText: t('nc_nexus', 'Submit report'),
					successText: t('nc_nexus', 'Status report submitted'),
				})
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		openMilestone(m) {
			this.show({
				title: m ? m.name : t('nc_nexus', 'New milestone'),
				dataTest: 'milestone-dialog',
				initial: m ? { ...m, external: m.external === 1 } : {},
				fields: [
					{ key: 'name', label: t('nc_nexus', 'Name'), required: true, wide: true },
					{ key: 'dueDate', label: t('nc_nexus', 'Due date'), type: 'date', required: true },
					{ key: 'forecastDate', label: t('nc_nexus', 'Forecast'), type: 'date' },
					{ key: 'actualDate', label: t('nc_nexus', 'Reached on'), type: 'date' },
					{ key: 'external', label: t('nc_nexus', 'External commitment'), type: 'checkbox' },
				],
				submit: (v) => m ? updateMilestone(this.projectId, m.id, v) : addMilestone(this.projectId, v),
				successText: t('nc_nexus', 'Milestone saved'),
			})
			if (m) {
				this.form.extraDelete = () => deleteMilestone(this.projectId, m.id)
			}
		},
		async removeMilestone() {
			try {
				await this.form.extraDelete()
				this.form = null
				await this.onSaved()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		openActual() {
			this.show({
				title: t('nc_nexus', 'Record actuals'),
				subtitle: t('nc_nexus', 'Effort in person days per pool is valued with the pool rate; external cost directly in euros.'),
				dataTest: 'actual-dialog',
				initial: { period: today().slice(0, 7), kind: 'effort', poolId: this.pools[0]?.id ?? null },
				fields: [
					{ key: 'period', label: t('nc_nexus', 'Month'), type: 'month', required: true },
					{ key: 'kind', label: t('nc_nexus', 'Kind'), type: 'select', options: [{ value: 'effort', label: t('nc_nexus', 'Effort (PD)') }, { value: 'cost', label: t('nc_nexus', 'External cost (€)') }], required: true },
					{ key: 'poolId', label: t('nc_nexus', 'Pool'), type: 'select', options: this.pools.map(p => ({ value: p.id, label: p.name })), show: (v) => v.kind === 'effort', required: true },
					{ key: 'amount', label: t('nc_nexus', 'Value'), type: 'number', min: 0, required: true },
				],
				submit: (v) => addActual(this.projectId, v),
				successText: t('nc_nexus', 'Actuals recorded'),
			})
		},
		openImport() {
			this.show({
				title: t('nc_nexus', 'Import actuals (CSV)'),
				subtitle: t('nc_nexus', 'One line per value: project key; month (YYYY-MM); kind (effort or cost); pool code (effort only); value. A header line is ignored.'),
				dataTest: 'import-dialog',
				size: 'large',
				fields: [{ key: 'csv', label: 'CSV', type: 'textarea', required: true }],
				submit: async (v) => {
					const result = await importActuals(v.csv)
					showSuccess(t('nc_nexus', '{n} values imported', { n: result.imported }))
					if (result.errors.length) {
						showError(t('nc_nexus', 'Not imported: {lines}', { lines: result.errors.join('; ') }))
					}
					return result
				},
				submitText: t('nc_nexus', 'Import'),
			})
		},
		openRisk(r) {
			this.show({
				title: r ? r.title : t('nc_nexus', 'New risk'),
				subtitle: t('nc_nexus', 'Probability and impact from 1 to 5; the score is their product. Residual risk after mitigation.'),
				size: 'large',
				dataTest: 'risk-dialog',
				initial: r ? { ...r, escalated: r.escalated === 1 } : { category: 'technical', probability: 3, impact: 3, status: 'open' },
				fields: [
					{ key: 'title', label: t('nc_nexus', 'Title'), required: true, wide: true },
					{ key: 'description', label: t('nc_nexus', 'Description'), type: 'textarea' },
					{ key: 'category', label: t('nc_nexus', 'Category'), type: 'select', options: ['technical', 'schedule', 'resources', 'supplier', 'financial', 'compliance', 'security', 'organizational'].map(v => ({ value: v, label: riskCategoryLabel(v) })) },
					{ key: 'status', label: t('nc_nexus', 'Status'), type: 'select', options: ['open', 'mitigated', 'occurred', 'closed'].map(v => ({ value: v, label: riskStatusLabel(v) })) },
					{ key: 'probability', label: t('nc_nexus', 'Probability'), type: 'select', options: SCALE(), required: true },
					{ key: 'impact', label: t('nc_nexus', 'Impact'), type: 'select', options: SCALE(), required: true },
					{ key: 'mitigation', label: t('nc_nexus', 'Mitigation'), type: 'textarea' },
					{ key: 'residualProbability', label: t('nc_nexus', 'Residual probability'), type: 'select', options: SCALE(), empty: '–' },
					{ key: 'residualImpact', label: t('nc_nexus', 'Residual impact'), type: 'select', options: SCALE(), empty: '–' },
					{ key: 'ownerId', label: t('nc_nexus', 'Risk owner'), type: 'user', required: true },
					{ key: 'dueDate', label: t('nc_nexus', 'Due'), type: 'date' },
					{ key: 'escalated', label: t('nc_nexus', 'Escalate to the portfolio (portfolio risk view)'), type: 'checkbox', wide: true },
				],
				submit: (v) => r ? updateRisk(r.id, v) : addRisk(this.projectId, v),
				successText: t('nc_nexus', 'Risk saved'),
			})
		},
		openIssue(i) {
			this.show({
				title: i ? i.title : t('nc_nexus', 'New issue'),
				subtitle: t('nc_nexus', 'An issue is a problem that has occurred, with severity and escalation level.'),
				dataTest: 'issue-dialog',
				initial: i ? { ...i } : { severity: 'medium', status: 'open', escalationLevel: 0 },
				fields: [
					{ key: 'title', label: t('nc_nexus', 'Title'), required: true, wide: true },
					{ key: 'description', label: t('nc_nexus', 'Description'), type: 'textarea' },
					{ key: 'severity', label: t('nc_nexus', 'Severity'), type: 'select', options: ['low', 'medium', 'high', 'critical'].map(v => ({ value: v, label: severityLabel(v) })) },
					{ key: 'status', label: t('nc_nexus', 'Status'), type: 'select', options: ['open', 'resolved'].map(v => ({ value: v, label: riskStatusLabel(v) })) },
					{ key: 'escalationLevel', label: t('nc_nexus', 'Escalation'), type: 'select', options: [0, 1, 2, 3].map(v => ({ value: v, label: escalationLabel(v) })) },
					{ key: 'ownerId', label: t('nc_nexus', 'Owner'), type: 'user' },
					{ key: 'dueDate', label: t('nc_nexus', 'Due'), type: 'date' },
				],
				submit: (v) => i ? updateIssue(this.projectId, i.id, v) : addIssue(this.projectId, v),
				successText: t('nc_nexus', 'Issue saved'),
			})
		},
		openAction(originType, originId) {
			this.show({
				title: t('nc_nexus', 'New action'),
				subtitle: t('nc_nexus', 'Actions appear in the decision follow-up with owner and due date.'),
				dataTest: 'action-dialog',
				fields: [
					{ key: 'title', label: t('nc_nexus', 'What'), required: true, wide: true },
					{ key: 'ownerId', label: t('nc_nexus', 'Owner'), type: 'user', required: true },
					{ key: 'dueDate', label: t('nc_nexus', 'Due'), type: 'date', required: true },
				],
				submit: (v) => addAction(this.projectId, { ...v, originType, originId }),
				successText: t('nc_nexus', 'Action created'),
			})
		},
		openChange(ch) {
			const pd = ch?.deltaPersonDays ?? {}
			const initial = ch ? { ...ch } : { changeType: 'scope' }
			for (const p of this.pools) {
				initial['pd' + p.id] = pd[p.id] ?? null
			}
			this.show({
				title: ch ? ch.title : t('nc_nexus', 'New change request'),
				subtitle: t('nc_nexus', 'Every change request quantifies its impact on cost, schedule, resources per pool, risk and benefit. Above the thresholds the responsible body decides.'),
				size: 'large',
				dataTest: 'change-dialog',
				initial,
				fields: [
					{ key: 'title', label: t('nc_nexus', 'Title'), required: true, wide: true },
					{ key: 'changeType', label: t('nc_nexus', 'Kind'), type: 'select', options: ['scope', 'time', 'cost', 'resource', 'benefit'].map(v => ({ value: v, label: changeTypeLabel(v) })) },
					{ key: 'description', label: t('nc_nexus', 'Description'), type: 'textarea' },
					{ type: 'heading', key: 'h1', label: t('nc_nexus', 'Impact analysis') },
					{ key: 'deltaBudget', label: t('nc_nexus', 'Budget change (€)'), type: 'number', decimals: 0 },
					{ key: 'deltaDays', label: t('nc_nexus', 'Schedule change (days)'), type: 'number', decimals: 0, grouping: false },
					...this.pools.map(p => ({ key: 'pd' + p.id, label: t('nc_nexus', 'Person days {pool}', { pool: p.name }) + (p.isBottleneck === 1 ? ' · ' + t('nc_nexus', 'bottleneck') : ''), type: 'number' })),
					{ key: 'deltaRisk', label: t('nc_nexus', 'Effect on risks'), type: 'textarea', required: true },
					{ key: 'deltaBenefit', label: t('nc_nexus', 'Effect on benefit'), type: 'textarea', required: true },
					{ key: 'impactAnalysis', label: t('nc_nexus', 'Impact analysis (cost, schedule, resources)'), type: 'textarea', required: true },
				],
				submit: (v) => {
					const deltaPersonDays = {}
					for (const p of this.pools) {
						if (v['pd' + p.id]) {
							deltaPersonDays[p.id] = v['pd' + p.id]
						}
						delete v['pd' + p.id]
					}
					const values = { ...v, deltaPersonDays }
					return ch ? updateChange(this.projectId, ch.id, values) : addChange(this.projectId, values)
				},
				successText: t('nc_nexus', 'Change request saved'),
			})
		},
		async submit(ch) {
			try {
				const result = await submitChange(this.projectId, ch.id)
				showSuccess(result.aboveThreshold ? t('nc_nexus', 'Submitted – above the threshold, the responsible body decides') : t('nc_nexus', 'Submitted – below the threshold, the project level decides'))
				await this.onSaved()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		openDecision(ch) {
			this.show({
				title: ch.title,
				subtitle: ch.reasons.length ? t('nc_nexus', 'above threshold: {r}', { r: ch.reasons.map(thresholdReasonLabel).join(', ') }) : t('nc_nexus', 'below threshold – project level decides'),
				dataTest: 'change-decision-dialog',
				initial: { outcome: 'approved' },
				fields: [
					{ key: 'outcome', label: t('nc_nexus', 'Decision'), type: 'select', options: [{ value: 'approved', label: t('nc_nexus', 'Approve – new baseline') }, { value: 'rejected', label: t('nc_nexus', 'Reject') }] },
					{ key: 'reason', label: t('nc_nexus', 'Reason'), type: 'textarea', required: true },
				],
				allowUnchanged: true,
				submit: (v) => decideChange(this.projectId, ch.id, v),
				submitText: t('nc_nexus', 'Decide'),
				successText: t('nc_nexus', 'Decision recorded'),
			})
		},
	},
}
</script>

<style scoped lang="scss">
.cockpit {
	padding: 8px 20px 20px 50px;

	&__facts {
		align-items: end;
		margin: 0 0 18px;
	}

	&__grid {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
		gap: 16px;
		margin-bottom: 24px;
	}

	&__card {
		padding: 12px 14px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);

		h3 {
			margin: 0 0 10px;
			font-size: 12px;
			font-weight: 700;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}

		h4 {
			margin: 0 0 6px;
			font-size: 13px;
		}
	}

	&__twin {
		display: grid;
		grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr);
		gap: 14px;
	}

	&__rag,
	&__findings,
	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
		font-size: 13px;

		li {
			display: flex;
			gap: 6px;
			align-items: center;
			padding: 2px 0;
		}

		small {
			color: var(--color-text-maxcontrast);
		}
	}

	&__dot {
		display: inline-block;
		flex: none;
		width: 12px;
		height: 12px;
		border-radius: 50%;
		background: var(--color-border-dark);

		&--green {
			background: var(--color-element-success, var(--color-success-text));
		}

		&--yellow {
			background: var(--color-element-warning, var(--color-warning-text));
		}

		&--red {
			background: var(--color-element-error, var(--color-error-text));
		}
	}

	&__dots {
		display: inline-flex;
		gap: 3px;
	}

	&__contra {
		color: var(--color-error-text);
		font-size: 12px;
	}

	&__ok {
		color: var(--color-success-text);
		font-size: 13px;
	}

	&__meta {
		margin: 6px 0 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__warn {
		color: var(--color-error-text);
	}

	&__table {
		width: 100%;
		font-size: 13px;
		font-variant-numeric: tabular-nums;

		td {
			padding: 3px 0;
		}

		td:last-child {
			text-align: end;
		}

		small {
			color: var(--color-text-maxcontrast);
		}
	}

	&__postcalc {
		max-width: 720px;

		th, td {
			padding: 4px 8px 4px 0;
			text-align: end;
		}

		th:first-child, td:first-child {
			text-align: start;
		}

		thead th {
			font-weight: normal;
			color: var(--color-text-maxcontrast);
		}

		tfoot th {
			border-top: 1px solid var(--color-border);
		}
	}

	&__actions {
		display: flex;
		gap: 6px;
		margin-top: 8px;
	}

	&__ms {
		grid-template-columns: minmax(0, 1.4fr) 150px 150px 150px;
		min-height: 44px;

		.nx-chip {
			margin-inline-start: 8px;
		}
	}

	&__risk {
		grid-template-columns: 70px minmax(0, 1.4fr) minmax(0, 1fr) auto 44px;
	}

	&__change {
		grid-template-columns: 170px minmax(0, 1fr) minmax(0, 1fr) auto;
	}

	&__score {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 34px;
		height: 28px;
		border-radius: 8px;
		font-weight: 700;
		color: #fff;

		&--green {
			background: var(--color-element-success, var(--color-success-text));
		}

		&--yellow {
			background: var(--color-element-warning, var(--color-warning-text));
		}

		&--red {
			background: var(--color-element-error, var(--color-error-text));
		}
	}

	&__hint-box {
		margin-bottom: 12px;
		padding: 8px 12px;
		border-radius: var(--border-radius-large, 10px);
		background: var(--color-background-dark);
		font-size: 13px;

		ul {
			margin: 4px 0;
			padding-inline-start: 18px;
		}
	}
}
</style>
