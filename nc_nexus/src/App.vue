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
					:active="filter.kind === 'all'"
					data-test="nav-all"
					@click="setFilter('all')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiInboxArrowDown" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="demands.length" :count="demands.length" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationCaption :name="t('nc_nexus', 'Status')" />
				<NcAppNavigationItem v-for="status in statuses"
					:key="status"
					:name="statusLabel(status)"
					:active="filter.kind === 'status' && filter.value === status"
					:data-test="'nav-status-' + status"
					@click="setFilter('status', status)">
					<template #icon>
						<NcIconSvgWrapper :path="status === 'draft' ? mdiFileDocumentEditOutline : mdiSend" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="countBy('status', status)" :count="countBy('status', status)" />
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationCaption :name="t('nc_nexus', 'Demand types')" />
				<NcAppNavigationItem v-for="type in types"
					:key="type"
					:name="typeLabel(type)"
					:active="filter.kind === 'type' && filter.value === type"
					@click="setFilter('type', type)">
					<template #icon>
						<NcAppNavigationIconBullet :color="typeColor(type)" />
					</template>
					<template #counter>
						<NcCounterBubble v-if="countBy('demandType', type)" :count="countBy('demandType', type)" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>
		</NcAppNavigation>

		<NcAppContent>
			<DemandList :demands="visibleDemands"
				:all-demands="demands"
				:title="listTitle"
				:loading="loading"
				:filtered="filter.kind !== 'all' || search !== ''"
				@open="openEditor"
				@create="openEditor(null)"
				@changed="load" />
			<DemandEditor v-if="editorOpen"
				:demand-id="editorId"
				@close="editorOpen = false"
				@changed="load" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { mdiFileDocumentEditOutline, mdiInboxArrowDown, mdiMagnify, mdiPlus, mdiSend } from '@mdi/js'
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
import { errorMessage, listDemands } from './api.js'
import { statusLabel, typeColor, typeLabel } from './labels.js'

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
	},
	setup() {
		return { mdiFileDocumentEditOutline, mdiInboxArrowDown, mdiMagnify, mdiPlus, mdiSend, t, statusLabel, typeColor, typeLabel }
	},
	data() {
		return {
			demands: [],
			loading: true,
			editorOpen: false,
			editorId: null,
			search: '',
			filter: { kind: 'all', value: null },
			statuses: ['draft', 'submitted'],
			types: loadState('nc_nexus', 'demand_types', []),
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
				return !query || [demand.key, demand.title, demand.problemStatement, demand.requestorId]
					.some(text => (text || '').toLowerCase().includes(query))
			})
		},
		listTitle() {
			if (this.filter.kind === 'status') {
				return statusLabel(this.filter.value)
			}
			if (this.filter.kind === 'type') {
				return typeLabel(this.filter.value)
			}
			return t('nc_nexus', 'Demands')
		},
	},
	mounted() {
		this.load()
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
		countBy(field, value) {
			return this.demands.filter(demand => demand[field] === value).length
		},
		setFilter(kind, value = null) {
			this.filter = { kind, value }
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
