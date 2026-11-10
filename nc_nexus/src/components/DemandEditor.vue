<template>
	<!-- label-id statt :name: NcModals :name rendert einen sichtbaren <h2>, der auf NC 34 die
	     zentrierte Suchleiste überlagert (VertragsWerk #337). Der Titel steht deshalb nur für
	     Screenreader im Body; sichtbar ist der Zusammenfassungskopf. -->
	<NcModal size="large"
		label-id="nx-demand-editor-title"
		:close-on-click-outside="false"
		@close="$emit('close')">
		<!-- Kopf- und Fußzeile stehen fest, nur der Inhalt dazwischen scrollt. -->
		<div class="editor"
			:class="{ 'editor--readonly': readonly }"
			data-test="demand-editor"
			@keydown.esc="$emit('close')">
			<h2 id="nx-demand-editor-title" class="nx-hidden-visually">
				{{ heading }}
			</h2>
			<header class="editor__head nx-summary">
				<div class="nx-summary__top">
					<div class="nx-summary__id">
						<div class="nx-summary__title">
							{{ heading }}
						</div>
						<div class="nx-summary__sub">
							<template v-if="demand">
								<span data-test="demand-key">{{ demand.key }}</span> · {{ t('nc_nexus', 'by {user}', { user: demand.requestorId }) }}
							</template>
							<template v-else>
								{{ t('nc_nexus', 'Saved as a draft first; only submitting makes it binding.') }}
							</template>
						</div>
					</div>
					<span v-if="demand"
						class="nx-chip"
						:class="'nx-chip--' + demand.status"
						data-test="demand-status">{{ statusLabel(demand.status) }}</span>
				</div>
				<div v-if="demand" class="nx-summary__facts">
					<div class="nx-fact">
						{{ t('nc_nexus', 'Type') }}
						<b>{{ typeLabel(demand.demandType) }}</b>
					</div>
					<div v-if="orgUnitPath" class="nx-fact">
						{{ t('nc_nexus', 'Organizational unit') }}
						<b>{{ orgUnitPath }}</b>
					</div>
					<div class="nx-fact">
						{{ t('nc_nexus', 'Created') }}
						<b>{{ formatDay(demand.createdAt) }}</b>
					</div>
					<div v-if="demand.submittedAt" class="nx-fact">
						{{ t('nc_nexus', 'Submitted') }}
						<b>{{ formatDay(demand.submittedAt) }}</b>
					</div>
					<div class="nx-fact">
						{{ t('nc_nexus', 'Channel') }}
						<b>{{ channelLabel(demand.intakeChannel) }}</b>
					</div>
				</div>
			</header>

			<div class="editor__body">
				<div v-if="loading" class="editor__loading">
					<NcLoadingIcon :size="32" />
				</div>
				<form v-else @submit.prevent="save">
					<div class="nx-section">
						<h3>{{ t('nc_nexus', 'Basic data') }}</h3>
						<div class="nx-field-row">
							<label class="nx-field" data-test="field-title">
								<span>{{ t('nc_nexus', 'Title') }} *</span>
								<input v-model="form.title"
									class="nx-input"
									maxlength="255"
									:disabled="readonly"
									:placeholder="readonly ? '' : t('nc_nexus', 'e.g. Replace the ticket system')">
							</label>
							<label class="nx-field" data-test="field-type">
								<span>{{ t('nc_nexus', 'Type') }} *</span>
								<select v-model="form.demandType" class="nx-input" :disabled="readonly">
									<option v-for="type in types" :key="type" :value="type">
										{{ typeLabel(type) }}
									</option>
								</select>
							</label>
						</div>
						<label class="nx-field" data-test="field-org-unit">
							<span>{{ t('nc_nexus', 'Organizational unit') }}</span>
							<select v-model="form.orgUnitId" class="nx-input" :disabled="readonly">
								<option :value="null">{{ t('nc_nexus', '– none –') }}</option>
								<option v-for="unit in orgTreeList" :key="unit.id" :value="unit.id">
									{{ indented(unit) }}
								</option>
							</select>
							<p v-if="!readonly" class="nx-hint">{{ t('nc_nexus', 'Determines who besides you can see the demand (sponsor, PMO, board of the area).') }}</p>
						</label>
					</div>

					<div class="nx-section">
						<h3>{{ t('nc_nexus', 'Justification') }}</h3>
						<label class="nx-field" data-test="field-problem">
							<span>{{ t('nc_nexus', 'Problem statement') }} *</span>
							<textarea v-model="form.problemStatement"
								class="nx-input"
								:disabled="readonly"
								:placeholder="readonly ? '' : t('nc_nexus', 'What happens if we do nothing?')" />
							<p v-if="!readonly" class="nx-hint">{{ t('nc_nexus', 'Required for submitting.') }}</p>
						</label>
						<label class="nx-field" data-test="field-benefit">
							<span>{{ t('nc_nexus', 'Expected benefit') }}</span>
							<textarea v-model="form.expectedBenefit" class="nx-input" :disabled="readonly" />
						</label>
					</div>

					<div class="nx-section">
						<h3>{{ t('nc_nexus', 'Description') }}</h3>
						<label class="nx-field" data-test="field-description">
							<span class="nx-hidden-visually">{{ t('nc_nexus', 'Description') }}</span>
							<textarea v-model="form.description"
								class="nx-input"
								:disabled="readonly"
								:placeholder="readonly ? '' : t('nc_nexus', 'Scope, affected areas, desired time frame …')" />
						</label>
					</div>

					<div v-if="history.length" class="nx-section" data-test="demand-history">
						<h3>{{ t('nc_nexus', 'History') }}</h3>
						<ul class="editor__history">
							<li v-for="entry in history" :key="entry.id">
								<span class="editor__history-when">{{ formatDate(entry.createdAt) }}</span>
								<span>
									<strong>{{ actionLabel(entry.action) }}</strong>
									· {{ entry.userId }}
									<span v-if="entry.action !== 'create' && entry.fieldChanges" class="editor__changes">
										· {{ Object.keys(entry.fieldChanges).map(fieldLabel).join(', ') }}
									</span>
								</span>
							</li>
						</ul>
					</div>
				</form>
			</div>

			<footer class="editor__actions">
				<NcButton v-if="canDelete"
					variant="error"
					class="editor__delete"
					:disabled="busy"
					data-test="delete-demand"
					@click="remove">
					{{ t('nc_nexus', 'Delete draft') }}
				</NcButton>
				<!-- „Abbrechen“ nur, wenn ungespeicherte Eingaben verworfen würden; sonst „Schließen“ -->
				<NcButton variant="tertiary"
					:disabled="busy"
					data-test="close-demand"
					@click="$emit('close')">
					{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
				</NcButton>
				<template v-if="!readonly">
					<NcButton variant="secondary"
						:disabled="busy || !valid"
						data-test="save-demand"
						@click="save">
						{{ t('nc_nexus', 'Save draft') }}
					</NcButton>
					<NcButton variant="primary"
						:disabled="busy || !valid || !form.problemStatement.trim()"
						data-test="submit-demand"
						@click="submit">
						{{ t('nc_nexus', 'Submit') }}
					</NcButton>
				</template>
			</footer>
		</div>
	</NcModal>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import { createDemand, deleteDemand, demandHistory, errorMessage, getDemand, submitDemand, updateDemand } from '../api.js'
import { actionLabel, channelLabel, fieldLabel, formatDate, formatDay, indented, orgTree, statusLabel, typeLabel } from '../labels.js'

const emptyForm = () => ({ title: '', demandType: 'strategic', orgUnitId: null, problemStatement: '', description: '', expectedBenefit: '' })

export default {
	name: 'DemandEditor',
	components: { NcButton, NcLoadingIcon, NcModal },
	props: {
		demandId: { type: Number, default: null },
		orgUnits: { type: Array, default: () => [] },
	},
	emits: ['close', 'changed'],
	setup() {
		return { indented, t, actionLabel, channelLabel, fieldLabel, formatDate, formatDay, statusLabel, typeLabel }
	},
	data() {
		return {
			id: this.demandId,
			demand: null,
			form: emptyForm(),
			saved: JSON.stringify(emptyForm()),
			history: [],
			loading: this.demandId !== null,
			busy: false,
			types: loadState('nc_nexus', 'demand_types', []),
		}
	},
	computed: {
		orgTreeList() {
			return orgTree(this.orgUnits)
		},
		orgUnitPath() {
			return this.orgTreeList.find(u => u.id === this.demand?.orgUnitId)?.path ?? ''
		},
		heading() {
			return this.demand ? this.demand.title : t('nc_nexus', 'New demand')
		},
		own() {
			return !this.demand || this.demand.requestorId === getCurrentUser()?.uid
		},
		readonly() {
			return !!this.demand && (this.demand.status !== 'draft' || !this.own)
		},
		canDelete() {
			return !!this.demand && !this.readonly
		},
		dirty() {
			return !this.readonly && JSON.stringify(this.form) !== this.saved
		},
		valid() {
			return this.form.title.trim() !== '' && !!this.form.demandType
		},
	},
	async mounted() {
		if (this.id !== null) {
			await this.load()
		}
	},
	methods: {
		async load() {
			try {
				this.setDemand(await getDemand(this.id))
				this.history = await demandHistory(this.id)
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		setDemand(demand) {
			this.demand = demand
			this.id = demand.id
			this.form = {
				title: demand.title,
				demandType: demand.demandType,
				orgUnitId: demand.orgUnitId ?? null,
				problemStatement: demand.problemStatement ?? '',
				description: demand.description ?? '',
				expectedBenefit: demand.expectedBenefit ?? '',
			}
			this.saved = JSON.stringify(this.form)
		},
		async persist() {
			const demand = this.id === null ? await createDemand(this.form) : await updateDemand(this.id, this.form)
			this.setDemand(demand)
			this.$emit('changed')
		},
		async run(action, message) {
			this.busy = true
			try {
				await action()
				showSuccess(message)
				return true
			} catch (e) {
				showError(errorMessage(e))
				return false
			} finally {
				this.busy = false
			}
		},
		async save() {
			if (!this.valid || this.readonly) {
				return
			}
			if (await this.run(() => this.persist(), t('nc_nexus', 'Draft saved'))) {
				this.history = await demandHistory(this.id)
			}
		},
		async submit() {
			const ok = await this.run(async () => {
				await this.persist()
				this.setDemand(await submitDemand(this.id))
				this.$emit('changed')
			}, t('nc_nexus', 'Demand submitted'))
			if (ok) {
				this.history = await demandHistory(this.id)
			}
		},
		async remove() {
			const ok = await this.run(() => deleteDemand(this.id), t('nc_nexus', 'Draft deleted'))
			if (ok) {
				this.$emit('changed')
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.editor {
	display: flex;
	flex-direction: column;
	// Gleiche Höhe wie der NcModal-Container, der sonst als Ganzes scrollen würde.
	max-height: min(90vh, 100vh - 2 * var(--header-height) - 2 * var(--body-container-margin));

	// Rechts Platz für den Schließen-Button von NcModal
	&__head {
		flex: none;
		padding: 18px 56px 16px 22px;
		border-bottom: 1px solid var(--color-border);
	}

	&__body {
		flex: 1 1 auto;
		min-height: 0;
		overflow-y: auto;
		padding: 0 22px;
	}

	&__loading {
		display: flex;
		justify-content: center;
		padding: 40px 0;
	}

	&__history {
		margin: 0;
		padding: 0;
		list-style: none;

		li {
			display: grid;
			grid-template-columns: 150px minmax(0, 1fr);
			gap: 12px;
			padding: 4px 0;
			font-size: 13px;
		}
	}

	&__history-when {
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__changes {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		flex: none;
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding: 12px 22px;
		border-top: 1px solid var(--color-border);
	}

	&__delete {
		margin-inline-end: auto;
	}
}

@media (max-width: 600px) {
	.editor .nx-field-row {
		grid-template-columns: 1fr;
	}
}
</style>
