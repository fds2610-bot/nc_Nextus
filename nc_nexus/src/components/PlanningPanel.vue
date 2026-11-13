<template>
	<div v-if="planning && (planning.link || planning.canLink || planning.canExport)" class="nx-section" data-test="planning">
		<h3>{{ planning.link ? t('nc_nexus', 'Planning ({tool})', { tool: planning.link.providerName }) : t('nc_nexus', 'Planning') }}</h3>
		<template v-if="planning.link">
			<p class="planning__title">
				<a v-if="planning.link.url"
					:href="planning.link.url"
					target="_blank"
					rel="noopener"
					data-test="planning-open">{{ planning.link.title }}</a>
				<b v-else data-test="planning-open">{{ planning.link.title }}</b>
				<span v-if="planning.link.syncStatus === 'error'" class="nx-chip nx-chip--rejected" :title="planning.link.syncError">{{ t('nc_nexus', 'sync failed') }}</span>
			</p>
			<p v-if="planning.link.syncStatus === 'error'" class="planning__error" data-test="planning-sync-error">
				{{ planning.link.syncError }}
				<template v-if="planning.link.summary">
					{{ t('nc_nexus', 'The values below are from the last successful sync.') }}
				</template>
			</p>
			<div v-if="planning.link.summary" class="nx-summary__facts" data-test="planning-summary">
				<div class="nx-fact">
					{{ t('nc_nexus', 'Progress') }}<b data-test="planning-progress">{{ planning.link.summary.progress }} %</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Tasks') }}<b data-test="planning-tasks">{{ planning.link.summary.done }} / {{ planning.link.summary.tasks }}</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Plan') }}<b>{{ planning.link.summary.start ? formatIsoDay(planning.link.summary.start) + ' – ' + formatIsoDay(planning.link.summary.end) : '–' }}</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Overdue') }}<b :class="{ 'planning__overdue': planning.link.summary.overdue }">{{ planning.link.summary.overdue }}</b>
				</div>
			</div>
			<ul v-if="planning.link.summary?.milestones?.length" class="planning__milestones" data-test="planning-milestones">
				<li v-for="(m, i) in planning.link.summary.milestones" :key="i" :class="{ 'planning__milestone--late': !m.done && m.date && m.date < today }">
					<span class="planning__diamond" :class="{ 'planning__diamond--done': m.done }" aria-hidden="true" />
					<span class="planning__date">{{ formatIsoDay(m.date) }}</span>
					<span>{{ m.title }}</span>
				</li>
			</ul>
			<div v-if="planning.link.demand" class="planning__demand" data-test="planning-demand">
				<h4>{{ t('nc_nexus', 'Effort in the plan') }}</h4>
				<table class="planning__table">
					<thead>
						<tr>
							<th>{{ t('nc_nexus', 'Pool') }}</th>
							<th class="planning__num">
								{{ t('nc_nexus', 'Plan') }}
							</th>
							<th class="planning__num">
								{{ t('nc_nexus', 'Resource needs') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="p in planning.link.demand.pools" :key="p.poolId" data-test="planning-demand-pool">
							<td>{{ p.pool }}</td>
							<td class="planning__num">
								{{ formatDays(p.total) }}
							</td>
							<td class="planning__num" :class="{ 'planning__diff': p.current !== p.total }">
								{{ formatDays(p.current) }}
							</td>
						</tr>
						<tr v-for="u in planning.link.demand.unmapped"
							:key="'u' + u.role"
							class="planning__unmapped"
							data-test="planning-demand-unmapped">
							<td>{{ t('nc_nexus', 'Unassigned: {role}', { role: roleLabel(u.role) }) }}</td>
							<td class="planning__num">
								{{ formatDays(u.total) }}
							</td>
							<td class="planning__num">
								–
							</td>
						</tr>
					</tbody>
				</table>
				<p v-if="planning.link.demand.unmapped.length" class="planning__muted">
					{{ t('nc_nexus', 'Effort of roles without pool is not taken over. The portfolio office assigns roles to pools under Planning tools.') }}
				</p>
				<div v-if="planning.link.canApplyDemand" class="planning__actions">
					<NcButton variant="secondary"
						:disabled="busy"
						data-test="planning-demand-apply"
						@click="applyDemand">
						{{ t('nc_nexus', 'Take over as resource needs') }}
					</NcButton>
					<span class="planning__muted">{{ t('nc_nexus', 'Replaces the resource needs of the project by the effort per pool and month from the plan.') }}</span>
				</div>
			</div>
			<p class="planning__muted">
				<template v-if="isFile">
					{{ t('nc_nexus', 'Snapshot of the file {file} from {date}. Import the plan again to update the values.', { file: planning.link.summary?.fileName ?? '', date: formatDate(planning.link.syncedAt) }) }}
				</template>
				<template v-else>
					{{ t('nc_nexus', 'After release the plan leads for dates and progress; the values are read from {tool}.', { tool: planning.link.providerName }) }}
					<span v-if="planning.link.syncedAt" data-test="planning-synced-at">{{ t('nc_nexus', 'Last sync: {date}', { date: formatDate(planning.link.syncedAt) }) }}</span>
				</template>
			</p>
			<div class="planning__actions">
				<NcButton v-if="planning.canImport"
					variant="secondary"
					:disabled="busy"
					data-test="planning-reimport"
					@click="$refs.file.click()">
					{{ t('nc_nexus', 'Import new version') }}
				</NcButton>
				<NcButton v-if="planning.canUnlink"
					variant="secondary"
					data-test="planning-unlink"
					@click="unlink">
					{{ t('nc_nexus', 'Disconnect') }}
				</NcButton>
			</div>
		</template>
		<template v-else-if="planning.canLink">
			<label v-if="planning.providers.length > 1" class="planning__tool">
				<span>{{ t('nc_nexus', 'Planning tool') }}</span>
				<select v-model="providerId" class="nx-input planning__select" data-test="planning-provider">
					<option v-for="p in planning.providers" :key="p.id" :value="p.id">
						{{ p.name }}
					</option>
				</select>
			</label>
			<div v-if="needsKey" class="planning__actions" data-test="planning-credentials">
				<input v-model="secret"
					type="password"
					autocomplete="off"
					class="nx-input planning__select"
					:placeholder="t('nc_nexus', 'Your API key')"
					:aria-label="t('nc_nexus', 'Your API key for {tool}', { tool: provider.name })"
					data-test="planning-secret">
				<NcButton variant="secondary"
					:disabled="!secret.trim() || busy"
					data-test="planning-secret-save"
					@click="storeSecret(secret)">
					{{ t('nc_nexus', 'Save key') }}
				</NcButton>
				<span class="planning__muted">{{ t('nc_nexus', 'The key is stored in the Nextcloud credential store for your account only.') }}</span>
			</div>
			<div v-else-if="provider" class="planning__actions">
				<NcButton v-if="can('create_project')"
					variant="secondary"
					:disabled="busy"
					data-test="planning-create"
					@click="connect(null)">
					{{ t('nc_nexus', 'Create project in {tool}', { tool: provider.name }) }}
				</NcButton>
				<template v-if="can('link_project') && projects.length">
					<select v-model="selected"
						class="nx-input planning__select"
						:aria-label="t('nc_nexus', 'Existing project in {tool}', { tool: provider.name })"
						data-test="planning-projects">
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
						data-test="planning-link"
						@click="connect(selected)">
						{{ t('nc_nexus', 'Link') }}
					</NcButton>
				</template>
				<NcButton v-if="can('import_file')"
					variant="secondary"
					:disabled="busy"
					data-test="planning-import"
					@click="$refs.file.click()">
					{{ t('nc_nexus', 'Import plan (XML) …') }}
				</NcButton>
				<span v-if="can('import_file')" class="planning__muted">{{ t('nc_nexus', 'In MS Project: File → Save as → XML format (*.xml).') }}</span>
				<NcButton v-if="provider.hasCredentials"
					variant="tertiary"
					data-test="planning-secret-remove"
					@click="storeSecret(null)">
					{{ t('nc_nexus', 'Remove API key') }}
				</NcButton>
			</div>
			<p v-if="projectsError" class="planning__muted planning__overdue">
				{{ projectsError }}
			</p>
		</template>
		<div v-if="planning.canExport" class="planning__actions">
			<NcButton variant="secondary"
				:disabled="busy"
				data-test="planning-export"
				@click="exportPlan">
				{{ t('nc_nexus', 'Export starting plan for MS Project (XML)') }}
			</NcButton>
		</div>
		<input ref="file"
			type="file"
			accept=".xml,application/xml,text/xml"
			class="planning__file"
			data-test="planning-file"
			@change="importFile">
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { applyPlanDemand, connectPlanning, disconnectPlanning, errorMessage, exportPlanning, getPlanning, importPlanning, planningProjects, savePlanningCredentials } from '../api.js'
import { formatDate, formatIsoDay } from '../labels.js'

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
		return { t, formatIsoDay, formatDate }
	},
	data() {
		return { planning: null, providerId: null, projects: [], projectsError: '', selected: null, secret: '', busy: false }
	},
	computed: {
		provider() {
			return this.planning?.providers.find(p => p.id === this.providerId) ?? null
		},
		needsKey() {
			return !!this.provider && this.can('credentials') && !this.provider.hasCredentials
		},
		isFile() {
			return !!this.planning?.link?.capabilities.includes('import_file')
		},
		today() {
			return new Date().toISOString().slice(0, 10)
		},
	},
	watch: {
		status: 'load',
		providerId: 'loadProjects',
	},
	mounted() {
		this.load()
	},
	methods: {
		can(capability) {
			return !!this.provider?.capabilities.includes(capability)
		},
		async load() {
			try {
				this.planning = await getPlanning(this.demandId)
				if (this.planning.canLink && !this.provider) {
					// Live-Provider zuerst, die Datei ist der Rückfall
					this.providerId = (this.planning.providers.find(p => p.capabilities.includes('sync')) ?? this.planning.providers[0])?.id ?? null
				} else if (this.planning.canLink) {
					await this.loadProjects()
				}
			} catch (e) {
				this.planning = null
			}
		},
		async loadProjects() {
			this.projects = []
			this.selected = null
			this.projectsError = ''
			if (!this.planning?.canLink || !this.can('link_project') || this.needsKey) {
				return
			}
			try {
				this.projects = await planningProjects(this.providerId)
			} catch (e) {
				this.projectsError = errorMessage(e)
			}
		},
		async connect(projectId) {
			this.busy = true
			try {
				this.planning = await connectPlanning(this.demandId, this.providerId, projectId)
				showSuccess(t('nc_nexus', 'Connected with the planning tool'))
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async importFile(event) {
			const file = event.target.files?.[0]
			event.target.value = ''
			if (!file) {
				return
			}
			this.busy = true
			try {
				const provider = this.planning.link?.provider ?? this.providerId
				this.planning = await importPlanning(this.demandId, file.name, await file.text(), provider)
				showSuccess(t('nc_nexus', 'Plan imported'))
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async exportPlan() {
			try {
				const file = await exportPlanning(this.demandId)
				const url = URL.createObjectURL(new Blob([file.content], { type: 'application/xml' }))
				const a = document.createElement('a')
				a.href = url
				a.download = file.fileName
				document.body.appendChild(a)
				a.click()
				a.remove()
				URL.revokeObjectURL(url)
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		async storeSecret(secret) {
			this.busy = true
			try {
				await savePlanningCredentials(this.providerId, secret)
				this.secret = ''
				this.planning = await getPlanning(this.demandId)
				await this.loadProjects()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		formatDays(days) {
			return t('nc_nexus', '{n} PD', { n: Number(days).toLocaleString(undefined, { maximumFractionDigits: 1 }) })
		},
		roleLabel(role) {
			return role === '' ? t('nc_nexus', 'without role') : role
		},
		async applyDemand() {
			if (!window.confirm(t('nc_nexus', 'Replace the resource needs of this project by the effort from the plan?'))) {
				return
			}
			this.busy = true
			try {
				this.planning = await applyPlanDemand(this.demandId)
				showSuccess(t('nc_nexus', 'Resource needs taken from the plan'))
				this.$emit('changed', { needs: true })
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

.planning__error {
	color: var(--color-error-text);
	margin: 4px 0 8px;
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
	margin-top: 6px;
}

.planning__tool {
	display: flex;
	align-items: center;
	gap: 10px;
	margin-bottom: 6px;
}

.planning__select.nx-input.nx-input {
	width: 280px;
}

.planning__demand {
	margin: 10px 0;

	h4 {
		margin: 0 0 4px;
	}
}

.planning__table {
	border-collapse: collapse;
	min-width: 360px;

	th,
	td {
		padding: 3px 12px 3px 0;
		text-align: start;
		border-bottom: 1px solid var(--color-border);
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: normal;
	}

	.planning__num {
		text-align: end;
		font-variant-numeric: tabular-nums;
	}
}

.planning__diff {
	font-weight: 600;
}

.planning__unmapped td:first-child {
	font-style: italic;
}

.planning__file {
	display: none;
}

.planning__milestones {
	list-style: none;
	margin: 8px 0;
	padding: 0;

	li {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 2px 0;
	}
}

.planning__milestone--late {
	color: var(--color-error-text);
}

.planning__date {
	min-width: 110px;
	font-variant-numeric: tabular-nums;
}

.planning__diamond {
	width: 9px;
	height: 9px;
	transform: rotate(45deg);
	border: 2px solid var(--color-primary-element);
	flex-shrink: 0;

	&--done {
		background: var(--color-primary-element);
	}
}
</style>
