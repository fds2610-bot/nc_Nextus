<template>
	<NxDialog :title="t('nc_nexus', 'Assign role')"
		:subtitle="t('nc_nexus', 'Roles apply globally or to an organizational unit including all subunits.')"
		data-test="role-dialog"
		@close="$emit('close')">
		<form @submit.prevent="save">
			<div class="nx-field" data-test="field-principal">
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
						<option v-for="role in roles" :key="role" :value="role">{{ roleLabel(role) }}</option>
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
		</form>
		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !principal"
				data-test="save-role"
				@click="save">
				{{ t('nc_nexus', 'Assign') }}
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
import { assignRole, errorMessage, searchPrincipals } from '../api.js'
import { ASSIGNABLE_ROLES, indented, orgTree, roleHint, roleLabel } from '../labels.js'

export default {
	name: 'RoleDialog',
	components: { NcButton, NcSelect, NxDialog },
	props: {
		units: { type: Array, required: true },
		scopeId: { type: Number, default: null },
		initialRole: { type: String, default: 'portfolio_manager' },
	},
	emits: ['close', 'changed'],
	setup() {
		return { indented, t, roleHint, roleLabel, roles: ASSIGNABLE_ROLES }
	},
	data() {
		return {
			busy: false,
			searching: false,
			principal: null,
			principalOptions: [],
			scope: this.scopeId === null ? 'global' : String(this.scopeId),
			form: { role: this.initialRole, validFrom: '', validTo: '' },
		}
	},
	computed: {
		// ungespeicherte Eingaben → „Abbrechen“, sonst „Schließen“
		dirty() {
			return this.principal !== null || this.form.validFrom !== '' || this.form.validTo !== ''
				|| this.form.role !== 'portfolio_manager' || this.scope !== (this.scopeId === null ? 'global' : String(this.scopeId))
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
		async save() {
			if (!this.principal) {
				return
			}
			this.busy = true
			try {
				await assignRole({
					role: this.form.role,
					scopeType: this.scope === 'global' ? 'global' : 'org_unit',
					scopeId: this.scope === 'global' ? null : Number(this.scope),
					principalType: this.principal.type,
					principalId: this.principal.id,
					validFrom: this.form.validFrom || null,
					validTo: this.form.validTo || null,
				})
				showSuccess(t('nc_nexus', 'Role assigned'))
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
