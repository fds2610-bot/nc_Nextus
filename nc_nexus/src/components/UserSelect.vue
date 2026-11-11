<template>
	<NcSelect :model-value="selected"
		:options="options"
		:loading="searching"
		:filterable="false"
		:placeholder="placeholder || t('nc_nexus', 'Search accounts …')"
		:aria-label-combobox="label"
		:disabled="disabled"
		label="display"
		@search="search"
		@update:model-value="$emit('update:modelValue', $event?.id ?? '')" />
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { errorMessage, searchPrincipals } from '../api.js'

/** Auswahl eines Kontos (ohne Gruppen) mit Suche; v-model ist die Konto-ID. */
export default {
	name: 'UserSelect',
	components: { NcSelect },
	props: {
		modelValue: { type: String, default: '' },
		label: { type: String, required: true },
		placeholder: { type: String, default: '' },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	setup() {
		return { t }
	},
	data() {
		return { options: [], searching: false }
	},
	computed: {
		selected() {
			if (!this.modelValue) {
				return null
			}
			return this.options.find(o => o.id === this.modelValue) ?? { id: this.modelValue, display: this.modelValue }
		},
	},
	methods: {
		async search(query) {
			if (query.trim().length < 1) {
				return
			}
			this.searching = true
			try {
				const q = query.trim()
				const found = await searchPrincipals(q)
				const options = found.filter(p => p.type === 'user').map(p => ({ id: p.id, display: p.label === p.id ? p.id : `${p.label} (${p.id})` }))
				// Die Suche von Nextcloud blendet das eigene Konto aus – hier gehört es dazu (z. B. als Gremiumsmitglied)
				const me = getCurrentUser()
				if (me && !options.some(o => o.id === me.uid) && [me.uid, me.displayName].some(v => (v || '').toLowerCase().includes(q.toLowerCase()))) {
					options.unshift({ id: me.uid, display: me.displayName && me.displayName !== me.uid ? `${me.displayName} (${me.uid})` : me.uid })
				}
				this.options = options
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.searching = false
			}
		},
	},
}
</script>
