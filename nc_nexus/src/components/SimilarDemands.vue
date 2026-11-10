<template>
	<div v-if="matches.length || hidden" class="similar" data-test="similar-demands">
		<div class="similar__head">
			<NcIconSvgWrapper :path="mdiContentDuplicate" :size="18" />
			<strong>{{ heading }}</strong>
		</div>
		<ul v-if="matches.length">
			<li v-for="m in matches" :key="m.id" :data-test="'similar-' + m.key">
				<span class="similar__key">{{ m.key }}</span>
				<span class="similar__title">{{ m.title }}</span>
				<span class="nx-chip" :class="'nx-chip--' + statusChip(m.status)">{{ statusLabel(m.status) }}</span>
			</li>
		</ul>
		<p v-if="hidden" class="similar__hidden" data-test="similar-hidden">
			{{ n('nc_nexus', '%n similar demand in another area – the portfolio office will see it during triage.', '%n similar demands in other areas – the portfolio office will see them during triage.', hidden) }}
		</p>
		<p v-if="hint" class="nx-hint">
			{{ hint }}
		</p>
	</div>
</template>

<script>
import { mdiContentDuplicate } from '@mdi/js'
import { n, t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { similarDemands } from '../api.js'
import { statusChip, statusLabel } from '../labels.js'

const DEBOUNCE_MS = 400

export default {
	name: 'SimilarDemands',
	components: { NcIconSvgWrapper },
	props: {
		title: { type: String, default: '' },
		problem: { type: String, default: '' },
		excludeId: { type: Number, default: null },
		/** „input“: beim Tippen (Antragsteller), „triage“: einmalig für das PMO */
		mode: { type: String, default: 'input' },
	},
	emits: ['loaded'],
	setup() {
		return { mdiContentDuplicate, n, t, statusChip, statusLabel }
	},
	data() {
		return { matches: [], hidden: 0, timer: null }
	},
	computed: {
		heading() {
			return this.mode === 'triage' ? t('nc_nexus', 'Possible duplicates') : t('nc_nexus', 'Similar demands already exist')
		},
		hint() {
			return this.mode === 'input' && this.matches.length
				? t('nc_nexus', 'Please check whether your request is already covered before submitting.')
				: ''
		},
	},
	watch: {
		title: 'schedule',
		problem: 'schedule',
	},
	mounted() {
		this.load()
	},
	beforeUnmount() {
		clearTimeout(this.timer)
	},
	methods: {
		schedule() {
			if (this.mode !== 'input') {
				return
			}
			clearTimeout(this.timer)
			this.timer = setTimeout(this.load, DEBOUNCE_MS)
		},
		async load() {
			if (this.title.trim().length < 4) {
				this.matches = []
				this.hidden = 0
				return
			}
			try {
				const result = await similarDemands({ title: this.title, problem: this.problem, excludeId: this.excludeId ?? undefined })
				this.matches = result.matches
				this.hidden = result.hidden
				this.$emit('loaded', result)
			} catch (e) {
				// Vorschläge sind eine Hilfe; ein Fehler hier darf die Erfassung nicht stören
				this.matches = []
				this.hidden = 0
			}
		},
	},
}
</script>

<style scoped lang="scss">
.similar {
	margin: 14px 0;
	padding: 10px 14px;
	border-radius: var(--border-radius-large, 10px);
	background: rgba(var(--color-info-rgb), 0.1);

	&__head {
		display: flex;
		align-items: center;
		gap: 6px;
		color: var(--color-info-text);
	}

	ul {
		margin: 6px 0 0;
		padding: 0;
		list-style: none;
	}

	li {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr) auto;
		align-items: center;
		gap: 10px;
		padding: 3px 0;
	}

	&__key {
		color: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__title {
		overflow: hidden;
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	&__hidden {
		margin: 6px 0 0;
		color: var(--color-text-maxcontrast);
	}
}
</style>
