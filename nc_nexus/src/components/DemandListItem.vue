<template>
	<div class="demand-row"
		:data-test="'demand-' + demand.key"
		@click="$emit('open')">
		<NcAvatar class="demand-row__avatar"
			:user="demand.requestorId"
			:disable-menu="true"
			:disable-tooltip="true"
			:size="32"
			aria-hidden="true" />
		<div class="demand-row__name">
			<a class="demand-row__title"
				href="#"
				@click.prevent.stop="$emit('open')">
				{{ demand.title }}
			</a>
			<div class="demand-row__meta">
				<span class="demand-row__key">{{ demand.key }}</span>
				<span class="sep">·</span>
				<span class="demand-row__type">
					<span class="demand-row__bullet" :style="{ background: '#' + typeColor(demand.demandType) }" />
					{{ typeLabel(demand.demandType) }}
				</span>
				<template v-if="orgUnitName">
					<span class="sep">·</span>
					<span>{{ orgUnitName }}</span>
				</template>
				<span class="sep">·</span>
				<span>{{ demand.requestorId }}</span>
			</div>
		</div>
		<div class="demand-row__status">
			<span class="nx-chip" :class="'nx-chip--' + statusChip(demand.status)">{{ statusLabel(demand.status) }}</span>
		</div>
		<div class="demand-row__date">
			<span class="demand-row__date-label">{{ demand.submittedAt ? t('nc_nexus', 'Submitted on') : t('nc_nexus', 'Created on') }}</span>
			{{ formatDay(demand.submittedAt || demand.createdAt) }}
		</div>
		<div class="demand-row__actions" @click.stop>
			<NcActions :aria-label="t('nc_nexus', 'Actions for {title}', { title: demand.title })">
				<NcActionButton close-after-click @click="$emit('open')">
					<template #icon>
						<NcIconSvgWrapper :path="canEdit ? mdiPencil : mdiEye" />
					</template>
					{{ canEdit ? t('nc_nexus', 'Edit') : t('nc_nexus', 'Open') }}
				</NcActionButton>
				<NcActionButton v-if="canDelete" close-after-click @click="remove">
					<template #icon>
						<NcIconSvgWrapper :path="mdiDelete" />
					</template>
					{{ t('nc_nexus', 'Delete draft') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
import { mdiDelete, mdiEye, mdiPencil } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { deleteDemand, errorMessage } from '../api.js'
import { formatDay, statusChip, statusLabel, typeColor, typeLabel } from '../labels.js'

export default {
	name: 'DemandListItem',
	components: { NcActionButton, NcActions, NcAvatar, NcIconSvgWrapper },
	props: {
		demand: { type: Object, required: true },
		orgUnits: { type: Array, default: () => [] },
	},
	emits: ['open', 'changed'],
	setup() {
		return { mdiDelete, mdiEye, mdiPencil, t, formatDay, statusChip, statusLabel, typeColor, typeLabel }
	},
	computed: {
		orgUnitName() {
			return this.orgUnits.find(u => u.id === this.demand.orgUnitId)?.name ?? ''
		},
		canEdit() {
			return !!this.demand.can?.edit
		},
		canDelete() {
			return !!this.demand.can?.delete
		},
	},
	methods: {
		async remove() {
			try {
				await deleteDemand(this.demand.id)
				showSuccess(t('nc_nexus', 'Draft deleted'))
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			}
		},
	},
}
</script>

<style scoped lang="scss">
/* Spalten MÜSSEN zu .demand-list__thead in DemandList.vue passen */
.demand-row {
	display: grid;
	grid-template-columns: 32px minmax(0, 1fr) 130px 130px 44px;
	align-items: center;
	gap: 12px;
	min-height: 58px;
	padding: 0 4px 0 12px;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	transition: background-color 0.1s ease;

	&:last-child {
		border-bottom: none;
	}

	&:hover,
	&:focus-within {
		background: var(--color-background-hover);
	}

	&__name {
		min-width: 0;
		padding: 8px 0;
	}

	&__title {
		display: block;
		overflow: hidden;
		color: var(--color-main-text);
		font-weight: 600;
		white-space: nowrap;
		text-overflow: ellipsis;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__meta {
		margin-top: 1px;
		overflow: hidden;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		text-overflow: ellipsis;

		.sep {
			margin: 0 5px;
			opacity: 0.5;
		}
	}

	&__key {
		font-variant-numeric: tabular-nums;
	}

	&__bullet {
		display: inline-block;
		width: 8px;
		height: 8px;
		margin-inline-end: 3px;
		border-radius: 50%;
		vertical-align: 1px;
	}

	&__date {
		display: flex;
		flex-direction: column;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}

	&__date-label {
		font-size: 12px;
		line-height: 1.3;
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		cursor: default;
	}
}

@container demand-list (max-width: 600px) {
	.demand-row {
		grid-template-columns: minmax(0, 1fr) 110px 44px;
		grid-template-areas:
			"name date actions"
			"status date actions";
		row-gap: 0;
	}

	.demand-row__avatar {
		display: none;
	}

	.demand-row__name { grid-area: name; padding-bottom: 2px; }
	.demand-row__status { grid-area: status; padding-bottom: 8px; }
	.demand-row__date { grid-area: date; }
	.demand-row__actions { grid-area: actions; }
}
</style>
