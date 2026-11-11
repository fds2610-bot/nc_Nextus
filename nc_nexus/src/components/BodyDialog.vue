<template>
	<NxDialog :title="current ? current.name : t('nc_nexus', 'New body')"
		:subtitle="t('nc_nexus', 'Bodies decide at the gates assigned to them in the decision rights. Members hold a seat (e.g. Finance, IT); a deputy may vote on their behalf.')"
		data-test="body-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<div class="nx-field-row">
			<label class="nx-field body__code" data-test="field-body-code">
				<span>{{ t('nc_nexus', 'Code') }} *</span>
				<input v-model="form.code" class="nx-input" maxlength="16">
			</label>
			<label class="nx-field" data-test="field-body-name">
				<span>{{ t('nc_nexus', 'Name') }} *</span>
				<input v-model="form.name" class="nx-input">
			</label>
		</div>
		<div class="nx-field-row">
			<label class="nx-field" data-test="field-body-type">
				<span>{{ t('nc_nexus', 'Type') }}</span>
				<select v-model="form.bodyType" class="nx-input">
					<option v-for="type in types" :key="type" :value="type">{{ bodyTypeLabel(type) }}</option>
				</select>
			</label>
			<div class="nx-field" data-test="field-body-chair">
				<span>{{ t('nc_nexus', 'Chair') }}</span>
				<UserSelect v-model="form.chairId" :label="t('nc_nexus', 'Chair')" />
			</div>
		</div>
		<div class="nx-field-row">
			<label class="nx-field" data-test="field-body-quorum">
				<span>{{ t('nc_nexus', 'Quorum (minimum votes)') }} *</span>
				<NxNumberInput v-model="form.quorumMin"
					:min="1"
					:max="99"
					:max-decimals="0"
					class="nx-input" />
			</label>
			<label class="nx-field" data-test="field-body-seats">
				<span>{{ t('nc_nexus', 'Required seats (comma-separated)') }}</span>
				<input v-model="seats" class="nx-input" :placeholder="t('nc_nexus', 'e.g. Finance, IT')">
			</label>
		</div>
		<label class="nx-field">
			<span>{{ t('nc_nexus', 'Description') }}</span>
			<textarea v-model="form.description" class="nx-input" />
		</label>
		<label class="body__active">
			<input v-model="form.active" type="checkbox">
			<span>{{ t('nc_nexus', 'active') }}</span>
		</label>

		<div v-if="current" class="nx-section" data-test="body-members">
			<h3>{{ t('nc_nexus', 'Members') }}</h3>
			<table v-if="current.members.length" class="body__members">
				<thead>
					<tr>
						<th>{{ t('nc_nexus', 'Account') }}</th>
						<th>{{ t('nc_nexus', 'Seat') }}</th>
						<th>{{ t('nc_nexus', 'Voting') }}</th>
						<th>{{ t('nc_nexus', 'Deputy') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="m in current.members" :key="m.id" :data-test="'member-' + m.userId">
						<td>{{ m.userId }}</td>
						<td>{{ m.seat || '–' }}</td>
						<td>{{ m.voting ? t('nc_nexus', 'Yes') : t('nc_nexus', 'No') }}</td>
						<td>{{ m.deputyId || '–' }}</td>
						<td>
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Remove member')"
								:data-test="'remove-member-' + m.userId"
								@click="remove(m)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="nx-hint">
				{{ t('nc_nexus', 'No members yet.') }}
			</p>
			<div class="body__add">
				<div class="nx-field" data-test="member-user">
					<span>{{ t('nc_nexus', 'Account') }}</span>
					<UserSelect v-model="member.userId" :label="t('nc_nexus', 'Account')" />
				</div>
				<label class="nx-field" data-test="member-seat">
					<span>{{ t('nc_nexus', 'Seat') }}</span>
					<input v-model="member.seat" class="nx-input" :placeholder="t('nc_nexus', 'e.g. Finance')">
				</label>
				<div class="nx-field" data-test="member-deputy">
					<span>{{ t('nc_nexus', 'Deputy') }}</span>
					<UserSelect v-model="member.deputyId" :label="t('nc_nexus', 'Deputy')" />
				</div>
				<label class="body__voting">
					<input v-model="member.voting" type="checkbox">
					<span>{{ t('nc_nexus', 'Voting') }}</span>
				</label>
				<NcButton variant="secondary"
					:disabled="busy || !member.userId"
					data-test="add-member"
					@click="add">
					{{ t('nc_nexus', 'Add') }}
				</NcButton>
			</div>
		</div>
		<template #actions>
			<NcButton v-if="current"
				variant="error"
				:disabled="busy"
				data-test="delete-body"
				@click="destroy">
				{{ t('nc_nexus', 'Delete') }}
			</NcButton>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !dirty || !form.code.trim() || !form.name.trim()"
				data-test="save-body"
				@click="save">
				{{ t('nc_nexus', 'Save') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { mdiClose } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NxDialog from './NxDialog.vue'
import NxNumberInput from './NxNumberInput.vue'
import UserSelect from './UserSelect.vue'
import { addMember, createBody, deleteBody, errorMessage, removeMember, updateBody } from '../api.js'
import { bodyTypeLabel } from '../labels.js'

const fromBody = (b) => ({
	code: b?.code ?? '',
	name: b?.name ?? '',
	bodyType: b?.bodyType ?? 'portfolio_board',
	chairId: b?.chairId ?? '',
	quorumMin: b?.quorumMin ?? 1,
	description: b?.description ?? '',
	active: b ? b.active === 1 : true,
})

export default {
	name: 'BodyDialog',
	components: { NcButton, NcIconSvgWrapper, NxDialog, NxNumberInput, UserSelect },
	props: {
		body: { type: Object, default: null },
	},
	emits: ['close', 'changed', 'ready'],
	setup() {
		return { mdiClose, t, bodyTypeLabel, types: ['portfolio_board', 'resource_board', 'architecture_board', 'steering_committee', 'executive', 'pmo'] }
	},
	data() {
		return {
			busy: false,
			current: this.body,
			form: fromBody(this.body),
			seats: (this.body?.requiredSeats ?? []).join(', '),
			saved: JSON.stringify([fromBody(this.body), (this.body?.requiredSeats ?? []).join(', ')]),
			member: { userId: '', seat: '', deputyId: '', voting: true },
		}
	},
	computed: {
		dirty() {
			return JSON.stringify([this.form, this.seats]) !== this.saved
		},
	},
	methods: {
		async run(action, message) {
			this.busy = true
			try {
				const result = await action()
				if (message) {
					showSuccess(message)
				}
				this.$emit('changed')
				return result
			} catch (e) {
				showError(errorMessage(e))
				return null
			} finally {
				this.busy = false
			}
		},
		async save() {
			const values = { ...this.form, requiredSeats: this.seats.split(',').map(s => s.trim()).filter(Boolean) }
			const body = await this.run(() => this.current ? updateBody(this.current.id, values) : createBody(values), t('nc_nexus', 'Body saved'))
			if (body) {
				this.current = body
				this.form = fromBody(body)
				this.seats = body.requiredSeats.join(', ')
				this.saved = JSON.stringify([this.form, this.seats])
			}
		},
		async add() {
			const body = await this.run(() => addMember(this.current.id, this.member), null)
			if (body) {
				this.current = body
				this.member = { userId: '', seat: '', deputyId: '', voting: true }
			}
		},
		async remove(m) {
			const body = await this.run(() => removeMember(this.current.id, m.id), null)
			if (body) {
				this.current = body
			}
		},
		async destroy() {
			if (await this.run(() => deleteBody(this.current.id).then(() => true), t('nc_nexus', 'Body deleted'))) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.body__code {
	max-width: 140px;
}

.body__active,
.body__voting {
	display: flex;
	gap: 6px;
	align-items: center;
	white-space: nowrap;
}

.body__members {
	width: 100%;
	margin-bottom: 10px;
	border-collapse: collapse;

	th, td {
		padding: 4px 6px 4px 0;
		text-align: start;
	}

	thead th {
		font-size: 12px;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}
}

.body__add {
	display: grid;
	// zwei Reihen: Konto und Sitz, darunter Stellvertretung, Stimmrecht und Hinzufügen
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
	gap: 6px 10px;
	align-items: end;

	.nx-field {
		min-width: 0;
		margin-bottom: 0;
	}
}

.body__add > :first-child {
	grid-column: 1 / 2;
}

.body__add > :nth-child(2) {
	grid-column: 2 / 4;
}
</style>
