<template>
	<div class="nx-view" data-test="conditions-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Decision follow-up') }}</h2>
			<span class="nx-view__count">{{ n('nc_nexus', '%n open', '%n open', openCount) }} · {{ n('nc_nexus', '%n overdue', '%n overdue', overdueCount) }}</span>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'Conditions and actions from decisions, with owner and due date. Blocking conditions lock the next gate of the project until they are fulfilled.') }}
		</p>
		<div class="cond__filters" role="group" :aria-label="t('nc_nexus', 'Filter')">
			<NcButton v-for="f in filters"
				:key="f.key"
				:variant="filter === f.key ? 'primary' : 'tertiary'"
				:data-test="'filter-' + f.key"
				@click="filter = f.key">
				{{ f.label }}
			</NcButton>
		</div>
		<p v-if="!shown.length" class="nx-view__empty" data-test="conditions-empty">
			{{ t('nc_nexus', 'Nothing to follow up here.') }}
		</p>
		<div v-else class="nx-view__table">
			<div v-for="c in shown"
				:key="c.id"
				class="nx-row cond__row"
				:class="{ 'nx-row--warn': c.overdue }"
				:data-test="'condition-' + c.id">
				<span class="nx-chip" :class="chip(c)">{{ kindText(c) }}</span>
				<div class="nx-row__name">
					<span class="nx-row__title">{{ c.title }}</span>
					<a href="#" class="nx-row__meta cond__link" @click.prevent="$emit('open', c.demandId)">{{ c.demandKey }} · {{ c.demandTitle }}</a>
				</div>
				<span class="nx-row__meta">{{ c.ownerId }}</span>
				<span class="nx-row__meta" :class="{ 'cond__overdue': c.overdue }">
					{{ formatIsoDay(c.dueDate) }}<template v-if="c.overdue"> · {{ t('nc_nexus', 'overdue') }}</template>
				</span>
				<span v-if="c.status !== 'open'" class="nx-row__meta cond__evidence" :title="c.evidence">
					{{ c.status === 'done' ? t('nc_nexus', 'done') : t('nc_nexus', 'waived') }} · {{ c.evidence }}
				</span>
				<div v-else class="nx-row__actions">
					<NcButton v-if="c.can.done"
						variant="secondary"
						data-test="condition-done"
						@click="closing = { condition: c, status: 'done' }">
						{{ t('nc_nexus', 'Done') }}
					</NcButton>
					<NcButton v-if="c.can.waive"
						variant="tertiary"
						data-test="condition-waive"
						@click="closing = { condition: c, status: 'waived' }">
						{{ t('nc_nexus', 'Waive') }}
					</NcButton>
				</div>
			</div>
		</div>

		<NxDialog v-if="closing"
			:title="closing.condition.title"
			:subtitle="closing.status === 'done' ? t('nc_nexus', 'Describe the evidence, e.g. a document or a result.') : t('nc_nexus', 'Why is the condition no longer needed?')"
			data-test="close-condition-dialog"
			@close="closing = null">
			<label class="nx-field">
				<span>{{ closing.status === 'done' ? t('nc_nexus', 'Evidence') : t('nc_nexus', 'Reason') }} *</span>
				<textarea v-model="evidence" class="nx-input" data-test="condition-evidence" />
			</label>
			<template #actions>
				<NcButton variant="tertiary" @click="closing = null">
					{{ evidence.trim() ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
				</NcButton>
				<NcButton variant="primary"
					:disabled="busy || !evidence.trim()"
					data-test="save-condition"
					@click="save">
					{{ closing.status === 'done' ? t('nc_nexus', 'Mark as done') : t('nc_nexus', 'Waive') }}
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
import { closeCondition, errorMessage, listConditions } from '../api.js'
import { formatIsoDay } from '../labels.js'

export default {
	name: 'ConditionsView',
	components: { NcButton, NxDialog },
	props: {
		me: { type: String, default: '' },
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open', 'changed'],
	setup() {
		return { n, t, formatIsoDay }
	},
	data() {
		return { items: [], filter: 'open', closing: null, evidence: '', busy: false }
	},
	computed: {
		filters() {
			return [
				{ key: 'open', label: t('nc_nexus', 'Pending') },
				{ key: 'mine', label: t('nc_nexus', 'Mine') },
				{ key: 'overdue', label: t('nc_nexus', 'Overdue') },
				{ key: 'closed', label: t('nc_nexus', 'Closed') },
			]
		},
		shown() {
			return this.items.filter(c => ({
				open: c.status === 'open',
				mine: c.status === 'open' && c.ownerId === this.me,
				overdue: c.overdue,
				closed: c.status !== 'open',
			})[this.filter])
		},
		openCount() {
			return this.items.filter(c => c.status === 'open').length
		},
		overdueCount() {
			return this.items.filter(c => c.overdue).length
		},
	},
	watch: {
		refreshKey() {
			this.load()
		},
		closing() {
			this.evidence = ''
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.items = await listConditions()
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		kindText(c) {
			if (c.kind === 'action') {
				return t('nc_nexus', 'Action')
			}
			return c.blocking ? t('nc_nexus', 'Condition · blocking') : t('nc_nexus', 'Condition')
		},
		chip(c) {
			if (c.status !== 'open') {
				return 'nx-chip--accepted'
			}
			return c.blocking ? 'nx-chip--rejected' : 'nx-chip--on-hold'
		},
		async save() {
			this.busy = true
			try {
				await closeCondition(this.closing.condition.id, this.closing.status, this.evidence)
				showSuccess(this.closing.status === 'done' ? t('nc_nexus', 'Marked as done') : t('nc_nexus', 'Condition waived'))
				this.closing = null
				await this.load()
				this.$emit('changed')
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
.cond__filters {
	display: flex;
	gap: 4px;
	margin-bottom: 12px;
}

.cond__row {
	grid-template-columns: 150px minmax(0, 1fr) 120px 150px minmax(160px, auto);
}

.cond__link {
	text-decoration: none;

	&:hover {
		text-decoration: underline;
	}
}

.cond__overdue {
	color: var(--color-error-text);
	font-weight: 600;
}

.cond__evidence {
	max-width: 260px;
}
</style>
