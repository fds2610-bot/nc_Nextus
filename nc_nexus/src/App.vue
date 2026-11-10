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

				<template v-if="me.canTriage">
					<NcAppNavigationCaption :name="t('nc_nexus', 'Triage')" />
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
				</template>

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

				<NcAppNavigationCaption :name="t('nc_nexus', 'Status')" />
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

				<NcAppNavigationCaption :name="t('nc_nexus', 'Demand types')" />
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
			</NcAppNavigationList>

			<template v-if="me.canManageOrg" #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem :name="t('nc_nexus', 'Organization')"
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
			<OrgView v-if="view === 'org'"
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
				@close="editorOpen = false"
				@changed="load" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { mdiAccountQuestion, mdiCalendarClock, mdiCheckCircleOutline, mdiCloseCircleOutline, mdiContentDuplicate, mdiDomain, mdiFileDocumentEditOutline, mdiGavel, mdiInboxArrowDown, mdiMagnify, mdiMagnifyScan, mdiPauseCircleOutline, mdiPlus, mdiSend } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationIconBullet from '@nextcloud/vue/components/NcAppNavigationIconBullet'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DemandEditor from './components/DemandEditor.vue'
import DemandList from './views/DemandList.vue'
import OrgView from './views/OrgView.vue'
import { errorMessage, getMe, listDemands, listOrgUnits, listRoleAssignments } from './api.js'
import { STATUSES, statusLabel, typeColor, typeLabel } from './labels.js'

export default {
	name: 'App',
	components: {
		DemandEditor,
		DemandList,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationIconBullet,
		NcAppNavigationItem,
		NcAppNavigationList,
		NcAppNavigationNew,
		NcContent,
		NcCounterBubble,
		NcIconSvgWrapper,
		NcTextField,
		OrgView,
	},
	setup() {
		return { mdiCalendarClock, mdiGavel, mdiMagnifyScan, mdiDomain, mdiFileDocumentEditOutline, mdiInboxArrowDown, mdiMagnify, mdiPlus, mdiSend, t, statusLabel, typeColor, typeLabel }
	},
	data() {
		return {
			demands: [],
			loading: true,
			editorOpen: false,
			editorId: null,
			search: '',
			filter: { kind: 'all', value: null },
			types: loadState('nc_nexus', 'demand_types', []),
			view: 'demands',
			me: { canManageOrg: false, canTriage: false, roles: [] },
			orgUnits: [],
			assignments: [],
		}
	},
	computed: {
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
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		setFilter(kind, value = null) {
			this.filter = { kind, value }
			this.view = 'demands'
		},
		openOrg() {
			this.view = 'org'
			this.loadOrg()
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
