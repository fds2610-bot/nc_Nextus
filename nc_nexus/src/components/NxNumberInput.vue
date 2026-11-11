<template>
	<input ref="input"
		:value="shown"
		type="text"
		inputmode="decimal"
		autocomplete="off"
		@focus="focused = true"
		@input="onInput"
		@blur="onBlur"
		@keydown.enter="commit">
</template>

<script>
import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * Zahlenfeld mit Tausender- und Dezimalzeichen nach der Sprache von Nextcloud.
 * Beim Tippen wird frei eingegeben; beim Verlassen des Feldes wird gelesen und formatiert angezeigt.
 * Der Wert nach außen ist eine Zahl oder null (leer).
 */
export default {
	name: 'NxNumberInput',
	props: {
		modelValue: { type: [Number, String], default: null },
		min: { type: Number, default: null },
		max: { type: Number, default: null },
		maxDecimals: { type: Number, default: 2 },
		/** Tausendertrennzeichen anzeigen (aus bei Jahreszahlen) */
		grouping: { type: Boolean, default: true },
	},
	emits: ['update:modelValue'],
	data() {
		return { focused: false, text: '' }
	},
	computed: {
		separators() {
			const parts = new Intl.NumberFormat(getCanonicalLocale()).formatToParts(11111.5)
			return {
				group: parts.find(p => p.type === 'group')?.value ?? '',
				decimal: parts.find(p => p.type === 'decimal')?.value ?? '.',
			}
		},
		shown() {
			return this.focused ? this.text : this.format(this.modelValue)
		},
	},
	watch: {
		modelValue: {
			immediate: true,
			handler(value) {
				if (!this.focused) {
					this.text = this.format(value)
				}
			},
		},
	},
	methods: {
		format(value) {
			if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
				return ''
			}
			return Number(value).toLocaleString(getCanonicalLocale(), { maximumFractionDigits: this.maxDecimals, useGrouping: this.grouping })
		},
		/**
		 * Eingabe lesen: Gruppenzeichen entfernen, Dezimalzeichen vereinheitlichen.
		 *
		 * @param {string} text Eingabe des Nutzers
		 */
		parse(text) {
			const { group, decimal } = this.separators
			let s = String(text).replace(/[\s']/g, '')
			if (group) {
				s = s.split(group).join('')
			}
			s = s.replace(decimal, '.')
			if (s === '' || !/^[-+]?(\d+\.?\d*|\.\d+)$/.test(s)) {
				return null
			}
			let n = Number(s)
			if (this.min !== null && n < this.min) n = this.min
			if (this.max !== null && n > this.max) n = this.max
			return n
		},
		onInput(event) {
			this.text = event.target.value
			// Wert sofort weitergeben, damit Summen und Speichern-Knöpfe mitlaufen
			this.$emit('update:modelValue', this.parse(this.text))
		},
		commit() {
			const n = this.parse(this.text)
			this.$emit('update:modelValue', n)
			this.text = this.format(n)
		},
		onBlur() {
			this.commit()
			this.focused = false
		},
	},
}
</script>
