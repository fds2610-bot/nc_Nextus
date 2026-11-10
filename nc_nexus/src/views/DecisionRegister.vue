<template>
	<div class="register" data-test="decision-register">
		<div class="register__header">
			<h2>{{ t('nc_nexus', 'Decision register') }}</h2>
			<span v-if="!loading" class="register__count">{{ n('nc_nexus', '%n decision', '%n decisions', visible.length) }}</span>
			<div class="register__search">
				<NcTextField v-model="search"
					:label="t('nc_nexus', 'Search decisions …')"
					:show-trailing-button="search !== ''"
					trailing-button-icon="close"
					@trailing-button-click="search = ''" />
			</div>
		</div>
		<p class="register__intro">
			{{ t('nc_nexus', 'Every triage decision is recorded here with mandate and reason and can no longer be changed. A new decision on the same demand replaces the previous one.') }}
		</p>

		<div v-if="loading" class="register__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent v-else-if="!visible.length"
			:name="search ? t('nc_nexus', 'No matching decisions') : t('nc_nexus', 'No decisions yet')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiScaleBalance" />
			</template>
		</NcEmptyContent>
		<div v-else class="register__table">
			<div v-for="d in visible"
				:key="d.id"
				class="decision"
				:class="{ 'decision--superseded': supersededIds.has(d.id) }"
				:data-test="'decision-' + d.key">
				<div class="decision__id">
					<strong>{{ d.key }}</strong>
					<span>{{ formatDate(d.decidedAt) }}</span>
				</div>
				<div class="decision__main">
					<a href="#" class="decision__demand" @click.prevent="$emit('open', d.demand.id)">
						{{ d.demand.key }} · {{ d.demand.title }}
					</a>
					<div class="decision__chips">
						<span class="nx-chip" :class="'nx-chip--' + outcomeChip(d.outcome)">{{ outcomeLabel(d.outcome) }}</span>
						<span v-if="d.outOfSequence" class="decision__flag" data-test="out-of-sequence">{{ t('nc_nexus', 'outside the regular step') }}</span>
						<span v-if="supersededIds.has(d.id)" class="decision__flag">{{ t('nc_nexus', 'replaced') }}</span>
						<span v-if="d.supersedesId" class="decision__meta">{{ t('nc_nexus', 'replaces {key}', { key: keyOf(d.supersedesId) }) }}</span>
					</div>
					<p v-if="d.rationale" class="decision__rationale">
						{{ d.rationale }}
					</p>
					<p v-if="detailText(d)" class="decision__meta">
						{{ detailText(d) }}
					</p>
				</div>
				<div class="decision__mandate">
					<span class="decision__label">{{ t('nc_nexus', 'Mandate') }}</span>
					<span data-test="decision-mandate">{{ mandateLabel(d.mandate) }}</span>
					<span class="decision__label">{{ t('nc_nexus', 'Decided by') }}</span>
					<span>{{ d.decidedBy }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { mdiScaleBalance } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage, listDecisions } from '../api.js'
import { formatDate, formatIsoDay, orgTree, outcomeChip, outcomeLabel, roleLabel } from '../labels.js'

export default {
	name: 'DecisionRegister',
	components: { NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcTextField },
	props: {
		orgUnits: { type: Array, default: () => [] },
		/** Zähler von außen erhöhen, um neu zu laden */
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { mdiScaleBalance, n, t, formatDate, outcomeChip, outcomeLabel }
	},
	data() {
		return { decisions: [], loading: true, search: '' }
	},
	computed: {
		tree() {
			return orgTree(this.orgUnits)
		},
		supersededIds() {
			return new Set(this.decisions.map(d => d.supersedesId).filter(Boolean))
		},
		visible() {
			const q = this.search.trim().toLowerCase()
			if (!q) {
				return this.decisions
			}
			return this.decisions.filter(d => [d.key, d.demand.key, d.demand.title, d.rationale, d.decidedBy, outcomeLabel(d.outcome)]
				.some(text => (text || '').toLowerCase().includes(q)))
		},
	},
	watch: {
		refreshKey: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.decisions = await listDecisions()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		keyOf(id) {
			return this.decisions.find(d => d.id === id)?.key ?? '#' + id
		},
		mandateLabel(m) {
			if (m.scopeType === 'nextcloud') {
				return t('nc_nexus', 'Nextcloud administration')
			}
			const scope = m.scopeType === 'global'
				? t('nc_nexus', 'Global')
				: (this.tree.find(u => u.id === m.scopeId)?.path ?? '?')
			return `${roleLabel(m.role)} · ${scope}`
		},
		detailText(d) {
			const x = d.details || {}
			const parts = []
			if (x.projectClass) {
				parts.push(t('nc_nexus', 'Project class {c}', { c: x.projectClass }))
			}
			if (x.resubmissionDate) {
				parts.push(t('nc_nexus', 'Resubmission {date}', { date: formatIsoDay(x.resubmissionDate) }))
			}
			if (x.analysisOwner) {
				parts.push(t('nc_nexus', 'Analysis by {user} until {date}', { user: x.analysisOwner, date: formatIsoDay(x.analysisDue) }))
			}
			if (x.duplicateOfId) {
				parts.push(t('nc_nexus', 'Duplicate of demand #{id}', { id: x.duplicateOfId }))
			}
			return parts.join(' · ')
		},
	},
}
</script>

<style scoped lang="scss">
.register {
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

	&__count,
	&__intro {
		color: var(--color-text-maxcontrast);
	}

	&__intro {
		margin: 0 0 16px;
		font-size: 13px;
	}

	&__search {
		width: 280px;
		margin-inline-start: auto;
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 40px 0;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}
}

.decision {
	display: grid;
	grid-template-columns: 150px minmax(0, 1fr) 240px;
	gap: 16px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&--superseded {
		opacity: 0.65;
	}

	&__id {
		display: flex;
		flex-direction: column;
		font-variant-numeric: tabular-nums;

		span {
			font-size: 13px;
			color: var(--color-text-maxcontrast);
		}
	}

	&__main {
		min-width: 0;
	}

	&__demand {
		display: block;
		overflow: hidden;
		font-weight: 600;
		white-space: nowrap;
		text-overflow: ellipsis;

		&:hover,
		&:focus-visible {
			text-decoration: underline;
		}
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-top: 6px;
	}

	&__flag {
		padding: 0 8px;
		border: 1px solid var(--color-border-dark);
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	&__rationale {
		margin: 6px 0 0;
		white-space: pre-wrap;
	}

	&__meta {
		margin: 4px 0 0;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__mandate {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr);
		gap: 2px 8px;
		align-content: start;
		font-size: 13px;
	}

	&__label {
		color: var(--color-text-maxcontrast);
	}
}

@media (max-width: 900px) {
	.decision {
		grid-template-columns: 1fr;
	}
}
</style>
