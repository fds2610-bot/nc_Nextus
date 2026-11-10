<template>
	<div class="demand-list">
		<div class="demand-list__header">
			<h2>{{ title }}</h2>
			<span v-if="!loading" class="demand-list__count">{{ n('nc_nexus', '%n demand', '%n demands', demands.length) }}</span>
		</div>

		<div v-if="!loading && allDemands.length" class="nx-kpis" data-test="kpis">
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					<NcIconSvgWrapper :path="mdiInboxArrowDown" :size="16" />
					{{ t('nc_nexus', 'Demands') }}
				</div>
				<div class="nx-kpi__num">
					{{ allDemands.length }}
				</div>
				<div class="nx-kpi__sub">
					{{ n('nc_nexus', '%n draft', '%n drafts', count('status', 'draft')) }}
				</div>
			</div>
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					<NcIconSvgWrapper :path="mdiSend" :size="16" />
					{{ t('nc_nexus', 'Submitted') }}
				</div>
				<div class="nx-kpi__num" data-test="kpi-submitted">
					{{ count('status', 'submitted') }}
				</div>
				<div class="nx-kpi__sub">
					{{ n('nc_nexus', '%n in the last 30 days', '%n in the last 30 days', submittedRecently) }}
				</div>
			</div>
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					<NcIconSvgWrapper :path="mdiScaleBalance" :size="16" />
					{{ t('nc_nexus', 'Mandatory / regulatory') }}
				</div>
				<div class="nx-kpi__num">
					{{ count('demandType', 'mandatory') }}
				</div>
				<div class="nx-kpi__sub">
					{{ t('nc_nexus', 'not competing for priority') }}
				</div>
			</div>
			<div class="nx-kpi">
				<div class="nx-kpi__lab">
					<NcIconSvgWrapper :path="mdiAccountMultiple" :size="16" />
					{{ t('nc_nexus', 'Requestors') }}
				</div>
				<div class="nx-kpi__num">
					{{ requestorCount }}
				</div>
				<div class="nx-kpi__sub">
					{{ t('nc_nexus', 'accounts with demands') }}
				</div>
			</div>
		</div>

		<div v-if="loading" class="demand-list__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="demands.length === 0"
			:name="filtered ? t('nc_nexus', 'No matching demands') : t('nc_nexus', 'No demands yet')"
			:description="filtered ? t('nc_nexus', 'Change the search or the filter in the navigation.') : t('nc_nexus', 'Every project starts as a demand. Record the first one.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiInboxArrowDown" />
			</template>
			<template v-if="!filtered" #action>
				<NcButton variant="primary" @click="$emit('create')">
					{{ t('nc_nexus', 'New demand') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="demand-list__table" data-test="demand-table">
			<div class="demand-list__thead" aria-hidden="true">
				<span />
				<span>{{ t('nc_nexus', 'Demand') }}</span>
				<span>{{ t('nc_nexus', 'Status') }}</span>
				<span>{{ t('nc_nexus', 'Date') }}</span>
				<span />
			</div>
			<DemandListItem v-for="demand in demands"
				:key="demand.id"
				:demand="demand"
				@open="$emit('open', demand.id)"
				@changed="$emit('changed')" />
		</div>
	</div>
</template>

<script>
import { mdiAccountMultiple, mdiInboxArrowDown, mdiScaleBalance, mdiSend } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DemandListItem from '../components/DemandListItem.vue'

const THIRTY_DAYS = 30 * 24 * 3600

export default {
	name: 'DemandList',
	components: { DemandListItem, NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon },
	props: {
		demands: { type: Array, required: true },
		allDemands: { type: Array, required: true },
		title: { type: String, required: true },
		loading: { type: Boolean, default: false },
		filtered: { type: Boolean, default: false },
	},
	emits: ['open', 'create', 'changed'],
	setup() {
		return { mdiAccountMultiple, mdiInboxArrowDown, mdiScaleBalance, mdiSend, n, t }
	},
	computed: {
		submittedRecently() {
			const since = Date.now() / 1000 - THIRTY_DAYS
			return this.allDemands.filter(d => d.submittedAt && d.submittedAt >= since).length
		},
		requestorCount() {
			return new Set(this.allDemands.map(d => d.requestorId)).size
		},
	},
	methods: {
		count(field, value) {
			return this.allDemands.filter(d => d[field] === value).length
		},
	},
}
</script>

<style scoped lang="scss">
.demand-list {
	padding: 8px 20px 20px 50px;
	container: demand-list / inline-size;

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: var(--default-clickable-area, 34px);
		margin: 4px 0 16px;

		h2 {
			margin: 0;
			font-size: 20px;
			font-weight: 600;
		}
	}

	&__count {
		color: var(--color-text-maxcontrast);
	}

	&__loading {
		display: flex;
		justify-content: center;
		align-items: center;
		height: 200px;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}

	/* Spalten MÜSSEN zu .demand-row in DemandListItem.vue passen */
	&__thead {
		display: grid;
		grid-template-columns: 32px minmax(0, 1fr) 130px 130px 44px;
		gap: 12px;
		align-items: center;
		padding: 10px 4px 10px 12px;
		border-bottom: 1px solid var(--color-border);
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}
}

@container demand-list (max-width: 800px) {
	.nx-kpis {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@container demand-list (max-width: 600px) {
	.demand-list__thead {
		display: none;
	}
}

@container demand-list (max-width: 500px) {
	.nx-kpis {
		grid-template-columns: 1fr;
	}
}
</style>
