<template>
	<div v-if="planning && (planning.link || planning.canLink || !planning.available)" class="nx-section" data-test="planning">
		<h3>{{ t('nc_nexus', 'Planning (Gantt)') }}</h3>
		<p v-if="!planning.available && !planning.link" class="planning__muted">
			{{ t('nc_nexus', 'Connect the released project with a project plan as soon as the Gantt app (nc_gantt) is installed.') }}
		</p>
		<template v-else-if="planning.link">
			<p class="planning__title">
				<a :href="planning.link.url"
					target="_blank"
					rel="noopener"
					data-test="planning-open">{{ planning.link.title }}</a>
				<span v-if="planning.link.syncStatus === 'error'" class="nx-chip nx-chip--rejected">{{ t('nc_nexus', 'sync failed') }}</span>
			</p>
			<div v-if="planning.link.summary" class="nx-summary__facts" data-test="planning-summary">
				<div class="nx-fact">
					{{ t('nc_nexus', 'Progress') }}<b data-test="planning-progress">{{ planning.link.summary.progress }} %</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Tasks') }}<b>{{ planning.link.summary.done }} / {{ planning.link.summary.tasks }}</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Plan') }}<b>{{ planning.link.summary.start ? formatIsoDay(planning.link.summary.start) + ' – ' + formatIsoDay(planning.link.summary.end) : '–' }}</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Overdue') }}<b :class="{ 'planning__overdue': planning.link.summary.overdue }">{{ planning.link.summary.overdue }}</b>
				</div>
			</div>
			<p class="planning__muted">
				{{ t('nc_nexus', 'After release the plan leads for dates and progress; the values are read from the Gantt tasks.') }}
			</p>
			<NcButton v-if="planning.canUnlink"
				variant="tertiary"
				data-test="planning-unlink"
				@click="unlink">
				{{ t('nc_nexus', 'Disconnect') }}
			</NcButton>
		</template>
		<div v-else class="planning__actions">
			<NcButton variant="secondary"
				:disabled="busy"
				data-test="planning-create"
				@click="connect(null)">
				{{ t('nc_nexus', 'Create Gantt project') }}
			</NcButton>
			<select v-if="projects.length"
				v-model="selected"
				class="nx-input planning__select"
				:aria-label="t('nc_nexus', 'Existing Gantt project')">
				<option :value="null">
					{{ t('nc_nexus', '– link existing project –') }}
				</option>
				<option v-for="p in projects" :key="p.id" :value="p.id">
					{{ p.title }}
				</option>
			</select>
			<NcButton v-if="selected"
				variant="secondary"
				:disabled="busy"
				@click="connect(selected)">
				{{ t('nc_nexus', 'Link') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { connectPlanning, disconnectPlanning, errorMessage, getPlanning, planningProjects } from '../api.js'
import { formatIsoDay } from '../labels.js'

export default {
	name: 'PlanningPanel',
	components: { NcButton },
	props: {
		demandId: { type: Number, required: true },
		/** nur freigegebene Vorhaben werden mit der Planung verbunden */
		status: { type: String, required: true },
	},
	emits: ['changed'],
	setup() {
		return { t, formatIsoDay }
	},
	data() {
		return { planning: null, projects: [], selected: null, busy: false }
	},
	watch: {
		status: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.planning = await getPlanning(this.demandId)
				if (this.planning.canLink) {
					this.projects = await planningProjects().catch(() => [])
				}
			} catch (e) {
				this.planning = null
			}
		},
		async connect(projectId) {
			this.busy = true
			try {
				this.planning = await connectPlanning(this.demandId, projectId)
				showSuccess(t('nc_nexus', 'Connected with the planning tool'))
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async unlink() {
			try {
				await disconnectPlanning(this.demandId)
				await this.load()
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.planning__muted {
	color: var(--color-text-maxcontrast);
}

.planning__title {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 0 0 6px;

	a {
		font-weight: 600;
		text-decoration: underline;
	}
}

.planning__overdue {
	color: var(--color-error-text);
}

.planning__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.planning__select.nx-input.nx-input {
	width: 280px;
}
</style>
