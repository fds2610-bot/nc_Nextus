<template>
	<div class="nx-view" data-test="planning-tools-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Planning tools') }}</h2>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Released projects are connected with one of these tools in the section “Planning” of the project. After release the plan leads for dates and progress.') }}
		</p>
		<section v-if="form" class="nx-view__section">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Connections') }}</h3>
				<NcButton v-if="config.canManage"
					variant="primary"
					:disabled="!dirty || busy"
					data-test="save-planning-tools"
					@click="save">
					{{ t('nc_nexus', 'Save') }}
				</NcButton>
			</div>
			<div class="nx-view__table">
				<!-- Gantt -->
				<div class="tools__row" data-test="tool-gantt">
					<div class="tools__name">
						<NcIconSvgWrapper :path="mdiChartGantt" />
						<b>Gantt</b>
					</div>
					<div class="tools__status">
						<span class="nx-chip" :class="config.ganttLocal ? 'nx-chip--accepted' : 'nx-chip--draft'" data-test="gantt-local">
							{{ config.ganttLocal ? t('nc_nexus', 'App found on this Nextcloud') : t('nc_nexus', 'App not found on this Nextcloud') }}
						</span>
						<span v-if="config.ganttUrl" class="nx-chip nx-chip--submitted" data-test="gantt-remote">{{ t('nc_nexus', 'Uses other Nextcloud') }}</span>
					</div>
					<div class="tools__fields">
						<p class="tools__label">
							{{ t('nc_nexus', 'Alternatively: Gantt on another Nextcloud') }}
						</p>
						<input v-model.trim="form.ganttUrl"
							type="url"
							class="nx-input"
							placeholder="https://cloud.example.com"
							:aria-label="t('nc_nexus', 'Address of the other Nextcloud')"
							:disabled="!config.canManage"
							data-test="gantt-url">
						<div v-if="form.ganttUrl" class="tools__pair">
							<input v-model.trim="form.ganttUser"
								type="text"
								class="nx-input"
								autocomplete="off"
								:placeholder="t('nc_nexus', 'Account')"
								:aria-label="t('nc_nexus', 'Account')"
								:disabled="!config.canManage"
								data-test="gantt-user">
							<input v-model="form.ganttPassword"
								type="password"
								class="nx-input"
								autocomplete="new-password"
								:placeholder="config.ganttHasPassword ? t('nc_nexus', 'App password (stored)') : t('nc_nexus', 'App password')"
								:aria-label="t('nc_nexus', 'App password')"
								:disabled="!config.canManage"
								data-test="gantt-password">
						</div>
						<p v-if="form.ganttUrl" class="tools__hint">
							{{ t('nc_nexus', 'Create an app password for this account on the other Nextcloud under Personal settings → Security → Devices & sessions. All Nexus accounts use this account and see its Gantt projects; the address replaces the Gantt app of this Nextcloud.') }}
							{{ t('nc_nexus', 'Enter the address under which this server reaches the other Nextcloud – “localhost” means this server itself (with Docker e.g. http://host.docker.internal:8088).') }}
						</p>
						<div v-if="config.ganttUrl && !dirty" class="tools__pair">
							<NcButton variant="secondary"
								:disabled="busy"
								data-test="gantt-test"
								@click="test">
								{{ t('nc_nexus', 'Test connection') }}
							</NcButton>
							<span v-if="testResult" :class="testResult.ok ? 'tools__ok' : 'tools__bad'" data-test="gantt-test-result">{{ testResult.text }}</span>
						</div>
					</div>
				</div>

				<!-- OpenProject -->
				<div class="tools__row" data-test="tool-openproject">
					<div class="tools__name">
						<NcIconSvgWrapper :path="mdiClipboardTextOutline" />
						<b>OpenProject</b>
					</div>
					<div class="tools__status">
						<span class="nx-chip" :class="config.openprojectUrl ? 'nx-chip--accepted' : 'nx-chip--draft'" data-test="openproject-status">
							{{ config.openprojectUrl ? t('nc_nexus', 'set up') : t('nc_nexus', 'not set up') }}
						</span>
					</div>
					<div class="tools__fields">
						<input v-model.trim="form.openprojectUrl"
							type="url"
							class="nx-input"
							placeholder="https://openproject.example.com"
							:aria-label="t('nc_nexus', 'OpenProject address')"
							:disabled="!config.canManage"
							data-test="openproject-url">
						<p class="tools__hint">
							{{ t('nc_nexus', 'Every account stores its own API key at the project (OpenProject: My account → Access tokens).') }}
						</p>
					</div>
				</div>

				<!-- MS Project -->
				<div class="tools__row" data-test="tool-mspdi">
					<div class="tools__name">
						<NcIconSvgWrapper :path="mdiFileDelimitedOutline" />
						<b>MS Project (XML)</b>
					</div>
					<div class="tools__status">
						<span class="nx-chip" :class="config.mspdiImport || config.mspdiExport ? 'nx-chip--accepted' : 'nx-chip--draft'">
							{{ config.mspdiImport || config.mspdiExport ? t('nc_nexus', 'active') : t('nc_nexus', 'off') }}
						</span>
					</div>
					<div class="tools__fields tools__switches">
						<NcCheckboxRadioSwitch v-model="form.mspdiImport"
							type="switch"
							:disabled="!config.canManage"
							data-test="mspdi-import">
							{{ t('nc_nexus', 'Import of plans') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch v-model="form.mspdiExport"
							type="switch"
							:disabled="!config.canManage"
							data-test="mspdi-export">
							{{ t('nc_nexus', 'Export of starting plans') }}
						</NcCheckboxRadioSwitch>
						<p class="tools__hint">
							{{ t('nc_nexus', 'Import reads a plan saved as XML and shows it as snapshot. Export creates a starting plan with the project frame and the governance milestones. Both are offered at the project.') }}
						</p>
					</div>
				</div>
			</div>
		</section>
		<section v-if="roles" class="nx-view__section" data-test="role-pool">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Role → pool') }}</h3>
			</div>
			<p class="nx-view__hint">
				{{ t('nc_nexus', 'Planning tools plan people or roles, the governance plans pools. Assign each role from the plans to a pool; the effort of a project can then be taken over as resource needs. People are summarized to roles before saving (MS Project: resource group; generic resources with their name). Effort without role (Gantt, OpenProject) can be assigned as a whole.') }}
			</p>
			<p v-if="!roles.roles.length" class="tools__hint" data-test="role-pool-empty">
				{{ t('nc_nexus', 'No plan with effort is connected yet.') }}
			</p>
			<div v-else class="nx-view__table">
				<div v-for="r in roles.roles"
					:key="r.provider + '|' + r.role"
					class="roles__row"
					:data-test="'role-' + r.provider + '-' + (r.role || '-')">
					<span class="roles__tool">{{ r.providerName }}</span>
					<b :class="{ 'roles__none': r.role === '' }">{{ r.role || t('nc_nexus', 'without role') }}</b>
					<span class="tools__hint">{{ n('nc_nexus', '%n project', '%n projects', r.projects) }} · {{ t('nc_nexus', '{n} PD', { n: r.days.toLocaleString(undefined, { maximumFractionDigits: 1 }) }) }}</span>
					<select class="nx-input"
						:value="r.poolId ?? ''"
						:aria-label="t('nc_nexus', 'Pool for {role}', { role: r.role || t('nc_nexus', 'without role') })"
						:disabled="busy"
						data-test="role-pool-select"
						@change="saveRole(r, $event.target.value)">
						<option value="">
							{{ t('nc_nexus', '– not assigned –') }}
						</option>
						<option v-for="p in roles.pools" :key="p.id" :value="p.id">
							{{ p.name }}
						</option>
					</select>
				</div>
			</div>
		</section>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiChartGantt, mdiClipboardTextOutline, mdiFileDelimitedOutline } from '@mdi/js'
import { errorMessage, getPlanningConfig, getPlanningRoles, savePlanningConfig, savePlanningRole, testGanttConnection } from '../api.js'

const FIELDS = ['ganttUrl', 'ganttUser', 'openprojectUrl', 'mspdiImport', 'mspdiExport']

export default {
	name: 'PlanningToolsView',
	components: { NcButton, NcCheckboxRadioSwitch, NcIconSvgWrapper },
	setup() {
		return { n, t, mdiChartGantt, mdiClipboardTextOutline, mdiFileDelimitedOutline }
	},
	data() {
		return { config: null, form: null, roles: null, busy: false, testResult: null }
	},
	computed: {
		dirty() {
			return !!this.form && (FIELDS.some(k => this.form[k] !== this.config[k]) || this.form.ganttPassword !== '')
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		apply(config) {
			this.config = config
			this.form = { ...Object.fromEntries(FIELDS.map(k => [k, config[k]])), ganttPassword: '' }
		},
		async load() {
			try {
				this.apply(await getPlanningConfig())
				if (this.config.canManage) {
					this.roles = await getPlanningRoles()
				}
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		async saveRole(role, value) {
			this.busy = true
			try {
				this.roles = await savePlanningRole(role.provider, role.role, value === '' ? null : Number(value))
				showSuccess(t('nc_nexus', 'Assignment saved'))
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async save() {
			this.busy = true
			try {
				this.apply(await savePlanningConfig(this.form))
				this.testResult = null
				showSuccess(t('nc_nexus', 'Planning tools saved'))
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async test() {
			this.busy = true
			try {
				const result = await testGanttConnection()
				this.testResult = { ok: true, text: t('nc_nexus', 'Connected – {n} projects visible', { n: result.projects }) }
			} catch (e) {
				this.testResult = { ok: false, text: errorMessage(e) }
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.tools__row {
	display: grid;
	grid-template-columns: 200px 260px minmax(0, 1fr);
	gap: 16px;
	align-items: start;
	padding: 14px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

.tools__name {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 34px;
}

.tools__status {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding-top: 5px;
}

.tools__fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 560px;

	.nx-input {
		width: 100%;
	}
}

.tools__label {
	margin: 0;
	font-weight: 600;
	min-height: 0;
}

.tools__pair {
	display: flex;
	gap: 8px;
	align-items: center;
}

.tools__switches {
	flex-direction: row;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px 24px;

	.tools__hint {
		flex-basis: 100%;
	}
}

.tools__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.tools__ok {
	color: var(--color-success-text);
}

.tools__bad {
	color: var(--color-error-text);
}

.roles__row {
	display: grid;
	grid-template-columns: 160px minmax(0, 1fr) 160px 280px;
	gap: 12px;
	align-items: center;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	.nx-input {
		width: 100%;
	}
}

.roles__tool {
	color: var(--color-text-maxcontrast);
}

.roles__none {
	font-weight: normal;
	font-style: italic;
}

@media (max-width: 1000px) {
	.roles__row {
		grid-template-columns: minmax(0, 1fr);
		gap: 4px;
	}

	.tools__row {
		grid-template-columns: minmax(0, 1fr);
		gap: 8px;
	}
}
</style>
