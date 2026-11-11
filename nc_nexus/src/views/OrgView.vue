<template>
	<div class="org-view" data-test="org-view">
		<div class="org-view__header">
			<h2>{{ t('nc_nexus', 'Organization') }}</h2>
			<span class="org-view__count">{{ n('nc_nexus', '%n unit', '%n units', units.length) }} · {{ n('nc_nexus', '%n role', '%n roles', assignments.length) }}</span>
		</div>

		<section class="org-view__section">
			<div class="org-view__section-head">
				<h3>{{ t('nc_nexus', 'Organizational units') }}</h3>
				<NcButton variant="secondary" data-test="new-unit" @click="unitDialog = { unit: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New unit') }}
				</NcButton>
			</div>
			<p v-if="!tree.length" class="org-view__empty">
				{{ t('nc_nexus', 'No units yet. Demands without a unit are visible only to their requestor and to global roles.') }}
			</p>
			<div v-else class="org-view__table">
				<template v-for="unit in tree" :key="unit.id">
					<div class="org-row"
						:data-test="'unit-' + unit.name"
						:aria-expanded="isOpen('unit', unit.id)"
						@click="toggle('unit', unit.id)">
						<NcIconSvgWrapper class="org-row__chevron"
							:path="isOpen('unit', unit.id) ? mdiChevronDown : mdiChevronRight"
							:style="{ marginInlineStart: (unit.depth * 24) + 'px' }" />
						<NcIconSvgWrapper class="org-row__icon" :path="mdiDomain" />
						<div class="org-row__name">
							<span class="org-row__title">{{ unit.name }}</span>
							<span v-if="unit.costCenter" class="org-row__meta">{{ t('nc_nexus', 'Cost center {cc}', { cc: unit.costCenter }) }}</span>
						</div>
						<span class="org-row__meta">{{ n('nc_nexus', '%n role', '%n roles', rolesFor(unit.id).length) }}</span>
						<div class="org-row__actions" @click.stop>
							<NcActions>
								<NcActionButton close-after-click @click="unitDialog = { unit }">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencil" />
									</template>
									{{ t('nc_nexus', 'Edit') }}
								</NcActionButton>
								<NcActionButton close-after-click @click="roleDialog = { scopeId: unit.id }">
									<template #icon>
										<NcIconSvgWrapper :path="mdiAccountPlus" />
									</template>
									{{ t('nc_nexus', 'Assign role') }}
								</NcActionButton>
							</NcActions>
						</div>
					</div>
					<template v-if="isOpen('unit', unit.id)">
						<div v-for="a in rolesFor(unit.id)"
							:key="a.id"
							class="role-row"
							:style="{ paddingInlineStart: (unit.depth * 24 + 12) + 'px' }"
							:data-test="'unit-role-' + a.principalId + '-' + a.role">
							<span class="role-row__indent" />
							<NcAvatar v-if="a.principalType === 'user'"
								:user="a.principalId"
								:size="28"
								:disable-menu="true" />
							<NcIconSvgWrapper v-else :path="mdiAccountGroup" class="role-row__group" />
							<div class="org-row__name">
								<span class="org-row__title">{{ a.principalId }}</span>
								<span class="org-row__meta">{{ a.principalType === 'group' ? t('nc_nexus', 'Group') : t('nc_nexus', 'Account') }}</span>
							</div>
							<span class="org-row__meta">{{ roleLabel(a.role) }}</span>
							<span class="org-row__meta">{{ validity(a) }}</span>
							<div class="org-row__actions">
								<NcButton variant="tertiary"
									:aria-label="t('nc_nexus', 'Edit role assignment')"
									:data-test="'edit-unit-role-' + a.id"
									@click="roleDialog = { assignment: a }">
									<template #icon>
										<NcIconSvgWrapper :path="mdiPencil" />
									</template>
								</NcButton>
								<NcButton variant="tertiary"
									:aria-label="t('nc_nexus', 'Remove role')"
									@click="remove(a)">
									<template #icon>
										<NcIconSvgWrapper :path="mdiClose" />
									</template>
								</NcButton>
							</div>
						</div>
					</template>
				</template>
			</div>
		</section>

		<section class="org-view__section">
			<div class="org-view__section-head">
				<h3>{{ t('nc_nexus', 'Roles') }}</h3>
				<NcButton variant="secondary" data-test="new-role" @click="roleDialog = { scopeId: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiAccountPlus" />
					</template>
					{{ t('nc_nexus', 'Assign role') }}
				</NcButton>
			</div>
			<p class="org-view__hint">
				{{ t('nc_nexus', 'Nextcloud administrators always act as global portfolio office.') }}
			</p>
			<div class="org-view__table" data-test="role-tree">
				<template v-for="group in roleGroups" :key="group.role">
					<div class="org-row role-head"
						:data-test="'role-group-' + group.role"
						:aria-expanded="isOpen('role', group.role)"
						@click="toggle('role', group.role)">
						<NcIconSvgWrapper class="org-row__chevron" :path="isOpen('role', group.role) ? mdiChevronDown : mdiChevronRight" />
						<NcIconSvgWrapper class="org-row__icon" :path="mdiShieldAccount" />
						<div class="org-row__name">
							<span class="org-row__title">{{ roleLabel(group.role) }}</span>
							<span class="org-row__meta">{{ roleHint(group.role) }}</span>
						</div>
						<span class="org-row__meta">{{ n('nc_nexus', '%n assignment', '%n assignments', group.items.length) }}</span>
						<div class="org-row__actions" @click.stop>
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Assign role') + ': ' + roleLabel(group.role)"
								:data-test="'assign-' + group.role"
								@click="roleDialog = { scopeId: null, role: group.role }">
								<template #icon>
									<NcIconSvgWrapper :path="mdiAccountPlus" />
								</template>
							</NcButton>
						</div>
					</div>
					<div v-for="a in (isOpen('role', group.role) ? group.items : [])"
						:key="a.id"
						class="role-row"
						:data-test="'role-' + a.principalId + '-' + a.role">
						<span class="role-row__indent" />
						<NcAvatar v-if="a.principalType === 'user'"
							:user="a.principalId"
							:size="28"
							:disable-menu="true" />
						<NcIconSvgWrapper v-else :path="mdiAccountGroup" class="role-row__group" />
						<div class="org-row__name">
							<span class="org-row__title">{{ a.principalId }}</span>
							<span class="org-row__meta">{{ a.principalType === 'group' ? t('nc_nexus', 'Group') : t('nc_nexus', 'Account') }}</span>
						</div>
						<span class="org-row__meta">{{ scopeLabel(a) }}</span>
						<span class="org-row__meta">{{ validity(a) }}</span>
						<div class="org-row__actions">
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Edit role assignment')"
								:data-test="'edit-role-' + a.id"
								@click="roleDialog = { assignment: a }">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencil" />
								</template>
							</NcButton>
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Remove role')"
								:data-test="'remove-role-' + a.id"
								@click="remove(a)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</div>
					</div>
				</template>
			</div>
		</section>

		<OrgUnitDialog v-if="unitDialog"
			:unit="unitDialog.unit"
			:units="units"
			@close="unitDialog = null"
			@changed="$emit('changed')" />
		<RoleDialog v-if="roleDialog"
			:units="units"
			:scope-id="roleDialog.scopeId"
			:initial-role="roleDialog.role"
			:assignment="roleDialog.assignment ?? null"
			@close="roleDialog = null"
			@changed="$emit('changed')" />
	</div>
</template>

<script>
import { mdiAccountGroup, mdiAccountPlus, mdiChevronDown, mdiChevronRight, mdiClose, mdiDomain, mdiPencil, mdiPlus, mdiShieldAccount } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import OrgUnitDialog from '../components/OrgUnitDialog.vue'
import RoleDialog from '../components/RoleDialog.vue'
import { errorMessage, unassignRole } from '../api.js'
import { ASSIGNABLE_ROLES, formatDay, orgTree, roleHint, roleLabel } from '../labels.js'

export default {
	name: 'OrgView',
	components: { NcActionButton, NcActions, NcAvatar, NcButton, NcIconSvgWrapper, OrgUnitDialog, RoleDialog },
	props: {
		units: { type: Array, required: true },
		assignments: { type: Array, required: true },
	},
	emits: ['changed'],
	setup() {
		return { mdiAccountGroup, mdiAccountPlus, mdiChevronDown, mdiChevronRight, mdiClose, mdiDomain, mdiPencil, mdiPlus, mdiShieldAccount, n, t, roleHint, roleLabel }
	},
	data() {
		return { unitDialog: null, roleDialog: null, open: new Set() }
	},
	computed: {
		tree() {
			return orgTree(this.units)
		},
		/** Alle vergebbaren Rollen (auch ohne Inhaber), danach weitere vorhandene Rollen; darunter die Zuweisungen. */
		roleGroups() {
			const roles = [...ASSIGNABLE_ROLES]
			for (const a of this.assignments) {
				if (!roles.includes(a.role)) {
					roles.push(a.role)
				}
			}
			return roles.map(role => ({
				role,
				items: this.assignments
					.filter(a => a.role === role)
					.sort((x, y) => this.scopeLabel(x).localeCompare(this.scopeLabel(y)) || x.principalId.localeCompare(y.principalId)),
			}))
		},
	},
	methods: {
		isOpen(kind, id) {
			return this.open.has(kind + ':' + id)
		},
		toggle(kind, id) {
			const key = kind + ':' + id
			const open = new Set(this.open)
			if (!open.delete(key)) {
				open.add(key)
			}
			this.open = open
		},
		rolesFor(unitId) {
			return this.assignments.filter(a => a.scopeType === 'org_unit' && a.scopeId === unitId)
		},
		scopeLabel(a) {
			return a.scopeType === 'global' ? t('nc_nexus', 'Global') : (this.tree.find(u => u.id === a.scopeId)?.path ?? '?')
		},
		validity(a) {
			const day = (s) => formatDay(new Date(s + 'T12:00:00').getTime() / 1000)
			if (a.validFrom && a.validTo) {
				return t('nc_nexus', '{from} – {to}', { from: day(a.validFrom), to: day(a.validTo) })
			}
			if (a.validFrom) {
				return t('nc_nexus', 'from {date}', { date: day(a.validFrom) })
			}
			if (a.validTo) {
				return t('nc_nexus', 'until {date}', { date: day(a.validTo) })
			}
			return t('nc_nexus', 'unlimited')
		},
		async remove(a) {
			try {
				await unassignRole(a.id)
				showSuccess(t('nc_nexus', 'Role removed'))
				this.$emit('changed')
			} catch (e) {
				showError(errorMessage(e))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.org-view {
	padding: 8px 20px 20px 50px;

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: var(--default-clickable-area, 34px);
		margin: 4px 0 16px;

		h2 {
			margin: 0;
			font-size: 20px;
			font-weight: 600;
		}
	}

	&__count,
	&__hint,
	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		margin: -6px 0 10px;
		font-size: 13px;
	}

	&__section {
		margin-bottom: 28px;
	}

	&__section-head {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 10px;

		h3 {
			margin: 0;
			font-size: 12px;
			font-weight: 700;
			color: var(--color-text-maxcontrast);
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}
}

.org-row,
.role-row {
	display: grid;
	align-items: center;
	gap: 12px;
	min-height: 52px;
	padding: 0 4px 0 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}
}

.org-row {
	grid-template-columns: auto auto minmax(0, 1fr) 100px 44px;
	cursor: pointer;

	&:hover {
		background: var(--color-background-hover);
	}

	&__name {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__title {
		overflow: hidden;
		font-weight: 600;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	&__meta {
		overflow: hidden;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
	}

	&__chevron {
		color: var(--color-text-maxcontrast);
	}
}

.role-head {
	grid-template-columns: auto auto minmax(0, 1fr) 120px 44px;

	&:hover {
		background: none;
	}
}

.role-row {
	grid-template-columns: 24px 28px minmax(0, 1fr) minmax(0, 1fr) 150px 88px;
	min-height: 44px;

	&__group {
		color: var(--color-text-maxcontrast);
	}
}
</style>
