<template>
	<div class="objectives" data-test="objectives-view">
		<div class="objectives__header">
			<h2>{{ t('nc_nexus', 'Strategic objectives') }}</h2>
			<span class="objectives__count">{{ n('nc_nexus', '%n objective', '%n objectives', objectives.length) }}</span>
			<NcButton v-if="canManage"
				class="objectives__new"
				variant="secondary"
				data-test="new-objective"
				@click="dialog = { objective: null }">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				{{ t('nc_nexus', 'New objective') }}
			</NcButton>
		</div>
		<p class="objectives__intro">
			{{ t('nc_nexus', 'The coverage shows how many demands and projects you may see contribute to an objective. Objectives without coverage are a gap in the portfolio.') }}
		</p>
		<NcEmptyContent v-if="!objectives.length"
			:name="t('nc_nexus', 'No strategic objectives yet')"
			:description="canManage ? t('nc_nexus', 'Create the objectives demands are measured against.') : t('nc_nexus', 'The portfolio office maintains the objectives.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiBullseyeArrow" />
			</template>
		</NcEmptyContent>
		<div v-else class="objectives__table">
			<div v-for="o in tree"
				:key="o.id"
				class="objective"
				:class="{ 'objective--clickable': canManage }"
				:data-test="'objective-' + o.code"
				@click="canManage && (dialog = { objective: o })">
				<div class="objective__name" :style="{ paddingInlineStart: (o.depth * 22) + 'px' }">
					<span class="objective__code">{{ o.code }}</span>
					<span class="objective__title">{{ o.title }}</span>
					<span v-if="o.targetMetric" class="objective__meta">{{ o.targetMetric }}</span>
				</div>
				<span class="objective__meta">{{ o.ownerUserId || '–' }}</span>
				<span class="objective__meta">{{ validity(o) }}</span>
				<span class="nx-chip"
					:class="o.demandCount ? 'nx-chip--accepted' : 'nx-chip--on-hold'"
					data-test="objective-coverage">{{ o.demandCount ? n('nc_nexus', '%n demand', '%n demands', o.demandCount) : t('nc_nexus', 'not covered') }}</span>
			</div>
		</div>
		<ObjectiveDialog v-if="dialog"
			:objective="dialog.objective"
			:objectives="objectives"
			@close="dialog = null"
			@changed="$emit('changed')" />
	</div>
</template>

<script>
import { mdiBullseyeArrow, mdiPlus } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ObjectiveDialog from '../components/ObjectiveDialog.vue'
import { formatIsoDay, objectiveTree } from '../labels.js'

export default {
	name: 'ObjectivesView',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, ObjectiveDialog },
	props: {
		objectives: { type: Array, required: true },
		canManage: { type: Boolean, default: false },
	},
	emits: ['changed'],
	setup() {
		return { mdiBullseyeArrow, mdiPlus, n, t }
	},
	data() {
		return { dialog: null }
	},
	computed: {
		tree() {
			return objectiveTree(this.objectives)
		},
	},
	methods: {
		validity(o) {
			if (o.validFrom && o.validTo) {
				return `${formatIsoDay(o.validFrom)} – ${formatIsoDay(o.validTo)}`
			}
			if (o.validFrom) {
				return t('nc_nexus', 'from {date}', { date: formatIsoDay(o.validFrom) })
			}
			if (o.validTo) {
				return t('nc_nexus', 'until {date}', { date: formatIsoDay(o.validTo) })
			}
			return t('nc_nexus', 'unlimited')
		},
	},
}
</script>

<style scoped lang="scss">
.objectives {
	padding: 8px 20px 20px 50px;

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: var(--default-clickable-area, 34px);
		margin: 4px 0 8px;

		h2 {
			margin: 0;
			font-size: 20px;
			font-weight: 600;
		}
	}

	&__new {
		margin-inline-start: auto;
	}

	&__count,
	&__intro {
		color: var(--color-text-maxcontrast);
	}

	&__intro {
		margin: 0 0 16px;
		font-size: 13px;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}
}

.objective {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 140px 190px 130px;
	align-items: center;
	gap: 12px;
	min-height: 52px;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&--clickable {
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__name {
		display: flex;
		flex-wrap: wrap;
		align-items: baseline;
		gap: 4px 10px;
		min-width: 0;
	}

	&__code {
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__title {
		font-weight: 600;
	}

	&__meta {
		overflow: hidden;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		text-overflow: ellipsis;
	}
}
</style>
