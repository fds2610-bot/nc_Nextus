<template>
	<div class="nx-view" data-test="conflicts-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Resource conflicts') }}</h2>
			<span class="nx-view__count">{{ n('nc_nexus', '%n open', '%n open', open.length) }}</span>
			<span class="nx-view__spacer" />
			<NcButton v-if="canDetect"
				variant="secondary"
				data-test="detect-conflicts"
				:disabled="busy"
				@click="detect">
				{{ t('nc_nexus', 'Check now') }}
			</NcButton>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Detected daily. A conflict starts bilaterally (level 1) and rises automatically to the resource board (2) and the portfolio board (3) when its deadline passes – it cannot be sat out.') }}
		</p>
		<p v-if="!conflicts.length" class="nx-view__empty">
			{{ t('nc_nexus', 'No conflicts detected.') }}
		</p>
		<div v-else class="nx-view__table">
			<div v-for="c in conflicts"
				:key="c.id"
				class="nx-row conf__row"
				:class="{ 'conf__closed': c.status !== 'open' }"
				:data-test="'conflict-' + c.id">
				<span class="nx-chip" :class="c.status !== 'open' ? 'nx-chip--accepted' : (c.escalationLevel >= 2 ? 'nx-chip--rejected' : 'nx-chip--on-hold')">
					{{ c.status === 'open' ? escalationLabel(c.escalationLevel) : (c.status === 'accepted' ? t('nc_nexus', 'accepted') : t('nc_nexus', 'resolved')) }}
				</span>
				<div class="nx-row__name">
					<span class="nx-row__title">{{ conflictTypeLabel(c.conflictType) }}<template v-if="c.poolName"> · {{ c.poolName }}</template><template v-if="c.period"> · {{ formatMonth(c.period) }}</template></span>
					<span class="nx-row__meta">
						{{ c.detail }}
						<template v-if="c.status !== 'open' && c.resolutionOption"> · {{ conflictOptionLabel(c.resolutionOption) }}</template>
						<template v-if="c.resolutionNote"> · {{ c.resolutionNote }}</template>
					</span>
				</div>
				<div class="conf__projects">
					<a v-for="p in c.projects"
						:key="p.id"
						href="#"
						@click.prevent="$emit('open', p.id)">{{ p.key }}</a>
				</div>
				<span class="nx-row__meta">{{ c.status === 'open' && c.escalateAt ? t('nc_nexus', 'escalates {date}', { date: formatDay(c.escalateAt) }) : '' }}</span>
				<div class="nx-row__actions">
					<NcButton v-if="c.can.resolve"
						variant="secondary"
						data-test="resolve-conflict"
						@click="resolving = c">
						{{ t('nc_nexus', 'Resolve') }}
					</NcButton>
				</div>
			</div>
		</div>

		<NxDialog v-if="resolving"
			:title="conflictTypeLabel(resolving.conflictType) + (resolving.poolName ? ' · ' + resolving.poolName : '')"
			:subtitle="t('nc_nexus', 'Check the options from top to bottom and document why the cheaper ones are not possible (chapter 01 §7).')"
			size="large"
			data-test="resolve-dialog"
			@close="resolving = null">
			<label class="conf__accept">
				<input v-model="form.accept" type="checkbox" data-test="resolve-accept">
				<span>{{ t('nc_nexus', 'Accept the conflict consciously (no resolution)') }}</span>
			</label>
			<template v-if="!form.accept">
				<label v-for="(o, i) in options"
					:key="o"
					class="conf__option"
					:data-test="'resolve-option-' + o">
					<span class="conf__option-head">
						<input v-model="form.option" type="radio" :value="o">
						<b>{{ i + 1 }}. {{ conflictOptionLabel(o) }}</b>
					</span>
					<input v-if="chosenIndex > i"
						v-model="form.rejected[o]"
						class="nx-input"
						:placeholder="t('nc_nexus', 'Why not possible?')"
						:data-test="'resolve-why-' + o">
				</label>
			</template>
			<label class="nx-field" data-test="resolve-note">
				<span>{{ t('nc_nexus', 'Reason') }} *</span>
				<textarea v-model="form.note" class="nx-input" />
			</label>
			<template #actions>
				<NcButton variant="tertiary" @click="resolving = null">
					{{ t('nc_nexus', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="busy || !canSave"
					data-test="save-resolution"
					@click="resolve">
					{{ form.accept ? t('nc_nexus', 'Accept') : t('nc_nexus', 'Resolve') }}
				</NcButton>
			</template>
		</NxDialog>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from '../components/NxDialog.vue'
import { detectConflicts, errorMessage, listConflicts, resolveConflict } from '../api.js'
import { conflictOptionLabel, conflictTypeLabel, escalationLabel, formatDay, formatMonth } from '../labels.js'

export default {
	name: 'ConflictsView',
	components: { NcButton, NxDialog },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { n, t, conflictOptionLabel, conflictTypeLabel, escalationLabel, formatDay, formatMonth }
	},
	data() {
		return { conflicts: [], options: [], canDetect: false, busy: false, resolving: null, form: { accept: false, option: 'sequenced', rejected: {}, note: '' } }
	},
	computed: {
		open() {
			return this.conflicts.filter(c => c.status === 'open')
		},
		chosenIndex() {
			return this.options.indexOf(this.form.option)
		},
		canSave() {
			if (!this.form.note.trim()) {
				return false
			}
			return this.form.accept || this.options.slice(0, this.chosenIndex).every(o => (this.form.rejected[o] || '').trim())
		},
	},
	watch: {
		refreshKey() {
			this.load()
		},
		resolving() {
			this.form = { accept: false, option: 'sequenced', rejected: {}, note: '' }
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		apply(result) {
			this.conflicts = result.conflicts
			this.options = result.options
			this.canDetect = result.canDetect
		},
		async load() {
			try {
				this.apply(await listConflicts())
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		async detect() {
			this.busy = true
			try {
				this.apply(await detectConflicts())
				showSuccess(t('nc_nexus', 'Conflict check finished'))
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async resolve() {
			this.busy = true
			try {
				this.apply(await resolveConflict(this.resolving.id, this.form))
				showSuccess(t('nc_nexus', 'Conflict closed'))
				this.resolving = null
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.conf__row {
	grid-template-columns: 200px minmax(0, 1fr) 160px 150px auto;
}

.conf__closed {
	color: var(--color-text-maxcontrast);
}

.conf__projects {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	font-size: 13px;
}

.conf__accept,
.conf__option-head {
	display: flex;
	gap: 8px;
	align-items: center;
}

.conf__accept {
	margin-bottom: 10px;
}

.conf__option {
	display: block;
	padding: 4px 0;

	.nx-input {
		margin: 4px 0 0 26px;
		max-width: calc(100% - 26px);
	}
}
</style>
