<template>
	<div ref="root" class="mat" data-test="maturity-chart">
		<div v-if="!table" class="mat__plot">
			<svg :width="width"
				:height="height"
				:viewBox="`0 0 ${width} ${height}`"
				role="img"
				:aria-label="t('nc_nexus', 'Portfolio matrix: maturity by status against effort in person days; circle area shows the cost')">
				<!-- Quadranten: links vor der Freigabe, rechts danach; oben ab Klasse M -->
				<rect :x="pad.left"
					:y="pad.top"
					:width="splitX - pad.left"
					:height="splitY - pad.top"
					class="mat__quad mat__quad--focus" />
				<text :x="pad.left + 8" :y="pad.top + 16" class="mat__qlab">{{ t('nc_nexus', 'Large, not yet released') }}</text>
				<text :x="width - pad.right - 8"
					:y="pad.top + 16"
					class="mat__qlab"
					text-anchor="end">{{ t('nc_nexus', 'Large, released or running') }}</text>
				<text :x="pad.left + 8" :y="height - pad.bottom - 8" class="mat__qlab">{{ t('nc_nexus', 'Small, not yet released') }}</text>
				<text :x="width - pad.right - 8"
					:y="height - pad.bottom - 8"
					class="mat__qlab"
					text-anchor="end">{{ t('nc_nexus', 'Small, released or running') }}</text>

				<!-- y-Achse: logarithmisch, Gitter an Zehnerpotenzen -->
				<g v-for="tick in yTicks" :key="'y' + tick">
					<line :x1="pad.left"
						:x2="width - pad.right"
						:y1="y(tick)"
						:y2="y(tick)"
						class="mat__grid" />
					<text :x="pad.left - 8"
						:y="y(tick) + 4"
						class="mat__tick"
						text-anchor="end">{{ formatNumber(tick) }}</text>
				</g>
				<text :x="14"
					:y="pad.top + plotH / 2"
					class="mat__axis"
					text-anchor="middle"
					:transform="`rotate(-90 14 ${pad.top + plotH / 2})`">{{ t('nc_nexus', 'Effort (PD, log.)') }}</text>

				<!-- Klassengrenzen -->
				<g v-for="(days, cls) in data.classDays" :key="'c' + cls">
					<template v-if="days > 0 && days >= yMin && days <= yMax">
						<line :x1="pad.left"
							:x2="width - pad.right"
							:y1="y(days)"
							:y2="y(days)"
							class="mat__class"
							:class="{ 'mat__class--split': cls === 'M' }" />
						<text :x="width - pad.right + 4" :y="y(days) + 4" class="mat__tick">{{ cls }}</text>
					</template>
				</g>

				<!-- x-Achse: Reifegrad -->
				<g v-for="(stage, i) in data.stages" :key="stage">
					<line v-if="i > 0"
						:x1="pad.left + i * band"
						:x2="pad.left + i * band"
						:y1="pad.top"
						:y2="height - pad.bottom"
						:class="stage === data.projectStage ? 'mat__split' : 'mat__grid'" />
					<text :x="pad.left + (i + 0.5) * band"
						:y="height - pad.bottom + 18"
						class="mat__stage"
						text-anchor="middle">{{ stageLabel(stage) }}</text>
				</g>
				<text :x="splitX + 4"
					:y="height - pad.bottom + 34"
					class="mat__tick">G3 ›</text>

				<!-- Vorhaben -->
				<g v-for="p in placed"
					:key="p.id"
					class="mat__dot"
					:data-test="'maturity-' + p.key"
					tabindex="0"
					role="button"
					:aria-label="describe(p)"
					@mouseenter="hover = p"
					@mouseleave="hover = null"
					@focus="hover = p"
					@blur="hover = null"
					@click="open(p)"
					@keydown.enter="open(p)">
					<circle :cx="p.cx"
						:cy="p.cy"
						:r="Math.max(p.r, 12)"
						class="mat__hit" />
					<circle :cx="p.cx"
						:cy="p.cy"
						:r="p.r"
						class="mat__mark"
						:class="{
							'mat__mark--nocost': p.cost === null,
							'mat__mark--paused': p.status === 'paused',
							'mat__mark--hover': hover && hover.id === p.id,
						}" />
					<text v-if="p.label"
						:x="p.lx"
						:y="p.ly"
						:text-anchor="p.anchor"
						class="mat__label">{{ p.label }}</text>
				</g>
			</svg>
			<div v-if="hover"
				class="mat__tip"
				:style="tipStyle"
				data-test="maturity-tip">
				<strong>{{ hover.key }}</strong> {{ hover.title }}
				<div class="mat__tiprow">
					{{ statusLabel(hover.status) }}{{ hover.projectClass ? ' · ' + t('nc_nexus', 'class {c}', { c: hover.projectClass }) : '' }}
				</div>
				<div class="mat__tiprow">
					{{ t('nc_nexus', '{pd} PD', { pd: formatNumber(hover.personDays) }) }} · {{ hover.cost === null ? t('nc_nexus', 'no cost estimate') : formatMoney(hover.cost) }}
				</div>
			</div>
		</div>

		<table v-else class="mat__table" data-test="maturity-table">
			<thead>
				<tr>
					<th>{{ t('nc_nexus', 'Maturity') }}</th>
					<th>{{ t('nc_nexus', 'Demand / project') }}</th>
					<th>{{ t('nc_nexus', 'Status') }}</th>
					<th class="mat__num">
						{{ t('nc_nexus', 'Effort (PD)') }}
					</th>
					<th class="mat__num">
						{{ t('nc_nexus', 'Cost') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="p in tableRows" :key="p.id">
					<td>{{ stageLabel(p.stage) }}</td>
					<td>
						<button type="button" class="mat__link" @click="open(p)">
							{{ p.key }} · {{ p.title }}
						</button>
					</td>
					<td>{{ statusLabel(p.status) }}</td>
					<td class="mat__num">
						{{ formatNumber(p.personDays) }}
					</td>
					<td class="mat__num">
						{{ p.cost === null ? '–' : formatMoney(p.cost) }}
					</td>
				</tr>
			</tbody>
		</table>

		<div class="mat__foot">
			<span class="mat__legend" :aria-label="t('nc_nexus', 'Legend')">
				<span class="mat__legtitle">{{ t('nc_nexus', 'Circle area = cost') }}</span>
				<span v-for="c in sizeLegend" :key="c.value" class="mat__legitem">
					<svg :width="c.r * 2 + 4" :height="c.r * 2 + 4"><circle :cx="c.r + 2"
						:cy="c.r + 2"
						:r="c.r"
						class="mat__mark" /></svg>
					{{ formatMoney(c.value) }}
				</span>
				<span class="mat__legitem">
					<svg width="14" height="14"><circle cx="7"
						cy="7"
						r="5"
						class="mat__mark mat__mark--nocost" /></svg>
					{{ t('nc_nexus', 'no cost estimate') }}
				</span>
				<span class="mat__legitem">
					<svg width="18" height="18"><circle cx="9"
						cy="9"
						r="7"
						class="mat__mark mat__mark--paused" /></svg>
					{{ t('nc_nexus', 'paused') }}
				</span>
			</span>
			<span class="nx-view__spacer" />
			<span v-if="data.withoutDays.length" class="mat__missing" data-test="maturity-without-days">
				{{ n('nc_nexus', '%n demand without effort estimate is not shown', '%n demands without effort estimate are not shown', data.withoutDays.length) }}
			</span>
			<button type="button"
				class="mat__toggle"
				data-test="maturity-toggle"
				@click="table = !table">
				{{ table ? t('nc_nexus', 'Show as chart') : t('nc_nexus', 'Show as table') }}
			</button>
		</div>
	</div>
</template>

<script>
import { n, t } from '@nextcloud/l10n'
import { formatMoney, formatNumber, statusLabel } from '../labels.js'

const HEIGHT = 380
const R_MAX = 30
const R_MIN = 4
const LABELS = 3

export default {
	name: 'MaturityChart',
	props: {
		/** maturity-Teil des Dashboards: stages, projectStage, classDays, points, withoutDays */
		data: { type: Object, required: true },
	},
	emits: ['open', 'project'],
	setup() {
		return { n, t, formatMoney, formatNumber, statusLabel }
	},
	data() {
		return { width: 900, height: HEIGHT, pad: { top: 12, right: 28, bottom: 46, left: 62 }, hover: null, table: false, observer: null }
	},
	computed: {
		plotW() {
			return Math.max(100, this.width - this.pad.left - this.pad.right)
		},
		plotH() {
			return this.height - this.pad.top - this.pad.bottom
		},
		band() {
			return this.plotW / this.data.stages.length
		},
		splitX() {
			return this.pad.left + this.data.stages.indexOf(this.data.projectStage) * this.band
		},
		splitY() {
			const m = this.data.classDays.M
			return m > 0 ? this.y(Math.min(Math.max(m, this.yMin), this.yMax)) : this.pad.top + this.plotH / 2
		},
		yMin() {
			const min = Math.min(...this.data.points.map(p => p.personDays), this.data.classDays.S || 1)
			return Math.pow(10, Math.floor(Math.log10(Math.max(min, 0.1))))
		},
		yMax() {
			const max = Math.max(...this.data.points.map(p => p.personDays), (this.data.classDays.L || 100) * 1.5)
			return Math.pow(10, Math.ceil(Math.log10(max)))
		},
		yTicks() {
			const ticks = []
			for (let v = this.yMin; v <= this.yMax; v *= 10) {
				ticks.push(v)
			}
			return ticks
		},
		maxCost() {
			return Math.max(0, ...this.data.points.map(p => p.cost ?? 0))
		},
		rMax() {
			return Math.min(R_MAX, this.band * 0.42)
		},
		// Position je Vorhaben: Spalte = Stufe, innerhalb der Spalte gleichmäßig verteilt (stabil nach ID)
		placed() {
			const byStage = {}
			for (const p of this.data.points) {
				(byStage[p.stage] ??= []).push(p)
			}
			const pos = {}
			for (const [stage, list] of Object.entries(byStage)) {
				const sorted = [...list].sort((a, b) => a.id - b.id)
				const i0 = this.data.stages.indexOf(stage)
				sorted.forEach((p, i) => {
					const offset = sorted.length === 1 ? 0 : (i / (sorted.length - 1) - 0.5) * 0.6
					pos[p.id] = this.pad.left + (i0 + 0.5 + offset) * this.band
				})
			}
			const placed = this.data.points.map(p => ({ ...p, cx: pos[p.id], cy: this.y(p.personDays), r: this.radius(p.cost), label: '' }))
			// Beschriftung nur für die teuersten Vorhaben, jeweils an einer freien Stelle (rechts, oben, unten, links)
			const boxes = []
			for (const p of [...placed].filter(x => x.cost).sort((a, b) => b.cost - a.cost).slice(0, LABELS)) {
				const text = this.shorten(p.title)
				const w = text.length * 6.6
				const h = 14
				const options = [
					{ x: p.cx + p.r + 4, y: p.cy + 4, anchor: 'start' },
					{ x: p.cx, y: p.cy - p.r - 5, anchor: 'middle' },
					{ x: p.cx, y: p.cy + p.r + 14, anchor: 'middle' },
					{ x: p.cx - p.r - 4, y: p.cy + 4, anchor: 'end' },
				]
				const box = o => {
					const left = o.anchor === 'start' ? o.x : (o.anchor === 'middle' ? o.x - w / 2 : o.x - w)
					return { l: left, r: left + w, t: o.y - h + 3, b: o.y + 3 }
				}
				const free = b => b.l >= this.pad.left && b.r <= this.width - this.pad.right && b.t >= this.pad.top && b.b <= this.height - this.pad.bottom
					&& !boxes.some(o => b.l < o.r && b.r > o.l && b.t < o.b && b.b > o.t)
					&& !placed.some(c => c.id !== p.id && c.cx + c.r > b.l && c.cx - c.r < b.r && c.cy + c.r > b.t && c.cy - c.r < b.b)
				const choice = options.find(o => free(box(o)))
				if (choice) {
					boxes.push(box(choice))
					Object.assign(p, { label: text, lx: choice.x, ly: choice.y, anchor: choice.anchor })
				}
			}
			return placed
		},
		sizeLegend() {
			if (!this.maxCost) {
				return []
			}
			// abgerundet, damit kein Legendenkreis größer ist als das größte Vorhaben
			const top = this.niceBelow(this.maxCost)
			return [...new Set([this.niceBelow(top / 10), this.niceBelow(top / 3), top])].filter(v => v > 0).map(value => ({ value, r: this.radius(value) }))
		},
		tipStyle() {
			const p = this.placed.find(x => x.id === this.hover.id) ?? this.hover
			const left = Math.min(p.cx + 14, this.width - 260)
			return { left: left + 'px', top: Math.max(0, p.cy - 10) + 'px' }
		},
		tableRows() {
			const order = this.data.stages
			return [...this.data.points].sort((a, b) => order.indexOf(a.stage) - order.indexOf(b.stage) || b.personDays - a.personDays)
		},
	},
	mounted() {
		this.measure()
		this.observer = new ResizeObserver(() => this.measure())
		this.observer.observe(this.$refs.root)
	},
	beforeUnmount() {
		this.observer?.disconnect()
	},
	methods: {
		measure() {
			const w = this.$refs.root?.clientWidth
			if (w) {
				this.width = w
			}
		},
		y(v) {
			const lo = Math.log10(this.yMin)
			const hi = Math.log10(this.yMax)
			return this.pad.top + this.plotH * (1 - (Math.log10(Math.max(v, this.yMin)) - lo) / (hi - lo || 1))
		},
		// Fläche proportional zu den Kosten
		radius(cost) {
			if (!cost || !this.maxCost) {
				return 5
			}
			return Math.max(R_MIN, this.rMax * Math.sqrt(cost / this.maxCost))
		},
		niceBelow(v) {
			const p = Math.pow(10, Math.floor(Math.log10(v)))
			return [5, 2, 1].map(f => f * p).find(x => x <= v)
		},
		shorten(title) {
			return title.length > 22 ? title.slice(0, 21) + '…' : title
		},
		stageLabel(stage) {
			return {
				intake: t('nc_nexus', 'Intake'),
				analysis: t('nc_nexus', 'Analysis'),
				candidate: t('nc_nexus', 'Candidate'),
				prioritized: t('nc_nexus', 'Prioritized'),
				released: t('nc_nexus', 'Released'),
				execution: t('nc_nexus', 'Execution'),
				completion: t('nc_nexus', 'Completion'),
			}[stage] || stage
		},
		describe(p) {
			return `${p.key} ${p.title}: ${statusLabel(p.status)}, ${t('nc_nexus', '{pd} PD', { pd: formatNumber(p.personDays) })}, ${p.cost === null ? t('nc_nexus', 'no cost estimate') : formatMoney(p.cost)}`
		},
		open(p) {
			this.$emit(p.project ? 'project' : 'open', p.id)
		},
	},
}
</script>

<style scoped lang="scss">
.mat {
	position: relative;

	&__plot {
		position: relative;
	}

	svg {
		display: block;
		overflow: visible;
	}

	&__quad--focus {
		fill: var(--color-primary-element-light);
		opacity: 0.35;
	}

	&__qlab {
		font-size: 12px;
		fill: var(--color-text-maxcontrast);
	}

	&__grid {
		stroke: var(--color-border);
		stroke-width: 1;
	}

	&__class {
		stroke: var(--color-border-dark);
		stroke-width: 1;
		stroke-dasharray: 2 4;

		&--split {
			stroke: var(--color-text-maxcontrast);
			stroke-dasharray: 6 4;
		}
	}

	&__split {
		stroke: var(--color-text-maxcontrast);
		stroke-width: 1;
		stroke-dasharray: 6 4;
	}

	&__tick,
	&__stage,
	&__axis {
		font-size: 12px;
		fill: var(--color-text-maxcontrast);
	}

	&__stage {
		fill: var(--color-main-text);
	}

	&__dot {
		cursor: pointer;
		outline: none;
	}

	&__hit {
		fill: transparent;
	}

	&__mark {
		fill: var(--color-primary-element);
		fill-opacity: 0.55;
		// Ring in Flächenfarbe trennt überlappende Kreise
		stroke: var(--color-main-background);
		stroke-width: 2;

		&--nocost {
			fill: var(--color-main-background);
			fill-opacity: 1;
			stroke: var(--color-text-maxcontrast);
			stroke-width: 1.5;
		}

		&--paused {
			fill-opacity: 0.2;
			stroke: var(--color-primary-element);
			stroke-dasharray: 3 2;
		}

		&--hover {
			fill-opacity: 0.85;
			stroke: var(--color-main-text);
		}
	}

	&__dot:focus-visible &__mark {
		stroke: var(--color-main-text);
		stroke-width: 3;
	}

	&__label {
		font-size: 12px;
		fill: var(--color-main-text);
		pointer-events: none;
	}

	&__tip {
		position: absolute;
		z-index: 2;
		max-width: 250px;
		padding: 8px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-element, 8px);
		background: var(--color-main-background);
		box-shadow: 0 2px 8px var(--color-box-shadow);
		font-size: 13px;
		pointer-events: none;
	}

	&__tiprow {
		color: var(--color-text-maxcontrast);
	}

	&__foot {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 14px;
		margin-top: 6px;
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__legend {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 12px;
	}

	&__legtitle {
		color: var(--color-main-text);
	}

	&__legitem {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	&__toggle,
	&__link {
		padding: 2px 8px;
		border: none;
		border-radius: var(--border-radius-element, 8px);
		background: none;
		color: var(--color-primary-element);
		font: inherit;
		cursor: pointer;
		text-align: start;

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__link {
		padding: 0;
		color: var(--color-main-text);
	}

	&__table {
		width: 100%;
		border-collapse: collapse;
		font-size: 14px;

		th,
		td {
			padding: 5px 8px;
			border-bottom: 1px solid var(--color-border);
			text-align: start;
		}

		th {
			color: var(--color-text-maxcontrast);
			font-weight: 600;
		}
	}

	&__num {
		text-align: end !important;
		font-variant-numeric: tabular-nums;
	}
}
</style>
