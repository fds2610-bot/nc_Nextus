<template>
	<figure class="gp" data-test="gate-process">
		<svg :viewBox="`0 0 ${width} ${height}`"
			role="img"
			:aria-label="t('nc_nexus', 'Process from submission to benefit review')">
			<!-- Strecke: je Abschnitt in der Farbe des Ziels, nach Projektende gestrichelt -->
			<line v-for="(node, i) in nodes.slice(1)"
				:key="'track-' + node.code"
				:x1="nodes[i].x"
				:x2="node.x"
				:y1="trackY"
				:y2="trackY"
				:stroke="color(node.code).fill"
				:stroke-dasharray="node.code === 'g6' ? '6 6' : null"
				stroke-width="6"
				stroke-linecap="round" />
			<text v-if="projectEndX"
				:x="projectEndX"
				:y="trackY - 12"
				class="gp__note"
				text-anchor="middle">
				{{ t('nc_nexus', 'Project end') }}
			</text>

			<g v-for="node in nodes"
				:key="node.code"
				text-anchor="middle"
				:data-test="'process-' + node.code">
				<title>{{ node.title }}</title>
				<text :x="node.x" :y="trackY - 40" class="gp__title">{{ node.name }}</text>
				<circle :cx="node.x"
					:cy="trackY"
					:r="node.code === 'submitted' ? 13 : 24"
					:fill="color(node.code).fill"
					class="gp__dot" />
				<text v-if="node.code !== 'submitted'"
					:x="node.x"
					:y="trackY + 6"
					:fill="color(node.code).text"
					class="gp__code">{{ node.code.toUpperCase() }}</text>
				<text v-for="(line, j) in node.deciders"
					:key="j"
					:x="node.x"
					:y="trackY + 46 + j * 16"
					class="gp__meta">{{ line }}</text>
			</g>

			<!-- Gate-Sets: welche Gates jedes Set enthält -->
			<g v-for="(set, i) in sets" :key="'set-' + set.id" :data-test="'process-set-' + set.name">
				<text :x="0" :y="setsY + i * 26 + 4" class="gp__set">
					{{ set.label }}
				</text>
				<template v-for="node in nodes">
					<circle v-if="node.code !== 'submitted' && set.codes.includes(node.code)"
						:key="set.id + node.code"
						:cx="node.x"
						:cy="setsY + i * 26"
						r="6"
						:fill="color(node.code).fill" />
					<line v-else-if="node.code !== 'submitted'"
						:key="set.id + node.code"
						:x1="node.x - 5"
						:x2="node.x + 5"
						:y1="setsY + i * 26"
						:y2="setsY + i * 26"
						class="gp__missing" />
				</template>
			</g>

			<!-- Change Requests: jederzeit möglich -->
			<g :transform="`translate(0 ${crY})`" data-test="process-change">
				<rect :width="width"
					height="52"
					rx="10"
					class="gp__card" />
				<rect width="6"
					height="52"
					rx="3"
					fill="#F59E0B" />
				<text x="22" y="22" class="gp__title">{{ gateLabel('change') }}</text>
				<text x="22" y="40" class="gp__meta">
					{{ t('nc_nexus', 'Possible at any time') }} · {{ deciders('change').join(' · ') }}
				</text>
			</g>
		</svg>
	</figure>
</template>

<script>
import { t } from '@nextcloud/l10n'
import { gateLabel, stageColor } from '../labels.js'

const ORDER = ['g1', 'g2', 'g3', 'g4', 'g5', 'g6']
const LEFT = 190
const MAX = 24

const cut = (s) => s.length > MAX ? s.slice(0, MAX - 1) + '…' : s

/**
 * Prozessbild unter den Entscheidungsrechten: zeigt die Gates, die in aktiven Gate-Sets
 * vorkommen (G1 immer, nur PMO), wer an jedem Gate entscheidet und welche Gates jedes Set nutzt.
 * Die Farben entsprechen der Markierung in der Antragsliste.
 */
export default {
	name: 'GateProcess',
	props: {
		gateSets: { type: Array, required: true },
		rights: { type: Array, required: true },
		bodies: { type: Array, required: true },
	},
	setup() {
		return { t, gateLabel, width: 1100, trackY: 70 }
	},
	computed: {
		codes() {
			const used = new Set(['g1'])
			this.gateSets.forEach(s => s.gates.forEach(g => used.add(g.code)))
			return ORDER.filter(c => used.has(c))
		},
		nodes() {
			const all = ['submitted', ...this.codes]
			const step = (this.width - LEFT - 40) / Math.max(1, all.length - 1)
			return all.map((code, i) => {
				const name = code === 'submitted' ? t('nc_nexus', 'Submission') : gateLabel(code).split(' – ').slice(1).join(' – ')
				const deciders = code === 'submitted'
					? [t('nc_nexus', 'Requestor')]
					: code === 'g1' ? [t('nc_nexus', 'Portfolio office only')] : this.deciders(code)
				return { code, x: LEFT + i * step, name: cut(name), title: (code === 'submitted' ? name : gateLabel(code)) + ': ' + deciders.join(', '), deciders }
			})
		},
		projectEndX() {
			const i = this.nodes.findIndex(n => n.code === 'g6')
			return i > 0 ? (this.nodes[i - 1].x + this.nodes[i].x) / 2 : null
		},
		maxLines() {
			return Math.max(1, ...this.nodes.map(n => n.deciders.length))
		},
		setsY() {
			return this.trackY + 46 + this.maxLines * 16 + 24
		},
		sets() {
			return this.gateSets.map(s => ({
				id: s.id,
				name: s.name,
				label: cut(s.name + (s.projectClasses.length ? ' (' + s.projectClasses.join(', ') + ')' : '')),
				codes: ['g1', ...s.gates.map(g => g.code)],
			}))
		},
		crY() {
			return this.setsY + this.sets.length * 26 + 8
		},
		height() {
			return this.crY + 54
		},
	},
	methods: {
		color(code) {
			return stageColor(code)
		},
		/**
		 * Zuständige je Gate aus den Entscheidungsrechten; ohne Recht Board oder PMO direkt
		 *
		 * @param {string} code Gate oder „change“
		 */
		deciders(code) {
			const direct = t('nc_nexus', 'Board / portfolio office')
			const rights = this.rights.filter(r => r.gateCode === code)
			const lines = rights.map(r => {
				const who = r.bodyId ? (this.bodies.find(b => b.id === r.bodyId)?.name ?? '?') : direct
				return cut((r.projectClasses ? r.projectClasses.join(', ') + ': ' : '') + who)
			})
			if (!rights.some(r => !r.projectClasses)) {
				lines.push(rights.length ? t('nc_nexus', 'otherwise {who}', { who: direct }) : direct)
			}
			return lines
		},
	},
}
</script>

<style scoped lang="scss">
.gp {
	margin: 16px 0 0;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);

	svg {
		display: block;
		width: 100%;
		height: auto;
		overflow: visible;
	}

	&__title {
		fill: var(--color-main-text);
		font-size: 14px;
		font-weight: 600;
	}

	&__code {
		font-size: 16px;
		font-weight: 700;
	}

	&__meta,
	&__note {
		fill: var(--color-text-maxcontrast);
		font-size: 12px;
	}

	&__set {
		fill: var(--color-main-text);
		font-size: 13px;
	}

	&__missing {
		stroke: var(--color-border-dark);
		stroke-width: 2;
	}

	&__card {
		fill: var(--color-background-hover);
		stroke: var(--color-border);
	}

	&__dot {
		filter: drop-shadow(0 2px 3px rgb(15 23 42 / 15%));
	}
}
</style>
