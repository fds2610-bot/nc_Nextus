<template>
	<div class="nx-view" data-test="governance-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Governance') }}</h2>
			<span class="nx-view__count">
				{{ n('nc_nexus', '%n body', '%n bodies', bodies.length) }} · {{ n('nc_nexus', '%n decision right', '%n decision rights', rights.length) }} · {{ n('nc_nexus', '%n gate set', '%n gate sets', gateSets.length) }}
			</span>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Bodies, decision rights and gate sets are configuration, not code. Without a decision right, the board or the portfolio office of the area decides directly.') }}
		</p>

		<section class="nx-view__section">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Bodies') }}</h3>
				<NcButton v-if="canManage"
					variant="secondary"
					data-test="new-body"
					@click="bodyDialog = { body: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New body') }}
				</NcButton>
			</div>
			<p v-if="!bodies.length" class="nx-view__empty">
				{{ t('nc_nexus', 'No bodies yet. Create the portfolio board first and add its members with their seats.') }}
			</p>
			<div v-else class="nx-view__table">
				<template v-for="b in bodies" :key="b.id">
					<div class="nx-row nx-row--click gov__body"
						:data-test="'body-' + b.code"
						:aria-expanded="open.has(b.id)"
						@click="toggle(b.id)">
						<NcIconSvgWrapper class="nx-row__chevron" :path="open.has(b.id) ? mdiChevronDown : mdiChevronRight" />
						<NcIconSvgWrapper :path="mdiAccountGroup" />
						<div class="nx-row__name">
							<span class="nx-row__title">{{ b.name }} <small class="nx-row__muted">{{ b.code }}</small></span>
							<span class="nx-row__meta">{{ bodyTypeLabel(b.bodyType) }}{{ b.active ? '' : ' · ' + t('nc_nexus', 'inactive') }}</span>
						</div>
						<span class="nx-row__meta">{{ t('nc_nexus', 'Quorum {n}', { n: b.quorumMin }) }}{{ b.requiredSeats.length ? ' · ' + b.requiredSeats.join(', ') : '' }}</span>
						<span class="nx-row__meta">{{ n('nc_nexus', '%n member', '%n members', b.members.length) }}</span>
						<div class="nx-row__actions" @click.stop>
							<NcButton v-if="canManage"
								variant="tertiary"
								:aria-label="t('nc_nexus', 'Edit')"
								:data-test="'edit-body-' + b.code"
								@click="bodyDialog = { body: b }">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencil" />
								</template>
							</NcButton>
						</div>
					</div>
					<template v-if="open.has(b.id)">
						<div v-for="m in b.members" :key="m.id" class="nx-row nx-row--sub gov__member">
							<NcAvatar :user="m.userId" :size="28" :disable-menu="true" />
							<span>{{ m.userId }}<template v-if="m.userId === b.chairId"> · {{ t('nc_nexus', 'Chair') }}</template></span>
							<span class="nx-row__meta">{{ m.seat || '–' }}</span>
							<span class="nx-row__meta">{{ m.voting ? t('nc_nexus', 'voting') : t('nc_nexus', 'advisory') }}</span>
							<span class="nx-row__meta">{{ m.deputyId ? t('nc_nexus', 'Deputy: {user}', { user: m.deputyId }) : '' }}</span>
						</div>
					</template>
				</template>
			</div>
		</section>

		<section class="nx-view__section">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Decision rights') }}</h3>
				<NcButton v-if="canManage"
					variant="secondary"
					data-test="new-right"
					@click="rightDialog = { right: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New decision right') }}
				</NcButton>
			</div>
			<p class="nx-view__hint">
				{{ retired ? n('nc_nexus', '%n earlier version is kept for the audit.', '%n earlier versions are kept for the audit.', retired) : '' }}
			</p>
			<div class="nx-view__table">
				<div class="nx-row gov__right gov__right--fixed"
					:title="t('nc_nexus', 'Fixed: the portfolio office of the area qualifies every demand; it cannot be changed.')"
					data-test="rights-g1">
					<b>G1</b>
					<span>{{ gateLabel('g1') }}</span>
					<div class="gov__right-list">
						<span class="nx-row__meta">{{ t('nc_nexus', 'Portfolio office only') }}</span>
					</div>
				</div>
				<div v-for="g in gates"
					:key="g"
					class="nx-row gov__right"
					:data-test="'rights-' + g">
					<b>{{ g === 'change' ? 'CR' : g.toUpperCase() }}</b>
					<span>{{ gateLabel(g) }}</span>
					<div class="gov__right-list">
						<span v-if="!rightsFor(g).length" class="nx-row__meta">
							{{ t('nc_nexus', 'Board or portfolio office of the area (directly)') }}
						</span>
						<button v-for="r in rightsFor(g)"
							:key="r.id"
							type="button"
							class="gov__right-chip"
							:disabled="!canManage"
							:data-test="'right-' + r.id"
							@click="rightDialog = { right: r }">
							{{ r.projectClasses ? r.projectClasses.join(', ') : t('nc_nexus', 'all classes') }} →
							<b>{{ r.bodyId ? bodyName(r.bodyId) : t('nc_nexus', 'directly') }}</b>
							<template v-if="r.quorumMin">
								· {{ t('nc_nexus', 'Quorum {n}', { n: r.quorumMin }) }}
							</template>
						</button>
					</div>
				</div>
			</div>
			<GateProcess :gate-sets="gateSets" :rights="rights" :bodies="bodies" />
		</section>

		<section class="nx-view__section">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Gate sets') }}</h3>
				<NcButton v-if="canManage"
					variant="secondary"
					data-test="new-gate-set"
					@click="setDialog = { set: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New gate set') }}
				</NcButton>
			</div>
			<div class="nx-view__table">
				<div v-for="s in gateSets"
					:key="s.id"
					class="nx-row gov__set"
					:class="{ 'nx-row--click': canManage }"
					:data-test="'gate-set-' + s.name"
					@click="canManage && (setDialog = { set: s })">
					<NcIconSvgWrapper :path="mdiSourceBranch" />
					<div class="nx-row__name">
						<span class="nx-row__title">{{ s.name }}</span>
						<span class="nx-row__meta">
							{{ s.projectClasses.length ? t('nc_nexus', 'Classes {list}', { list: s.projectClasses.join(', ') }) : t('nc_nexus', 'all classes') }}
							<template v-if="s.demandTypes.length">
								· {{ s.demandTypes.map(typeLabel).join(', ') }}
							</template>
						</span>
					</div>
					<div class="nx-gates">
						<span class="nx-gates__fixed" :title="gateLabel('g1') + ' · ' + t('nc_nexus', 'Portfolio office only')">
							G1
						</span>
						<span v-for="g in s.gates" :key="g.code" :title="g.name + (g.requiredChecks.length ? ': ' + g.requiredChecks.map(checkLabel).join(', ') : '')">
							{{ g.code.toUpperCase() }}{{ g.budgetTranche ? ' €' : '' }}
						</span>
					</div>
					<span class="nx-row__meta">{{ t('nc_nexus', 'Version {n}', { n: s.version }) }}</span>
				</div>
			</div>
		</section>

		<section v-if="settings" class="nx-view__section" data-test="thresholds">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Thresholds') }}</h3>
				<NcButton v-if="canManage"
					variant="secondary"
					:disabled="!settingsDirty"
					data-test="save-thresholds"
					@click="storeSettings">
					{{ t('nc_nexus', 'Save') }}
				</NcButton>
			</div>
			<p class="nx-view__hint">
				{{ t('nc_nexus', 'Change requests above these thresholds go to the body of the decision rights (type “change request”); tolerances feed the health indicator and the escalation of conflicts.') }}
			</p>
			<div class="nx-view__table gov__thresholds">
				<label v-for="key in Object.keys(settings)"
					:key="key"
					class="gov__threshold"
					:data-test="'threshold-' + key">
					<span>{{ thresholdLabel(key) }}</span>
					<NxNumberInput v-model="settings[key]"
						:min="0"
						:max-decimals="1"
						:disabled="!canManage"
						class="nx-input" />
				</label>
			</div>
		</section>

		<section v-if="settings" class="nx-view__section" data-test="project-classes">
			<div class="nx-view__section-head">
				<h3>{{ t('nc_nexus', 'Project classes') }}</h3>
			</div>
			<p class="nx-view__hint">
				{{ t('nc_nexus', 'The classes are fixed in the app. The portfolio office assigns them when accepting a demand (G1); they select the gate set, the decision rights and the status report cadence.') }}
				{{ t('nc_nexus', 'The limits are set under thresholds; the higher criterion counts, a limit of 0 is not used.') }}
			</p>
			<div class="nx-view__table">
				<div v-for="c in classes"
					:key="c"
					class="nx-row gov__class"
					:data-test="'class-' + c">
					<b>{{ c }}</b>
					<div class="nx-row__name" data-test="class-criteria">
						<span class="nx-row__title">{{ classCriteria(c) || '–' }}</span>
						<span v-if="classNote(c)" class="nx-row__meta">{{ classNote(c) }}</span>
					</div>
					<div class="nx-row__name">
						<span class="nx-row__title">{{ setsForClass(c).map(s => s.name).join(', ') || '–' }}</span>
						<span class="nx-row__meta">
							{{ classRights(c).length
								? t('nc_nexus', 'Own decision rights: {list}', { list: classRights(c).join(', ') })
								: t('nc_nexus', 'Decision rights for all classes') }}
						</span>
					</div>
					<span class="nx-row__meta">
						{{ t('nc_nexus', 'Status report every {n} days', { n: classReportDays(c) }) }}
					</span>
				</div>
			</div>
		</section>

		<BodyDialog v-if="bodyDialog"
			:body="bodyDialog.body"
			@close="bodyDialog = null"
			@changed="load" />
		<RightDialog v-if="rightDialog"
			:right="rightDialog.right"
			:bodies="bodies.filter(b => b.active)"
			@close="rightDialog = null"
			@changed="load" />
		<GateSetDialog v-if="setDialog"
			:set="setDialog.set"
			:checks="checks"
			@close="setDialog = null"
			@changed="load" />
	</div>
</template>

<script>
import { mdiAccountGroup, mdiChevronDown, mdiChevronRight, mdiPencil, mdiPlus, mdiSourceBranch } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import BodyDialog from '../components/BodyDialog.vue'
import GateProcess from '../components/GateProcess.vue'
import GateSetDialog from '../components/GateSetDialog.vue'
import RightDialog from '../components/RightDialog.vue'
import { loadState } from '@nextcloud/initial-state'
import { errorMessage, getSettings, governance, saveSettings } from '../api.js'
import NxNumberInput from '../components/NxNumberInput.vue'
import { bodyTypeLabel, checkLabel, formatMoney, formatNumber, gateLabel, typeLabel } from '../labels.js'

export default {
	name: 'GovernanceView',
	components: { BodyDialog, GateProcess, GateSetDialog, NcAvatar, NcButton, NcIconSvgWrapper, NxNumberInput, RightDialog },
	emits: ['changed'],
	setup() {
		return { mdiAccountGroup, mdiChevronDown, mdiChevronRight, mdiPencil, mdiPlus, mdiSourceBranch, n, t, bodyTypeLabel, checkLabel, gateLabel, typeLabel, gates: ['g2', 'g3', 'g4', 'g5', 'g6', 'change'], classes: loadState('nc_nexus', 'project_classes', []) }
	},
	data() {
		return { canManage: false, bodies: [], rights: [], retired: 0, gateSets: [], checks: [], open: new Set(), bodyDialog: null, rightDialog: null, setDialog: null, settings: null, settingsSaved: '' }
	},
	computed: {
		settingsDirty() {
			return JSON.stringify(this.settings) !== this.settingsSaved
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				const g = await governance()
				this.canManage = g.canManage
				this.bodies = g.bodies
				this.rights = g.rights.rights
				this.retired = g.rights.retired
				this.gateSets = g.gateSets
				this.checks = g.checks
				if (!this.settings) {
					await this.loadSettings()
				}
				// Dialog mit aktuellem Gremium versorgen (Mitglieder)
				if (this.bodyDialog?.body) {
					this.bodyDialog = { body: this.bodies.find(b => b.id === this.bodyDialog.body.id) ?? null }
				}
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		toggle(id) {
			const open = new Set(this.open)
			if (!open.delete(id)) {
				open.add(id)
			}
			this.open = open
		},
		async loadSettings() {
			const result = await getSettings()
			this.settings = { ...result.values }
			this.settingsSaved = JSON.stringify(this.settings)
		},
		async storeSettings() {
			try {
				const result = await saveSettings(this.settings)
				this.settings = { ...result.values }
				this.settingsSaved = JSON.stringify(this.settings)
				showSuccess(t('nc_nexus', 'Thresholds saved'))
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		thresholdLabel(key) {
			return {
				crBudgetPct: t('nc_nexus', 'Change request: budget increase (%)'),
				crBudgetAbs: t('nc_nexus', 'Change request: budget increase (€)'),
				crDays: t('nc_nexus', 'Change request: delay (days)'),
				crPersonDaysPct: t('nc_nexus', 'Change request: extra effort in a bottleneck pool (%)'),
				facTolerancePct: t('nc_nexus', 'Forecast above budget: tolerance (%)'),
				reportDaysLarge: t('nc_nexus', 'Status report cadence M/L (days)'),
				reportDaysSmall: t('nc_nexus', 'Status report cadence XS/S (days)'),
				effortDeviationPct: t('nc_nexus', 'Actual effort deviation (%)'),
				riskEscalationScore: t('nc_nexus', 'Risk score for escalation'),
				conflictLevel1Days: t('nc_nexus', 'Conflict: days until resource board'),
				conflictLevel2Days: t('nc_nexus', 'Conflict: days until portfolio board'),
				conditionReminderDays: t('nc_nexus', 'Reminder before a condition is due (days)'),
				recalcWindowMonths: t('nc_nexus', 'Post-calculation: window of completed projects (months)'),
				recalcMinProjects: t('nc_nexus', 'Post-calculation: projects needed for a correction factor'),
				classDaysS: t('nc_nexus', 'Class S from (person days)'),
				classDaysM: t('nc_nexus', 'Class M from (person days)'),
				classDaysL: t('nc_nexus', 'Class L from (person days)'),
				classBudgetS: t('nc_nexus', 'Class S from (€)'),
				classBudgetM: t('nc_nexus', 'Class M from (€)'),
				classBudgetL: t('nc_nexus', 'Class L from (€)'),
			}[key] ?? key
		},
		rightsFor(gate) {
			return this.rights.filter(r => r.gateCode === gate)
		},
		/**
		 * Gate-Sets einer Klasse wie gateSetFor(): eigene Sets, sonst die ohne Klasseneinschränkung
		 *
		 * @param {string} c Vorhabensklasse
		 */
		setsForClass(c) {
			const own = this.gateSets.filter(s => s.projectClasses.includes(c))
			return own.length ? own : this.gateSets.filter(s => !s.projectClasses.length)
		},
		classRights(c) {
			return [...new Set(this.rights.filter(r => r.projectClasses?.includes(c)).map(r => r.gateCode === 'change' ? 'CR' : r.gateCode.toUpperCase()))]
		},
		/**
		 * Bereich einer Klasse aus den Untergrenzen (Personentage und Budget, 0 = nicht genutzt)
		 *
		 * @param {string} c Vorhabensklasse
		 */
		classCriteria(c) {
			const i = this.classes.indexOf(c)
			const range = (prefix, format) => {
				const bound = (k) => (k > 0 && k < this.classes.length ? this.settings[prefix + this.classes[k]] : 0) || 0
				const lo = bound(i)
				const hi = bound(i + 1)
				if (lo && hi) {
					return format(lo) + '–' + format(hi)
				}
				if (hi) {
					return t('nc_nexus', 'below {value}', { value: format(hi) })
				}
				return lo ? t('nc_nexus', 'from {value}', { value: format(lo) }) : ''
			}
			const days = range('classDays', v => formatNumber(v))
			const budget = range('classBudget', formatMoney)
			return [
				days ? t('nc_nexus', '{range} person days', { range: days }) : '',
				budget ? t('nc_nexus', 'budget {range}', { range: budget }) : '',
			].filter(Boolean).join(' · ')
		},
		classNote(c) {
			return {
				XS: t('nc_nexus', 'Service request, no bottleneck skill'),
				S: t('nc_nexus', 'Small undertaking'),
				M: t('nc_nexus', 'Regular project'),
				L: t('nc_nexus', 'Major project or programme; also when strategically or regulatorily critical'),
			}[c] ?? ''
		},
		classReportDays(c) {
			return this.settings[['XS', 'S'].includes(c) ? 'reportDaysSmall' : 'reportDaysLarge'] ?? 0
		},
		bodyName(id) {
			return this.bodies.find(b => b.id === id)?.name ?? '?'
		},
	},
}
</script>

<style scoped lang="scss">
.gov__body {
	grid-template-columns: auto auto minmax(0, 1fr) minmax(0, 1fr) 110px 44px;
}

.gov__member {
	grid-template-columns: 28px minmax(0, 1fr) minmax(0, 1fr) 110px minmax(0, 1fr);
}

.gov__right {
	grid-template-columns: 34px 220px minmax(0, 1fr);
}

.gov__right--fixed {
	opacity: .55;
	cursor: default;
}

.nx-gates__fixed {
	opacity: .55;
}

.gov__right-list {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.gov__right-chip {
	min-height: 30px;
	margin: 0;
	padding: 2px 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill, 20px);
	background: var(--color-main-background);
	font-weight: normal;
	cursor: pointer;

	&:hover:not(:disabled) {
		background: var(--color-background-hover);
	}

	&:disabled {
		cursor: default;
		opacity: 1;
	}
}

.gov__thresholds {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
	gap: 0 16px;
	padding: 8px 12px;
}

.gov__threshold {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 110px;
	gap: 10px;
	align-items: center;
	padding: 4px 0;
	font-size: 13px;
}

.gov__class {
	grid-template-columns: 40px minmax(0, 1fr) minmax(0, 1fr) auto;
}

.gov__set {
	grid-template-columns: auto minmax(0, 1fr) auto 90px;
}
</style>
