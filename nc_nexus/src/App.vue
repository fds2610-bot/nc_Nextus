<template>
	<NcContent app-name="nc_nexus">
		<NcAppNavigation :aria-label="t('nc_nexus', 'Nexus navigation')">
			<!-- Hauptaktion oben in der Navigation, wie in VertragsWerk, Mail und Deck -->
			<NcAppNavigationNew :text="t('nc_nexus', 'New demand')"
				data-test="new-demand"
				@click="openEditor(null)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
			</NcAppNavigationNew>

			<div class="nav-search">
				<NcTextField v-model="search"
					:label="t('nc_nexus', 'Search demands …')"
					:show-trailing-button="search !== ''"
					trailing-button-icon="close"
					@trailing-button-click="search = ''">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMagnify" :size="20" />
					</template>
				</NcTextField>
			</div>

			<NcAppNavigationList>
				<NcAppNavigationItem :name="t('nc_nexus', 'All demands')"
					:active="view === 'demands' && filter.kind === 'all'"
					data-test="nav-all"
					@click="setFilter('all')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiInboxArrowDown" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="demands.length" :count="demands.length" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('nc_nexus', 'Decision register')"
					:active="view === 'decisions'"
					data-test="nav-decisions"
					@click="view = 'decisions'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiScaleBalance" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="showSessions"
					:name="t('nc_nexus', 'Sessions')"
					:active="view === 'sessions'"
					data-test="nav-sessions"
					@click="view = 'sessions'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCalendarAccount" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('nc_nexus', 'Decision follow-up')"
					:active="view === 'conditions'"
					data-test="nav-conditions"
					@click="view = 'conditions'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClipboardCheckOutline" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem :name="t('nc_nexus', 'Strategic objectives')"
					:active="view === 'objectives'"
					data-test="nav-objectives"
					@click="view = 'objectives'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiBullseyeArrow" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('nc_nexus', 'Benefit register')"
					:active="view === 'benefits'"
					data-test="nav-benefits"
					@click="view = 'benefits'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCashMultiple" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="poolInfo.canSee"
					:name="t('nc_nexus', 'Portfolio')"
					:active="view === 'portfolio'"
					data-test="nav-portfolio"
					@click="view = 'portfolio'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiViewGridOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="poolInfo.canSee"
					:name="t('nc_nexus', 'Prioritization')"
					:active="view === 'ranking'"
					data-test="nav-ranking"
					@click="view = 'ranking'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiSortNumericAscending" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem v-if="poolInfo.canSee"
					:name="t('nc_nexus', 'Capacity')"
					:active="view === 'capacity'"
					data-test="nav-capacity"
					@click="view = 'capacity'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiChartBox" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem v-model:open="groups.steering"
					:name="t('nc_nexus', 'Steering')"
					:allow-collapse="true"
					data-test="nav-group-steering"
					@click="groups.steering = !groups.steering">
					<template #icon>
						<NcIconSvgWrapper :path="mdiSteering" />
					</template>
					<NcAppNavigationItem :name="t('nc_nexus', 'Projects')"
						:active="view === 'projects'"
						data-test="nav-projects"
						@click="openProjects(null)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBriefcaseOutline" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('nc_nexus', 'Portfolio risks')"
						:active="view === 'risks'"
						data-test="nav-risks"
						@click="view = 'risks'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiAlertOutline" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem v-if="poolInfo.canSee"
						:name="t('nc_nexus', 'Resource conflicts')"
						:active="view === 'conflicts'"
						data-test="nav-conflicts"
						@click="view = 'conflicts'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiSwapHorizontal" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem v-if="poolInfo.canSee"
						:name="t('nc_nexus', 'Key figures')"
						:active="view === 'kpis'"
						data-test="nav-kpis"
						@click="view = 'kpis'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiSpeedometer" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<NcAppNavigationItem v-if="me.canTriage"
					v-model:open="groups.triage"
					:name="t('nc_nexus', 'Triage')"
					:allow-collapse="true"
					data-test="nav-group-triage"
					@click="groups.triage = !groups.triage">
					<template #icon>
						<NcIconSvgWrapper :path="mdiGavel" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="triageTotal && !groups.triage" :count="triageTotal" type="highlighted" />
					</template>
					<NcAppNavigationItem :name="t('nc_nexus', 'To triage')"
						:active="view === 'demands' && filter.kind === 'queue' && filter.value === 'triage'"
						data-test="nav-queue-triage"
						@click="setFilter('queue', 'triage')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiGavel" />
						</template>
						<template #counter>
							<NcCounterBubble v-if="queue('triage').length" :count="queue('triage').length" type="highlighted" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem :name="t('nc_nexus', 'Resubmission due')"
						:active="view === 'demands' && filter.kind === 'queue' && filter.value === 'resubmission'"
						data-test="nav-queue-resubmission"
						@click="setFilter('queue', 'resubmission')">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCalendarClock" />
						</template>
						<template #counter>
							<NcCounterBubble v-if="queue('resubmission').length" :count="queue('resubmission').length" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<NcAppNavigationItem v-if="queue('analysis').length"
					:name="t('nc_nexus', 'My analyses')"
					:active="view === 'demands' && filter.kind === 'queue' && filter.value === 'analysis'"
					data-test="nav-queue-analysis"
					@click="setFilter('queue', 'analysis')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiMagnifyScan" />
					</template>
					<template #counter>
						<NcCounterBubble :count="queue('analysis').length" type="highlighted" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem v-model:open="groups.status"
					:name="t('nc_nexus', 'Status')"
					:allow-collapse="true"
					data-test="nav-group-status"
					@click="groups.status = !groups.status">
					<template #icon>
						<NcIconSvgWrapper :path="mdiProgressCheck" />
					</template>
					<NcAppNavigationItem v-for="status in shownStatuses"
						:key="status"
						:name="statusLabel(status)"
						:active="view === 'demands' && filter.kind === 'status' && filter.value === status"
						:data-test="'nav-status-' + status"
						@click="setFilter('status', status)">
						<template #icon>
							<NcIconSvgWrapper :path="statusIcon(status)" />
						</template>
						<template #counter>
							<NcCounterBubble v-if="countBy('status', status)" :count="countBy('status', status)" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>

				<NcAppNavigationItem v-model:open="groups.types"
					:name="t('nc_nexus', 'Demand types')"
					:allow-collapse="true"
					data-test="nav-group-types"
					@click="groups.types = !groups.types">
					<template #icon>
						<NcIconSvgWrapper :path="mdiShapeOutline" />
					</template>
					<NcAppNavigationItem v-for="type in types"
						:key="type"
						:name="typeLabel(type)"
						:active="view === 'demands' && filter.kind === 'type' && filter.value === type"
						@click="setFilter('type', type)">
						<template #icon>
							<NcAppNavigationIconBullet :color="typeColor(type)" />
						</template>
						<template #counter>
							<NcCounterBubble v-if="countBy('demandType', type)" :count="countBy('demandType', type)" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationItem>
			</NcAppNavigationList>

			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem :name="t('nc_nexus', 'Glossary')"
						data-test="nav-glossary"
						@click="glossaryOpen = true">
						<template #icon>
							<NcIconSvgWrapper :path="mdiBookOpenVariant" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem v-if="poolInfo.canSee"
						:name="t('nc_nexus', 'Assessment model')"
						:active="view === 'model'"
						data-test="nav-model"
						@click="view = 'model'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTuneVariant" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem v-if="me.canTriage || me.canManageOrg"
						:name="t('nc_nexus', 'Data quality')"
						:active="view === 'quality'"
						data-test="nav-quality"
						@click="view = 'quality'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiShieldCheckOutline" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem v-if="me.canManageOrg"
						:name="t('nc_nexus', 'Governance')"
						:active="view === 'governance'"
						data-test="nav-governance"
						@click="view = 'governance'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiSitemapOutline" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem v-if="me.canManageOrg"
						:name="t('nc_nexus', 'Organization')"
						:active="view === 'org'"
						data-test="nav-org"
						@click="openOrg">
						<template #icon>
							<NcIconSvgWrapper :path="mdiDomain" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<BenefitRegister v-if="view === 'benefits'"
				:refresh-key="refresh"
				@open="openEditor" />
			<RankingView v-else-if="view === 'ranking'"
				:refresh-key="refresh"
				@open="openEditor" />
			<ScoringModelView v-else-if="view === 'model'"
				:model="scoring"
				@changed="loadOrg" />
			<ObjectivesView v-else-if="view === 'objectives'"
				:objectives="objectiveInfo.objectives"
				:can-manage="objectiveInfo.canManage"
				@changed="loadOrg" />
			<PortfolioView v-else-if="view === 'portfolio'"
				:org-units="orgUnits"
				:refresh-key="refresh"
				@filter="filterPortfolio" />
			<CapacityView v-else-if="view === 'capacity'"
				:org-units="orgUnits"
				:refresh-key="refresh"
				@open="openEditor" />
			<DecisionRegister v-else-if="view === 'decisions'"
				:org-units="orgUnits"
				:refresh-key="refresh"
				@open="openEditor" />
			<ProjectsView v-else-if="view === 'projects'"
				:pools="poolInfo.pools"
				:open-id="projectOpenId"
				:refresh-key="refresh"
				@open="openEditor"
				@changed="onChanged" />
			<RisksView v-else-if="view === 'risks'"
				:refresh-key="refresh"
				@project="openProjects" />
			<ConflictsView v-else-if="view === 'conflicts'"
				:refresh-key="refresh"
				@open="openProjects" />
			<KpiView v-else-if="view === 'kpis'"
				:refresh-key="refresh" />
			<SessionsView v-else-if="view === 'sessions'"
				:me="me.userId"
				:refresh-key="refresh"
				@changed="onChanged" />
			<ConditionsView v-else-if="view === 'conditions'"
				:me="me.userId"
				:refresh-key="refresh"
				@open="openEditor"
				@changed="onChanged" />
			<QualityView v-else-if="view === 'quality'"
				:refresh-key="refresh"
				@open="openEditor" />
			<GovernanceView v-else-if="view === 'governance'"
				@changed="loadOrg" />
			<OrgView v-else-if="view === 'org'"
				:units="orgUnits"
				:assignments="assignments"
				@changed="loadOrg" />
			<DemandList v-else
				:demands="visibleDemands"
				:all-demands="demands"
				:title="listTitle"
				:loading="loading"
				:org-units="orgUnits"
				:filtered="filter.kind !== 'all' || search !== ''"
				@open="openEditor"
				@create="openEditor(null)"
				@changed="load" />
			<DemandEditor v-if="editorOpen"
				:demand-id="editorId"
				:org-units="orgUnits"
				:demands="demands"
				:pools="poolInfo.pools"
				:objectives="objectiveInfo.objectives"
				:scoring="scoring"
				@close="editorOpen = false"
				@open-project="id => { editorOpen = false; openProjects(id) }"
				@changed="onChanged" />
			<GlossaryDialog v-if="glossaryOpen" @close="glossaryOpen = false" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { mdiAccountQuestion, mdiBookOpenVariant, mdiAlertOutline, mdiArchiveOutline, mdiBriefcaseOutline, mdiSpeedometer, mdiSteering, mdiSwapHorizontal, mdiProgressCheck, mdiShapeOutline, mdiBullseyeArrow, mdiCancel, mdiFlagCheckered, mdiPlayCircleOutline, mdiCalendarAccount, mdiClipboardCheckOutline, mdiShieldCheckOutline, mdiSitemapOutline, mdiCashMultiple, mdiCalendarClock, mdiChartBox, mdiCheckCircleOutline, mdiCloseCircleOutline, mdiContentDuplicate, mdiDomain, mdiFileDocumentEditOutline, mdiGavel, mdiInboxArrowDown, mdiMagnify, mdiMagnifyScan, mdiPauseCircleOutline, mdiPlus, mdiScaleBalance, mdiSend, mdiSortNumericAscending, mdiTuneVariant, mdiViewGridOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationIconBullet from '@nextcloud/vue/components/NcAppNavigationIconBullet'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DemandEditor from './components/DemandEditor.vue'
import GlossaryDialog from './components/GlossaryDialog.vue'
import DemandList from './views/DemandList.vue'
import BenefitRegister from './views/BenefitRegister.vue'
import CapacityView from './views/CapacityView.vue'
import PortfolioView from './views/PortfolioView.vue'
import RankingView from './views/RankingView.vue'
import ScoringModelView from './views/ScoringModelView.vue'
import DecisionRegister from './views/DecisionRegister.vue'
import ObjectivesView from './views/ObjectivesView.vue'
import OrgView from './views/OrgView.vue'
import ConditionsView from './views/ConditionsView.vue'
import ConflictsView from './views/ConflictsView.vue'
import KpiView from './views/KpiView.vue'
import ProjectsView from './views/ProjectsView.vue'
import RisksView from './views/RisksView.vue'
import GovernanceView from './views/GovernanceView.vue'
import QualityView from './views/QualityView.vue'
import SessionsView from './views/SessionsView.vue'
import { errorMessage, getMe, listDemands, listObjectives, listOrgUnits, listPools, listRoleAssignments, scoringModel } from './api.js'
import { PHASE_OF, phaseLabel, STATUSES, statusLabel, typeColor, typeLabel } from './labels.js'

export default {
	name: 'App',
	components: {
		DemandEditor,
		DemandList,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationIconBullet,
		NcAppNavigationItem,
		NcAppNavigationList,
		NcAppNavigationNew,
		NcContent,
		NcCounterBubble,
		NcIconSvgWrapper,
		NcTextField,
		BenefitRegister,
		CapacityView,
		ConditionsView,
		ConflictsView,
		KpiView,
		ProjectsView,
		RisksView,
		GovernanceView,
		QualityView,
		SessionsView,
		GlossaryDialog,
		DecisionRegister,
		ObjectivesView,
		OrgView,
		PortfolioView,
		RankingView,
		ScoringModelView,
	},
	setup() {
		return { mdiAlertOutline, mdiBookOpenVariant, mdiBriefcaseOutline, mdiSpeedometer, mdiSteering, mdiSwapHorizontal, mdiProgressCheck, mdiShapeOutline, mdiCalendarAccount, mdiClipboardCheckOutline, mdiShieldCheckOutline, mdiSitemapOutline, mdiCashMultiple, mdiSortNumericAscending, mdiTuneVariant, mdiBullseyeArrow, mdiCalendarClock, mdiChartBox, mdiViewGridOutline, mdiGavel, mdiMagnifyScan, mdiScaleBalance, mdiDomain, mdiFileDocumentEditOutline, mdiInboxArrowDown, mdiMagnify, mdiPlus, mdiSend, t, statusLabel, typeColor, typeLabel }
	},
	data() {
		return {
			glossaryOpen: false,
			demands: [],
			loading: true,
			editorOpen: false,
			editorId: null,
			search: '',
			filter: { kind: 'all', value: null },
			types: loadState('nc_nexus', 'demand_types', []),
			view: 'demands',
			// Gruppen der Navigation, standardmäßig eingeklappt
			groups: { steering: false, triage: false, status: false, types: false },
			projectOpenId: null,
			refresh: 0,
			me: { canManageOrg: false, canTriage: false, roles: [], bodies: [] },
			orgUnits: [],
			assignments: [],
			poolInfo: { canSee: false, canManage: false, pools: [] },
			objectiveInfo: { canManage: false, objectives: [] },
			scoring: null,
		}
	},
	computed: {
		// Sitzungen sehen PMO, Gremiumsmitglieder sowie Board und Revision
		// Summe der Arbeitsvorräte der Triage (für die eingeklappte Gruppe)
		triageTotal() {
			return this.queue('triage').length + this.queue('resubmission').length
		},
		showSessions() {
			return this.me.canManageOrg || (this.me.bodies ?? []).length > 0
				|| this.me.roles.some(r => ['board_member', 'auditor', 'portfolio_manager'].includes(r.role))
		},
		visibleDemands() {
			const query = this.search.trim().toLowerCase()
			return this.demands.filter(demand => {
				if (this.filter.kind === 'status' && demand.status !== this.filter.value) {
					return false
				}
				if (this.filter.kind === 'type' && demand.demandType !== this.filter.value) {
					return false
				}
				if (this.filter.kind === 'queue' && !this.queue(this.filter.value).includes(demand)) {
					return false
				}
				if (this.filter.kind === 'portfolio' && !this.inPortfolioCell(demand, this.filter.value)) {
					return false
				}
				return !query || [demand.key, demand.title, demand.problemStatement, demand.requestorId]
					.some(text => (text || '').toLowerCase().includes(query))
			})
		},
		// Entwurf und Eingereicht immer, weitere Status nur, wenn es Anträge darin gibt
		shownStatuses() {
			return STATUSES.filter(s => ['draft', 'submitted'].includes(s) || this.countBy('status', s) > 0)
		},
		listTitle() {
			if (this.filter.kind === 'status') {
				return statusLabel(this.filter.value)
			}
			if (this.filter.kind === 'type') {
				return typeLabel(this.filter.value)
			}
			if (this.filter.kind === 'portfolio') {
				return `${this.filter.value.label} · ${phaseLabel(this.filter.value.phase)}`
			}
			if (this.filter.kind === 'queue') {
				return {
					triage: t('nc_nexus', 'To triage'),
					resubmission: t('nc_nexus', 'Resubmission due'),
					analysis: t('nc_nexus', 'My analyses'),
				}[this.filter.value]
			}
			return t('nc_nexus', 'Demands')
		},
	},
	mounted() {
		this.load()
		this.loadOrg()
	},
	methods: {
		async load() {
			try {
				this.demands = await listDemands()
			} catch (e) {
				showError(t('nc_nexus', 'Could not load demands: {error}', { error: errorMessage(e) }))
			} finally {
				this.loading = false
			}
		},
		queue(name) {
			// Fälligkeit berechnet der Server (can.triage); hier nur nach Art des Vorrats trennen
			if (name === 'triage') {
				return this.demands.filter(d => d.can?.triage && d.status !== 'deferred')
			}
			if (name === 'analysis') {
				return this.demands.filter(d => d.can?.completeAnalysis && d.analysisOwner === this.me.userId)
			}
			return this.demands.filter(d => d.can?.triage && d.status === 'deferred')
		},
		statusIcon(status) {
			return {
				draft: mdiFileDocumentEditOutline,
				submitted: mdiSend,
				query: mdiAccountQuestion,
				further_analysis: mdiMagnifyScan,
				deferred: mdiPauseCircleOutline,
				in_qualification: mdiCheckCircleOutline,
				rejected: mdiCloseCircleOutline,
				duplicate: mdiContentDuplicate,
				in_execution: mdiPlayCircleOutline,
				paused: mdiPauseCircleOutline,
				completed: mdiFlagCheckered,
				closed: mdiArchiveOutline,
				cancelled: mdiCancel,
			}[status] || mdiSend
		},
		countBy(field, value) {
			return this.demands.filter(demand => demand[field] === value).length
		},
		async loadOrg() {
			try {
				this.me = await getMe()
				this.orgUnits = await listOrgUnits()
				this.assignments = this.me.canManageOrg ? await listRoleAssignments() : []
				this.poolInfo = await listPools()
				this.objectiveInfo = await listObjectives()
				this.scoring = await scoringModel()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		setFilter(kind, value = null) {
			this.filter = { kind, value }
			this.view = 'demands'
		},
		// Klick in der Portfolioübersicht: Anträge der Einheit (samt Untereinheiten) in der Phase
		filterPortfolio(value) {
			this.filter = { kind: 'portfolio', value }
			this.view = 'demands'
		},
		inPortfolioCell(demand, { orgUnitId, phase }) {
			if (PHASE_OF[demand.status] !== phase) {
				return false
			}
			if (orgUnitId === null) {
				return demand.orgUnitId === null || !this.orgUnits.some(u => u.id === demand.orgUnitId)
			}
			const parents = new Map(this.orgUnits.map(u => [u.id, u.parentId]))
			for (let u = demand.orgUnitId, guard = 0; u !== null && u !== undefined && guard < 100; u = parents.get(u), guard++) {
				if (u === orgUnitId) {
					return true
				}
			}
			return false
		},
		openProjects(id) {
			this.projectOpenId = id
			this.view = 'projects'
		},
		openOrg() {
			this.view = 'org'
			this.loadOrg()
		},
		onChanged() {
			this.load()
			this.loadOrg()
			this.refresh++
		},
		openEditor(id) {
			this.editorId = id
			this.editorOpen = true
		},
	},
}
</script>

<style scoped lang="scss">
.nav-search {
	padding: 4px 8px 6px;
}
</style>
