<template>
	<NxDialog :title="t('nc_nexus', 'Glossary')"
		:subtitle="t('nc_nexus', 'The most important terms of portfolio management in Nexus')"
		size="large"
		data-test="glossary-dialog"
		@close="$emit('close')">
		<div class="glossary__search" data-test="glossary-search">
			<NcTextField v-model="query"
				:label="t('nc_nexus', 'Search terms …')"
				:show-trailing-button="query !== ''"
				trailing-button-icon="close"
				@trailing-button-click="query = ''" />
		</div>
		<dl v-if="shown.length" class="glossary__list">
			<div v-for="entry in shown"
				:key="entry.term"
				class="glossary__entry"
				data-test="glossary-entry">
				<dt><strong>{{ entry.term }}</strong></dt>
				<dd>{{ entry.text }}</dd>
			</div>
		</dl>
		<p v-else class="glossary__empty">
			{{ t('nc_nexus', 'No matching term.') }}
		</p>
		<template #actions>
			<NcButton variant="primary" data-test="glossary-close" @click="$emit('close')">
				{{ t('nc_nexus', 'Close') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NxDialog from './NxDialog.vue'

// Fachbegriffe mit Erklärung in ein bis zwei Sätzen; Reihenfolge ergibt sich aus der Übersetzung
const entries = () => [
	{ term: t('nc_nexus', 'Demand'), text: t('nc_nexus', 'An idea, need or request from a department, submitted for a decision. Every project in the portfolio starts as a demand.') },
	{ term: t('nc_nexus', 'Triage'), text: t('nc_nexus', 'First review of a submitted demand by the portfolio office. It ends with a binding decision code: accept, further analysis, defer, reject or duplicate.') },
	{ term: t('nc_nexus', 'Decision code'), text: t('nc_nexus', 'The binding result of the triage. Each code has mandatory details, e.g. a resubmission date for “defer” or a reason for “reject”.') },
	{ term: t('nc_nexus', 'Query to requestor'), text: t('nc_nexus', 'Instead of a decision, the demand goes back to the requestor with a question and a due date. After the answer it is submitted and triaged again.') },
	{ term: t('nc_nexus', 'Resubmission'), text: t('nc_nexus', 'Date on which a deferred demand comes back to triage. It is mandatory so that deferred demands are not forgotten.') },
	{ term: t('nc_nexus', 'Duplicate'), text: t('nc_nexus', 'A demand that describes the same need as an existing one. It is merged into the leading demand, which continues alone.') },
	{ term: t('nc_nexus', 'Project class'), text: t('nc_nexus', 'Size category XS, S, M or L based on effort and risk. It determines the gate set and which body decides.') },
	{ term: t('nc_nexus', 'Portfolio office (PMO)'), text: t('nc_nexus', 'Central role that runs the process: triages demands, prepares decisions and monitors data quality. It may handle all submitted demands, outside the regular step only with a reason.') },
	{ term: t('nc_nexus', 'Portfolio board'), text: t('nc_nexus', 'Management body that decides on admission to the portfolio, priorities and releases. It decides at the gates assigned to it in the decision rights.') },
	{ term: t('nc_nexus', 'Body'), text: t('nc_nexus', 'Committee with seats and members (e.g. portfolio board, IT board) that votes on decisions in sessions. Deputies may vote on behalf of a member.') },
	{ term: t('nc_nexus', 'Quorum'), text: t('nc_nexus', 'Minimum number of votes required for a valid decision of a body. Without a quorum, the item has to be postponed.') },
	{ term: t('nc_nexus', 'Session'), text: t('nc_nexus', 'Meeting of a body with agenda, documents and votes. The decisions taken there go to the decision register.') },
	{ term: t('nc_nexus', 'Decision rights'), text: t('nc_nexus', 'Versioned matrix that defines which body decides on which gate, project class and decision type. Without a matching right, the board or PMO of the area decides directly.') },
	{ term: t('nc_nexus', 'Decision register'), text: t('nc_nexus', 'Complete, unchangeable record of all decisions with number, reason and mandate. A changed decision replaces the old one but does not delete it.') },
	{ term: t('nc_nexus', 'Stage-gate model'), text: t('nc_nexus', 'The life cycle of a project is divided into stages separated by decision points (gates G0–G6). The next stage only starts after a positive gate decision.') },
	{ term: t('nc_nexus', 'Gate'), text: t('nc_nexus', 'Decision point in the life cycle, e.g. G2 portfolio admission, G3 release, G5 acceptance, G6 benefit review. Each gate has a question, mandatory data and a responsible decision maker.') },
	{ term: t('nc_nexus', 'Gate set'), text: t('nc_nexus', 'Versioned set of gates that applies to a project class. Small projects (XS/S) use a shortened path with fewer gates.') },
	{ term: t('nc_nexus', 'Conditions'), text: t('nc_nexus', 'Requirements attached to a decision “approved with conditions”, each with an owner and a due date. Blocking conditions lock the next gate until they are fulfilled.') },
	{ term: t('nc_nexus', 'Budget tranche'), text: t('nc_nexus', 'Part of the budget released at a gate instead of the whole budget at once. This limits the risk of each decision; a cancellation only costs the current tranche.') },
	{ term: t('nc_nexus', 'Strategic objective'), text: t('nc_nexus', 'Goal set by management that demands and projects contribute to. The contribution to objectives flows into the assessment.') },
	{ term: t('nc_nexus', 'Investment bucket'), text: t('nc_nexus', 'Share of capacity reserved in advance for a type of project, e.g. mandatory, operations, growth, innovation. Projects only compete within their bucket, so that mandatory projects do not crowd out innovation.') },
	{ term: t('nc_nexus', 'Assessment model'), text: t('nc_nexus', 'Versioned catalogue of criteria with scales and weights (benefit, urgency, effort, risk). All demands are assessed with the same model so that they are comparable.') },
	{ term: t('nc_nexus', 'Value density'), text: t('nc_nexus', 'Key figure for prioritization: (benefit × urgency) ÷ (effort × risk). It shows the benefit per unit of scarce capacity and is the basis of the ranking.') },
	{ term: t('nc_nexus', 'Ranking'), text: t('nc_nexus', 'Order of the demands by value density within each bucket. The board may correct the order, but only with a reason.') },
	{ term: t('nc_nexus', 'Business case'), text: t('nc_nexus', 'Economic justification with costs, benefit items and assumptions. It is mandatory for portfolio admission (G2) and is updated at later gates.') },
	{ term: t('nc_nexus', 'Benefit register'), text: t('nc_nexus', 'List of all promised benefit items with target values and owners. At the benefit review (G6) the actual values are compared with the promise.') },
	{ term: t('nc_nexus', 'Resource pool'), text: t('nc_nexus', 'Group of people with similar skills (e.g. development, infrastructure) whose capacity is planned together. Bottleneck pools limit how many projects can run at the same time.') },
	{ term: t('nc_nexus', 'Capacity'), text: t('nc_nexus', 'Available working time of a pool per month in person-days (PD), after deducting operations and absence. Demand and capacity are compared in the heatmap.') },
	{ term: t('nc_nexus', 'Hard allocation'), text: t('nc_nexus', 'Firm commitment of capacity from a pool to a project, given at release (G3). Only hard allocations reduce the free capacity for other projects.') },
	{ term: t('nc_nexus', 'Portfolio'), text: t('nc_nexus', 'All demands and projects of the organization viewed together. Portfolio management decides which projects are done, in which order and with which resources.') },
	{ term: t('nc_nexus', 'Project baseline'), text: t('nc_nexus', 'Frozen plan of scope, dates, budget and benefit at release (G3). Each approved change request creates a new baseline version; earlier ones are kept for comparison.') },
	{ term: t('nc_nexus', 'Change request'), text: t('nc_nexus', 'Formal request to change scope, dates or budget of a running project. Above defined thresholds it has to be approved by the responsible body.') },
	{ term: t('nc_nexus', 'Status report'), text: t('nc_nexus', 'Regular report of the project lead with a self-assessed traffic light, progress and outlook. It is compared with the calculated health indicator.') },
	{ term: t('nc_nexus', 'Health indicator'), text: t('nc_nexus', 'Traffic light calculated from objective data, e.g. overdue milestones, forecast above budget or critical risks without action. If it contradicts the reported status, a reason is required.') },
	{ term: t('nc_nexus', 'Forecast at completion'), text: t('nc_nexus', 'Expected total cost at the end of the project (FAC), made up of actual costs so far and the estimated remaining effort. It is compared with the budget.') },
	{ term: t('nc_nexus', 'Risk'), text: t('nc_nexus', 'Possible future event that would endanger the project goals, assessed by probability and impact. Critical risks need an action with an owner.') },
	{ term: t('nc_nexus', 'Issue'), text: t('nc_nexus', 'A problem that has already occurred and affects the project. Unlike a risk, it must be solved, not just monitored.') },
	{ term: t('nc_nexus', 'Conflict'), text: t('nc_nexus', 'Capacity problem detected by the system, e.g. an overbooked pool or a bottleneck lasting several months. Open conflicts are escalated step by step if they are not resolved in time.') },
	{ term: t('nc_nexus', 'KPI'), text: t('nc_nexus', 'Key performance indicator of portfolio management, e.g. lead time from demand to decision or bottleneck utilization, with target and alert thresholds. It shows whether the process works.') },
]

export default {
	name: 'GlossaryDialog',
	components: { NcButton, NcTextField, NxDialog },
	emits: ['close'],
	setup() {
		return { t }
	},
	data() {
		return { query: '' }
	},
	computed: {
		sorted() {
			return entries().sort((a, b) => a.term.localeCompare(b.term))
		},
		shown() {
			const q = this.query.trim().toLowerCase()
			return q ? this.sorted.filter(e => (e.term + ' ' + e.text).toLowerCase().includes(q)) : this.sorted
		},
	},
}
</script>

<style scoped lang="scss">
.glossary__search {
	margin-bottom: 12px;
}

.glossary__list {
	display: grid;
	gap: 10px;
	margin: 0;
}

.glossary__entry {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);

	// globale Nextcloud-Stile für dt/dd (Breite, Einzug, Abstände) zurücksetzen
	dt,
	dd {
		display: block;
		width: auto;
		min-width: 0;
		margin: 0;
		padding: 0;
		text-align: start;
	}

	dt {
		font-weight: bold;
	}

	dd {
		margin-top: 2px;
		color: var(--color-text-maxcontrast);
	}
}

.glossary__empty {
	color: var(--color-text-maxcontrast);
}
</style>
