<template>
	<ProjectCockpit v-if="selected"
		:project-id="selected"
		:pools="pools"
		@back="selected = null; load()"
		@open-demand="$emit('open', $event)"
		@changed="$emit('changed')" />
	<div v-else class="nx-view" data-test="projects-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Projects') }}</h2>
			<span class="nx-view__count">{{ n('nc_nexus', '%n project', '%n projects', projects.length) }}</span>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Reported traffic lights next to the calculated health indicator; contradictions are marked.') }}
		</p>
		<p v-if="!projects.length" class="nx-view__empty">
			{{ t('nc_nexus', 'No released projects yet.') }}
		</p>
		<div v-else class="nx-view__table">
			<div class="nx-row prj__row prj__head">
				<span>{{ t('nc_nexus', 'Project') }}</span>
				<span>{{ t('nc_nexus', 'Reported') }}</span>
				<span>{{ t('nc_nexus', 'Health') }}</span>
				<span>{{ t('nc_nexus', 'Forecast vs. budget') }}</span>
				<span>{{ t('nc_nexus', 'Escalation') }}</span>
			</div>
			<div v-for="p in projects"
				:key="p.id"
				class="nx-row nx-row--click prj__row"
				:data-test="'project-' + p.key"
				@click="selected = p.id">
				<div class="nx-row__name">
					<span class="nx-row__title">{{ p.title }}</span>
					<span class="nx-row__meta">{{ p.key }} · {{ statusLabel(p.status) }}{{ p.managerId ? ' · ' + p.managerId : '' }}{{ p.plannedEnd ? ' · ' + formatIsoDay(p.plannedEnd) : '' }}</span>
				</div>
				<span class="prj__rag">
					<template v-if="p.rag">
						<span v-for="d in dims"
							:key="d"
							class="prj__dot"
							:class="'prj__dot--' + p.rag[d]"
							:title="dimensionRagLabel(d) + ': ' + ragLabel(p.rag[d])" />
						<small class="nx-row__meta">{{ p.reportPeriod }}</small>
					</template>
					<small v-else class="nx-row__meta">{{ t('nc_nexus', 'no report') }}</small>
					<b v-if="p.contradiction" class="prj__contra" :title="t('nc_nexus', 'contradicts the indicator')">!</b>
				</span>
				<span class="nx-chip" :class="ragChip(p.health)" :data-test="'project-health-' + p.key">{{ ragLabel(p.health) }}<template v-if="p.findings"> · {{ p.findings }}</template></span>
				<span class="nx-row__meta" :class="{ 'prj__warn': p.deviationPct > 0 }">
					{{ p.deviationPct === null ? '–' : (p.deviationPct > 0 ? '+' : '') + formatNumber(p.deviationPct) + ' %' }}
				</span>
				<span><span v-if="p.escalation" class="nx-chip nx-chip--rejected">{{ p.escalation }}</span></span>
			</div>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import ProjectCockpit from '../components/ProjectCockpit.vue'
import { errorMessage, listProjects } from '../api.js'
import { dimensionRagLabel, formatIsoDay, formatNumber, ragChip, ragLabel, statusLabel } from '../labels.js'

export default {
	name: 'ProjectsView',
	components: { ProjectCockpit },
	props: {
		pools: { type: Array, default: () => [] },
		openId: { type: Number, default: null },
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open', 'changed'],
	setup() {
		return { n, t, dimensionRagLabel, formatIsoDay, formatNumber, ragChip, ragLabel, statusLabel, dims: ['scope', 'time', 'cost', 'risk'] }
	},
	data() {
		return { projects: [], selected: this.openId }
	},
	watch: {
		openId(id) {
			this.selected = id
		},
		refreshKey() {
			if (!this.selected) {
				this.load()
			}
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.projects = await listProjects()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.prj__row {
	grid-template-columns: minmax(0, 1fr) 150px 120px 130px 80px;
}

.prj__head {
	min-height: 36px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.prj__rag {
	display: inline-flex;
	gap: 3px;
	align-items: center;

	small {
		margin-inline-start: 4px;
	}
}

.prj__dot {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 50%;

	&--green {
		background: var(--color-element-success, var(--color-success-text));
	}

	&--yellow {
		background: var(--color-element-warning, var(--color-warning-text));
	}

	&--red {
		background: var(--color-element-error, var(--color-error-text));
	}
}

.prj__contra,
.prj__warn {
	color: var(--color-error-text);
}

.prj__contra {
	margin-inline-start: 4px;
}
</style>
