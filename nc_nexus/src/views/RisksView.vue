<template>
	<div class="nx-view" data-test="risks-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Portfolio risks') }}</h2>
			<span v-if="data" class="nx-view__count">{{ n('nc_nexus', '%n open risk', '%n open risks', data.open) }}</span>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Escalated and cross-project risks and those above the escalation threshold (score {s}). The matrix counts all open risks of readable projects.', { s: data ? data.threshold : 15 }) }}
		</p>
		<div v-if="data" class="risk__layout">
			<table class="risk__matrix" data-test="risk-matrix">
				<caption>{{ t('nc_nexus', 'Probability × impact') }}</caption>
				<tbody>
					<tr v-for="p in [5, 4, 3, 2, 1]" :key="p">
						<th>{{ p }}</th>
						<td v-for="i in [1, 2, 3, 4, 5]" :key="i" :class="cellClass(p * i)">
							{{ (data.matrix[p] && data.matrix[p][i]) || '' }}
						</td>
					</tr>
					<tr>
						<th />
						<th v-for="i in [1, 2, 3, 4, 5]" :key="i">
							{{ i }}
						</th>
					</tr>
				</tbody>
			</table>
			<div>
				<p v-if="!data.risks.length" class="nx-view__empty">
					{{ t('nc_nexus', 'No escalated or cross-project risks.') }}
				</p>
				<div v-else class="nx-view__table">
					<div v-for="r in data.risks"
						:key="r.id"
						class="nx-row risk__row"
						:data-test="'portfolio-risk-' + r.id">
						<span class="risk__score" :class="cellClass(r.score)">{{ r.score }}</span>
						<div class="nx-row__name">
							<span class="nx-row__title">{{ r.title }}</span>
							<span class="nx-row__meta">{{ riskCategoryLabel(r.category) }} · {{ r.ownerId }}{{ r.residualScore !== null ? ' · ' + t('nc_nexus', 'residual {s}', { s: r.residualScore }) : '' }}</span>
						</div>
						<div class="risk__projects">
							<a v-for="p in r.projects"
								:key="p.id"
								href="#"
								@click.prevent="$emit('project', p.id)">{{ p.key }}</a>
						</div>
						<span>
							<span v-if="r.escalated" class="nx-chip nx-chip--rejected">{{ t('nc_nexus', 'escalated') }}</span>
							<span v-else-if="r.escalationHint" class="nx-chip nx-chip--on-hold">{{ t('nc_nexus', 'escalation recommended') }}</span>
							<span v-if="r.crossProject" class="nx-chip nx-chip--submitted">{{ t('nc_nexus', 'cross-project') }}</span>
						</span>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import { errorMessage, portfolioRisks } from '../api.js'
import { riskCategoryLabel } from '../labels.js'

export default {
	name: 'RisksView',
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['project'],
	setup() {
		return { n, t, riskCategoryLabel }
	},
	data() {
		return { data: null }
	},
	watch: {
		refreshKey() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.data = await portfolioRisks()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		cellClass(score) {
			return score >= (this.data?.threshold ?? 15) ? 'risk--red' : (score >= 8 ? 'risk--yellow' : 'risk--green')
		},
	},
}
</script>

<style scoped lang="scss">
.risk__layout {
	display: grid;
	grid-template-columns: 260px minmax(0, 1fr);
	gap: 20px;
	align-items: start;
}

.risk__matrix {
	border-collapse: separate;
	border-spacing: 3px;

	caption {
		margin-bottom: 6px;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	td {
		width: 42px;
		height: 36px;
		border-radius: 6px;
		font-weight: 700;
		text-align: center;
		color: #fff;
	}

	th {
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}
}

.risk--green {
	background: var(--color-element-success, var(--color-success-text));
}

.risk--yellow {
	background: var(--color-element-warning, var(--color-warning-text));
}

.risk--red {
	background: var(--color-element-error, var(--color-error-text));
}

.risk__row {
	grid-template-columns: 44px minmax(0, 1fr) 160px auto;
}

.risk__score {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 28px;
	border-radius: 8px;
	font-weight: 700;
	color: #fff;
}

.risk__projects {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	font-size: 13px;
}
</style>
