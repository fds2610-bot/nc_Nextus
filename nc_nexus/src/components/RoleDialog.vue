<template>
	<NxDialog :title="assignment ? t('nc_nexus', 'Edit role assignment') : t('nc_nexus', 'Assign role')"
		:subtitle="t('nc_nexus', 'Roles apply globally or to an organizational unit including all subunits.')"
		data-test="role-dialog"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<div v-if="assignment" class="nx-field" data-test="field-principal-fixed">
				<span>{{ t('nc_nexus', 'Account or group') }}</span>
				<strong>{{ assignment.principalId }}</strong>
			</div>
			<div v-else class="nx-field" data-test="field-principal">
				<span>{{ t('nc_nexus', 'Account or group') }} *</span>
				<NcSelect v-model="principal"
					:options="principalOptions"
					:loading="searching"
					:filterable="false"
					:placeholder="t('nc_nexus', 'Search accounts and groups …')"
					:aria-label-combobox="t('nc_nexus', 'Account or group')"
					label="display"
					@search="search" />
			</div>
			<div class="nx-field-row">
				<label class="nx-field" data-test="field-role">
					<span>{{ t('nc_nexus', 'Role') }} *</span>
					<select v-model="form.role" class="nx-input">
						<option v-for="role in roleOptions" :key="role" :value="role">{{ roleLabel(role) }}</option>
					</select>
				</label>
				<label class="nx-field" data-test="field-scope">
					<span>{{ t('nc_nexus', 'Scope') }} *</span>
					<select v-model="scope" class="nx-input">
						<option value="global">{{ t('nc_nexus', 'Global (all areas)') }}</option>
						<option v-for="unit in tree" :key="unit.id" :value="String(unit.id)">
							{{ indented(unit) }}
						</option>
					</select>
				</label>
			</div>
			<p class="nx-hint nx-field">
				{{ roleHint(form.role) }}
			</p>
			<div class="nx-field-row">
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Valid from') }}</span>
					<input v-model="form.validFrom" type="date" class="nx-input">
				</label>
				<label class="nx-field">
					<span>{{ t('nc_nexus', 'Valid until') }}</span>
					<input v-model="form.validTo" type="date" class="nx-input">
				</label>
			</div>
			<p class="nx-hint">
				{{ t('nc_nexus', 'Leave empty for an unlimited assignment; use a period for deputies.') }}
			</p>
			<div class="nx-field" data-test="field-deputy">
				<span>{{ t('nc_nexus', 'Deputy for (optional)') }}</span>
				<NcSelect v-model="deputy"
					:options="deputyOptions"
					:loading="searching"
					:filterable="false"
					:placeholder="t('nc_nexus', 'Search accounts …')"
					:aria-label-combobox="t('nc_nexus', 'Deputy for (optional)')"
					label="display"
					@search="searchDeputy" />
			</div>
		</form>
		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || (!assignment && !principal) || (assignment && !dirty)"
				data-test="save-role"
				@click="save">
				{{ assignment ? t('nc_nexus', 'Save') : t('nc_nexus', 'Assign') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NxDialog from './NxDialog.vue'
import { assignRole, errorMessage, searchPrincipals, updateRoleAssignment } from '../api.js'
import { ASSIGNABLE_ROLES, indented, orgTree, roleHint, roleLabel } from '../labels.js'

export default {
	name: 'RoleDialog',
	components: { NcButton, NcSelect, NxDialog },
	props: {
		units: { type: Array, required: true },
		scopeId: { type: Number, default: null },
		initialRole: { type: String, default: 'portfolio_manager' },
		/** vorhandene Zuweisung bearbeiten (sonst neu anlegen) */
		assignment: { type: Object, default: null },
	},
	emits: ['close', 'changed'],
	setup() {
		return { indented, t, roleHint, roleLabel }
	},
	data() {
		return {
			busy: false,
			searching: false,
			principal: null,
			principalOptions: [],
			deputyOptions: [],
			deputy: this.assignment?.deputyFor ? { id: this.assignment.deputyFor, display: this.assignment.deputyFor } : null,
			scope: this.assignment
				? (this.assignment.scopeType === 'global' ? 'global' : String(this.assignment.scopeId))
				: (this.scopeId === null ? 'global' : String(this.scopeId)),
			form: {
				role: this.assignment?.role ?? this.initialRole,
				validFrom: this.assignment?.validFrom ?? '',
				validTo: this.assignment?.validTo ?? '',
			},
		}
	},
	computed: {
		// ungespeicherte Eingaben → „Abbrechen“, sonst „Schließen“
		dirty() {
			if (this.assignment) {
				const a = this.assignment
				return this.form.role !== a.role
					|| this.scope !== (a.scopeType === 'global' ? 'global' : String(a.scopeId))
					|| this.form.validFrom !== (a.validFrom ?? '')
					|| this.form.validTo !== (a.validTo ?? '')
					|| (this.deputy?.id ?? '') !== (a.deputyFor ?? '')
			}
			return this.principal !== null || this.form.validFrom !== '' || this.form.validTo !== '' || this.deputy !== null
				|| this.form.role !== this.initialRole || this.scope !== (this.scopeId === null ? 'global' : String(this.scopeId))
		},
		roleOptions() {
			return ASSIGNABLE_ROLES.includes(this.form.role) ? ASSIGNABLE_ROLES : [...ASSIGNABLE_ROLES, this.form.role]
		},
		tree() {
			return orgTree(this.units)
		},
	},
	methods: {
		async search(query) {
			if (query.trim().length < 1) {
				return
			}
			this.searching = true
			try {
				const found = await searchPrincipals(query.trim())
				this.principalOptions = found.map(p => ({
					...p,
					display: p.type === 'group' ? t('nc_nexus', '{name} (group)', { name: p.label }) : p.label,
				}))
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.searching = false
			}
		},
		async searchDeputy(query) {
			if (query.trim().length < 1) {
				return
			}
			this.searching = true
			try {
				const found = await searchPrincipals(query.trim())
				this.deputyOptions = found.filter(p => p.type !== 'group').map(p => ({ ...p, display: p.label }))
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.searching = false
			}
		},
		async save() {
			if (!this.assignment && !this.principal) {
				return
			}
			this.busy = true
			try {
				const values = {
					role: this.form.role,
					scopeType: this.scope === 'global' ? 'global' : 'org_unit',
					scopeId: this.scope === 'global' ? null : Number(this.scope),
					validFrom: this.form.validFrom || null,
					validTo: this.form.validTo || null,
					deputyFor: this.deputy?.id ?? null,
				}
				if (this.assignment) {
					await updateRoleAssignment(this.assignment.id, values)
				} else {
					await assignRole({ ...values, principalType: this.principal.type, principalId: this.principal.id })
				}
				showSuccess(this.assignment ? t('nc_nexus', 'Role assignment saved') : t('nc_nexus', 'Role assigned'))
				this.$emit('changed')
				this.$emit('close')
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
