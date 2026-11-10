<template>
	<NcModal size="large"
		:name="heading"
		:close-on-click-outside="false"
		@close="$emit('close')">
		<!-- Kopf- und Fußzeile stehen fest, nur der Inhalt dazwischen scrollt. -->
		<div class="editor" data-test="demand-editor">
			<header class="editor__head">
				<div class="editor__title">
					<h2>{{ heading }}</h2>
					<p v-if="demand">
						{{ demand.key }} · {{ t('nc_nexus', 'by {user}', { user: demand.requestorId }) }}
					</p>
				</div>
				<span v-if="demand" class="editor__status" data-test="demand-status">{{ statusLabel(demand.status) }}</span>
			</header>

			<div class="editor__body">
				<NcLoadingIcon v-if="loading" :size="32" />
				<form v-else class="editor__form" @submit.prevent="save">
					<div data-test="field-title">
						<NcTextField v-model="form.title"
							:label="t('nc_nexus', 'Title') + ' *'"
							:disabled="readonly"
							:maxlength="255" />
					</div>
					<div data-test="field-type">
						<NcSelect v-model="form.demandType"
							:options="typeOptions"
							:input-label="t('nc_nexus', 'Type') + ' *'"
							:disabled="readonly"
							:clearable="false"
							:reduce="option => option.id"
							label="label" />
					</div>
					<div data-test="field-problem">
						<NcTextArea v-model="form.problemStatement"
							:label="t('nc_nexus', 'Problem statement') + ' *'"
							:helper-text="t('nc_nexus', 'What happens if we do nothing? Required for submitting.')"
							:disabled="readonly"
							resize="vertical" />
					</div>
					<div data-test="field-description">
						<NcTextArea v-model="form.description"
							:label="t('nc_nexus', 'Description')"
							:disabled="readonly"
							resize="vertical" />
					</div>
					<div data-test="field-benefit">
						<NcTextArea v-model="form.expectedBenefit"
							:label="t('nc_nexus', 'Expected benefit')"
							:disabled="readonly"
							resize="vertical" />
					</div>
				</form>

				<section v-if="history.length" class="editor__history" data-test="demand-history">
					<h3>{{ t('nc_nexus', 'History') }}</h3>
					<ul>
						<li v-for="entry in history" :key="entry.id">
							<strong>{{ actionLabel(entry.action) }}</strong>
							{{ formatDate(entry.createdAt) }} · {{ entry.userId }}
							<span v-if="entry.action !== 'create' && entry.fieldChanges" class="editor__changes">
								{{ Object.keys(entry.fieldChanges).map(fieldLabel).join(', ') }}
							</span>
						</li>
					</ul>
				</section>
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
				<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
					{{ readonly ? t('nc_nexus', 'Close') : t('nc_nexus', 'Cancel') }}
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createDemand, deleteDemand, demandHistory, errorMessage, getDemand, submitDemand, updateDemand } from '../api.js'
import { actionLabel, fieldLabel, formatDate, statusLabel, typeLabel } from '../labels.js'

const emptyForm = () => ({ title: '', demandType: 'strategic', problemStatement: '', description: '', expectedBenefit: '' })

export default {
	name: 'DemandEditor',
	components: { NcButton, NcLoadingIcon, NcModal, NcSelect, NcTextArea, NcTextField },
	props: {
		demandId: { type: Number, default: null },
	},
	emits: ['close', 'changed'],
	setup() {
		return { t, actionLabel, fieldLabel, formatDate, statusLabel }
	},
	data() {
		return {
			id: this.demandId,
			demand: null,
			form: emptyForm(),
			history: [],
			loading: this.demandId !== null,
			busy: false,
			typeOptions: loadState('nc_nexus', 'demand_types', []).map(id => ({ id, label: typeLabel(id) })),
		}
	},
	computed: {
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
				problemStatement: demand.problemStatement ?? '',
				description: demand.description ?? '',
				expectedBenefit: demand.expectedBenefit ?? '',
			}
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
		display: flex;
		align-items: center;
		gap: 12px;
		padding: 14px 56px 14px 20px;
		border-bottom: 1px solid var(--color-border);
	}

	&__title {
		flex: 1;
		min-width: 0;

		h2 {
			margin: 0;
			font-size: 1.25em;
			overflow-wrap: anywhere;
		}

		p {
			margin: 0;
			color: var(--color-text-maxcontrast);
		}
	}

	&__status {
		flex: none;
		padding: 2px 10px;
		border-radius: var(--border-radius-pill);
		background: var(--color-primary-element-light);
		color: var(--color-primary-element-light-text);
		font-size: var(--font-size-small, 13px);
	}

	&__body {
		flex: 1 1 auto;
		min-height: 0;
		overflow-y: auto;
		padding: 16px 20px;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__history {
		margin-top: 24px;

		h3 {
			margin: 0 0 8px;
			font-size: 1em;
		}

		li {
			padding: 4px 0;
		}
	}

	&__changes {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		flex: none;
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding: 12px 20px;
		border-top: 1px solid var(--color-border);
	}

	&__delete {
		margin-inline-end: auto;
	}
}
</style>
