<template>
	<NxDialog :title="title"
		:subtitle="subtitle"
		:size="size"
		:data-test="dataTest"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<slot name="before" :values="values" />
		<div class="form__grid">
			<template v-for="f in visibleFields" :key="f.key">
				<div v-if="f.type === 'heading'" class="form__heading">
					<h3>{{ f.label }}</h3>
					<p v-if="f.hint" class="nx-hint">
						{{ f.hint }}
					</p>
				</div>
				<label v-else-if="f.type === 'checkbox'"
					class="form__check"
					:class="{ 'form__wide': f.wide }"
					:data-test="'field-' + f.key">
					<input v-model="values[f.key]" type="checkbox">
					<span>{{ f.label }}</span>
				</label>
				<div v-else-if="f.type === 'user'"
					class="nx-field"
					:class="{ 'form__wide': f.wide }"
					:data-test="'field-' + f.key">
					<span>{{ f.label }}{{ f.required ? ' *' : '' }}</span>
					<UserSelect v-model="values[f.key]" :label="f.label" />
				</div>
				<label v-else
					class="nx-field"
					:class="{ 'form__wide': f.wide || f.type === 'textarea' }"
					:data-test="'field-' + f.key">
					<span>{{ f.label }}{{ f.required ? ' *' : '' }}</span>
					<textarea v-if="f.type === 'textarea'" v-model="values[f.key]" class="nx-input" />
					<select v-else-if="f.type === 'select'" v-model="values[f.key]" class="nx-input">
						<option v-if="f.empty" :value="null">{{ f.empty }}</option>
						<option v-for="o in f.options" :key="o.value" :value="o.value">{{ o.label }}</option>
					</select>
					<NxNumberInput v-else-if="f.type === 'number'"
						v-model="values[f.key]"
						:min="f.min ?? null"
						:max="f.max ?? null"
						:max-decimals="f.decimals ?? 2"
						:grouping="f.grouping !== false"
						class="nx-input" />
					<input v-else
						v-model="values[f.key]"
						:type="f.type || 'text'"
						class="nx-input">
					<small v-if="f.hint" class="nx-hint">{{ f.hint }}</small>
				</label>
			</template>
		</div>
		<slot :values="values" />
		<template #actions>
			<slot name="extra-actions" :values="values" />
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !valid || (!dirty && !allowUnchanged)"
				data-test="form-save"
				@click="save">
				{{ submitText || t('nc_nexus', 'Save') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from './NxDialog.vue'
import NxNumberInput from './NxNumberInput.vue'
import UserSelect from './UserSelect.vue'
import { errorMessage } from '../api.js'

/**
 * Formulardialog aus einer Feldbeschreibung: [{ key, label, type, required, options, hint, wide, show(values) }].
 * Typen: text, textarea, date, month, number, select, user, checkbox, heading.
 */
export default {
	name: 'FormDialog',
	components: { NcButton, NxDialog, NxNumberInput, UserSelect },
	props: {
		title: { type: String, required: true },
		subtitle: { type: String, default: '' },
		fields: { type: Array, required: true },
		initial: { type: Object, default: () => ({}) },
		/** async (values) => Ergebnis; wirft bei Fehler */
		submit: { type: Function, required: true },
		submitText: { type: String, default: '' },
		successText: { type: String, default: '' },
		size: { type: String, default: 'normal' },
		dataTest: { type: String, default: 'form-dialog' },
		allowUnchanged: { type: Boolean, default: false },
	},
	emits: ['close', 'saved', 'ready'],
	setup() {
		return { t }
	},
	data() {
		const values = {}
		for (const f of this.fields) {
			if (f.type !== 'heading') {
				values[f.key] = this.initial[f.key] ?? (f.type === 'checkbox' ? false : (['number', 'select'].includes(f.type) ? (f.default ?? null) : (f.default ?? '')))
			}
		}
		return { busy: false, values, saved: JSON.stringify(values) }
	},
	computed: {
		visibleFields() {
			return this.fields.filter(f => !f.show || f.show(this.values))
		},
		dirty() {
			return JSON.stringify(this.values) !== this.saved
		},
		valid() {
			return this.visibleFields.every(f => !f.required || (this.values[f.key] !== null && this.values[f.key] !== '' && this.values[f.key] !== undefined))
		},
	},
	methods: {
		async save() {
			this.busy = true
			try {
				const result = await this.submit({ ...this.values })
				if (this.successText) {
					showSuccess(this.successText)
				}
				this.$emit('saved', result)
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

<style scoped lang="scss">
.form__grid {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 0 14px;
}

.form__wide,
.form__heading {
	grid-column: 1 / -1;
}

.form__heading h3 {
	margin: 10px 0 4px;
	font-size: 12px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.form__check {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 12px;
}
</style>
