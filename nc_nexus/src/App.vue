<template>
	<NcContent app-name="nc_nexus">
		<NcAppNavigation :aria-label="t('nc_nexus', 'Nexus navigation')">
			<template #list>
				<NcAppNavigationItem :name="t('nc_nexus', 'Demands')" active>
					<template #icon>
						<NcIconSvgWrapper :path="mdiInboxArrowDown" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<div class="nexus-page">
				<header class="nexus-page__head">
					<h2>{{ t('nc_nexus', 'Demands') }}</h2>
					<NcButton variant="primary" data-test="new-demand" @click="openEditor(null)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPlus" />
						</template>
						{{ t('nc_nexus', 'New demand') }}
					</NcButton>
				</header>

				<NcLoadingIcon v-if="loading" :size="32" />
				<NcEmptyContent v-else-if="demands.length === 0"
					:name="t('nc_nexus', 'No demands yet')"
					:description="t('nc_nexus', 'Every project starts as a demand. Record the first one.')">
					<template #icon>
						<NcIconSvgWrapper :path="mdiInboxArrowDown" />
					</template>
				</NcEmptyContent>
				<table v-else class="nexus-table" data-test="demand-table">
					<thead>
						<tr>
							<th>{{ t('nc_nexus', 'Key') }}</th>
							<th>{{ t('nc_nexus', 'Title') }}</th>
							<th>{{ t('nc_nexus', 'Type') }}</th>
							<th>{{ t('nc_nexus', 'Status') }}</th>
							<th>{{ t('nc_nexus', 'Requestor') }}</th>
							<th>{{ t('nc_nexus', 'Created') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="demand in demands"
							:key="demand.id"
							tabindex="0"
							:data-test="'demand-' + demand.key"
							@click="openEditor(demand.id)"
							@keydown.enter="openEditor(demand.id)">
							<td class="nexus-table__key">
								{{ demand.key }}
							</td>
							<td>{{ demand.title }}</td>
							<td>{{ typeLabel(demand.demandType) }}</td>
							<td>
								<span class="nexus-status" :class="'nexus-status--' + demand.status">{{ statusLabel(demand.status) }}</span>
							</td>
							<td>{{ demand.requestorId }}</td>
							<td>{{ formatDate(demand.createdAt) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
			<DemandEditor v-if="editorOpen"
				:demand-id="editorId"
				@close="editorOpen = false"
				@changed="load" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { mdiInboxArrowDown, mdiPlus } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DemandEditor from './components/DemandEditor.vue'
import { errorMessage, listDemands } from './api.js'
import { formatDate, statusLabel, typeLabel } from './labels.js'

export default {
	name: 'App',
	components: { DemandEditor, NcAppContent, NcAppNavigation, NcAppNavigationItem, NcButton, NcContent, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon },
	setup() {
		return { mdiInboxArrowDown, mdiPlus, t, formatDate, statusLabel, typeLabel }
	},
	data() {
		return {
			demands: [],
			loading: true,
			editorOpen: false,
			editorId: null,
		}
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
		openEditor(id) {
			this.editorId = id
			this.editorOpen = true
		},
	},
}
</script>

<style scoped lang="scss">
.nexus-page {
	padding: 8px 24px 24px 56px;

	&__head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		min-height: var(--default-clickable-area);
		margin-bottom: 16px;

		h2 {
			margin: 0;
		}
	}
}

.nexus-table {
	width: 100%;
	border-collapse: collapse;

	th, td {
		padding: 8px 12px;
		text-align: start;
		border-bottom: 1px solid var(--color-border);
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: normal;
	}

	tbody tr {
		cursor: pointer;

		&:hover, &:focus-visible {
			background: var(--color-background-hover);
		}
	}

	&__key {
		font-family: var(--font-face-monospace, monospace);
		white-space: nowrap;
	}
}

.nexus-status {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: var(--font-size-small, 13px);
	white-space: nowrap;
	background: var(--color-background-dark);

	&--submitted {
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
	}
}
</style>
