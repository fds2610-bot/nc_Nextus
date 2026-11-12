<template>
	<div ref="root" class="tl nx-buckets" data-test="timeline-chart">
		<div v-if="!table" class="tl__plot">
			<svg :width="width"
				:height="height"
				:viewBox="`0 0 ${width} ${height}`"
				role="img"
				:aria-label="t('nc_nexus', 'Timeline: duration of each demand and project; dashed outline = planned, solid outline = released; vertical strokes = status reports')">
				<!-- Monatsraster mit Beschriftung -->
				<g v-for="m in monthTicks" :key="m.key">
					<line :x1="m.x"
						:x2="m.x"
						:y1="pad.top - 4"
						:y2="height - pad.bottom"
						:class="m.year ? 'tl__grid tl__grid--year' : 'tl__grid'" />
					<text v-if="m.label"
						:x="m.x + 3"
						:y="pad.top - 8"
						class="tl__tick">{{ m.label }}</text>
				</g>

				<!-- Vorhaben -->
				<g v-for="(b, i) in placed"
					:key="b.id"
					class="tl__row"
					:data-test="'timeline-' + b.key"
					tabindex="0"
					role="button"
					:aria-label="describe(b)"
					@mouseenter="hover = b"
					@mouseleave="hover = null"
					@focus="hover = b"
					@blur="hover = null"
					@click="open(b)"
					@keydown.enter="open(b)">
					<rect :x="0"
						:y="rowY(i)"
						:width="width"
						:height="ROW"
						class="tl__hit"
						:class="{ 'tl__hit--hover': hover && hover.id === b.id }" />
					<text :x="4"
						:y="rowY(i) + ROW / 2 + 4"
						class="tl__key">{{ b.key }}</text>
					<text :x="keyW"
						:y="rowY(i) + ROW / 2 + 4"
						class="tl__title">{{ shorten(b.title) }}</text>
					<rect :x="b.x1"
						:y="rowY(i) + (ROW - BAR) / 2"
						:width="Math.max(2, b.x2 - b.x1)"
						:height="BAR"
						rx="3"
						class="tl__bar"
						:style="bucketStyle(b.bucket)"
						:class="{
							'tl__bar--planned': !b.released,
							'tl__bar--paused': b.status === 'paused',
						}" />
					<!-- Statusberichte als senkrechter Strich -->
					<line v-for="(r, j) in b.marks"
						:key="j"
						:x1="r.x"
						:x2="r.x"
						:y1="rowY(i) + (ROW - BAR) / 2 - 3"
						:y2="rowY(i) + (ROW + BAR) / 2 + 3"
						class="tl__report"
						data-test="timeline-report" />
				</g>

				<!-- Heute -->
				<template v-if="todayX !== null">
					<line :x1="todayX"
						:x2="todayX"
						:y1="pad.top - 4"
						:y2="height - pad.bottom + 4"
						class="tl__today" />
					<text :x="todayX"
						:y="height - pad.bottom + 16"
						text-anchor="middle"
						class="tl__today-lab">{{ t('nc_nexus', 'Today') }}</text>
				</template>
			</svg>
			<div v-if="hover"
				class="tl__tip"
				:style="tipStyle"
				data-test="timeline-tip">
				<strong>{{ hover.key }}</strong> {{ hover.title }}
				<div class="tl__tiprow">
					{{ statusLabel(hover.status) }} · {{ hover.released ? t('nc_nexus', 'released') : t('nc_nexus', 'planned, not yet released') }}
				</div>
				<div class="tl__tiprow">
					{{ formatIsoDay(hover.start) }} – {{ formatIsoDay(hover.end) }}
				</div>
				<div v-if="hover.source === 'demand'" class="tl__tiprow">
					{{ t('nc_nexus', 'Period derived from the planned resource demand') }}
				</div>
				<div v-if="bucketName(hover.bucket)" class="tl__tiprow">
					{{ bucketName(hover.bucket) }}
				</div>
				<div v-if="hover.reports.length" class="tl__tiprow">
					{{ t('nc_nexus', 'Status reports:') }}
					<span v-for="r in hover.reports" :key="r.date + r.period" class="tl__tiprep">
						<span class="tl__dot" :class="'tl__dot--' + r.rag" />{{ formatIsoDay(r.date) }}
					</span>
				</div>
			</div>
		</div>

		<table v-else class="tl__table" data-test="timeline-table">
			<thead>
				<tr>
					<th>{{ t('nc_nexus', 'Demand / project') }}</th>
					<th>{{ t('nc_nexus', 'Status') }}</th>
					<th>{{ t('nc_nexus', 'Start') }}</th>
					<th>{{ t('nc_nexus', 'End') }}</th>
					<th>{{ t('nc_nexus', 'Status reports') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="b in data.bars" :key="b.id">
					<td>
						<button type="button" class="tl__link" @click="open(b)">
							{{ b.key }} · {{ b.title }}
						</button>
					</td>
					<td>{{ statusLabel(b.status) }} · {{ b.released ? t('nc_nexus', 'released') : t('nc_nexus', 'planned') }}</td>
					<td>{{ formatIsoDay(b.start) }}</td>
					<td>{{ formatIsoDay(b.end) }}{{ b.source === 'demand' ? ' *' : '' }}</td>
					<td>{{ b.reports.map(r => formatIsoDay(r.date)).join(', ') || '–' }}</td>
				</tr>
			</tbody>
		</table>

		<div class="tl__foot">
			<span class="tl__legend" :aria-label="t('nc_nexus', 'Legend')">
				<span class="tl__legitem">
					<svg width="30" height="14"><rect x="1"
						y="2"
						width="28"
						height="10"
						rx="3"
						class="tl__bar tl__bar--planned tl__bar--neutral" /></svg>
					{{ t('nc_nexus', 'planned, not yet released') }}
				</span>
				<span class="tl__legitem">
					<svg width="30" height="14"><rect x="1"
						y="2"
						width="28"
						height="10"
						rx="3"
						class="tl__bar tl__bar--neutral" /></svg>
					{{ t('nc_nexus', 'released (G3)') }}
				</span>
				<span class="tl__legitem">
					<svg width="10" height="16"><line x1="5"
						x2="5"
						y1="1"
						y2="15"
						class="tl__report" /></svg>
					{{ t('nc_nexus', 'status report') }}
				</span>
				<span v-for="b in data.buckets"
					:key="b.code"
					class="tl__legitem">
					<svg width="14" height="14"><rect x="1"
						y="1"
						width="12"
						height="12"
						rx="3"
						class="tl__bar"
						:style="bucketStyle(b.code)" /></svg>
					{{ b.code }} · {{ b.name }}
				</span>
			</span>
			<span class="nx-view__spacer" />
			<span v-if="data.withoutDates" class="tl__missing" data-test="timeline-without-dates">
				{{ n('nc_nexus', '%n demand without dates or resource demand is not shown', '%n demands without dates or resource demand are not shown', data.withoutDates) }}
			</span>
			<button type="button"
				class="tl__toggle"
				data-test="timeline-toggle"
				@click="table = !table">
				{{ table ? t('nc_nexus', 'Show as chart') : t('nc_nexus', 'Show as table') }}
			</button>
		</div>
	</div>
</template>

<script>
import { getCanonicalLocale, n, t } from '@nextcloud/l10n'
import { formatIsoDay, statusLabel } from '../labels.js'

const ROW = 26
const BAR = 14
const DAY = 86400000
// geprüfte Farben (Blau, Gelb, Magenta, Grün); weitere Buckets grau
const BUCKET_COLORS = 4

/**
 * JJJJ-MM-TT als Tageszahl (UTC), damit Sommerzeit nichts verschiebt
 *
 * @param {string} iso Datum JJJJ-MM-TT
 */
const day = (iso) => {
	const [y, m, d] = iso.split('-').map(Number)
	return Date.UTC(y, m - 1, d) / DAY
}

export default {
	name: 'TimelineChart',
	props: {
		/** timeline-Teil des Dashboards: today, bars, withoutDates, buckets */
		data: { type: Object, required: true },
	},
	emits: ['open', 'project'],
	setup() {
		return { n, t, formatIsoDay, statusLabel, ROW, BAR }
	},
	data() {
		return { width: 900, pad: { top: 22, right: 12, bottom: 24 }, hover: null, table: false, observer: null }
	},
	computed: {
		keyW() {
			return 104
		},
		labelW() {
			return Math.min(300, Math.max(160, this.width * 0.28))
		},
		height() {
			return this.pad.top + this.data.bars.length * ROW + this.pad.bottom
		},
		// Zeitraum: vom ersten Monatsanfang bis zum Ende des letzten Monats, heute immer sichtbar
		range() {
			const starts = this.data.bars.map(b => b.start)
			const ends = this.data.bars.map(b => b.end)
			const reports = this.data.bars.flatMap(b => b.reports.map(r => r.date))
			const lo = [...starts, ...reports, this.data.today].sort()[0]
			const hi = [...ends, ...reports, this.data.today].sort().pop()
			const [ly, lm] = lo.split('-').map(Number)
			const [hy, hm] = hi.split('-').map(Number)
			return {
				from: Date.UTC(ly, lm - 1, 1) / DAY,
				to: Date.UTC(hy, hm, 1) / DAY,
				firstMonth: { y: ly, m: lm - 1 },
				months: (hy - ly) * 12 + hm - lm + 1,
			}
		},
		plotW() {
			return Math.max(100, this.width - this.labelW - this.pad.right)
		},
		monthTicks() {
			const { firstMonth, months } = this.range
			const perMonth = this.plotW / months
			// Beschriftung je Monat, je Quartal oder je Jahr – je nach Platz
			const step = perMonth >= 46 ? 1 : (perMonth >= 16 ? 3 : 12)
			const ticks = []
			for (let i = 0; i <= months; i++) {
				const date = new Date(Date.UTC(firstMonth.y, firstMonth.m + i, 1))
				const month = date.getUTCMonth()
				const label = i < months && month % step === 0
					? (step === 12 || month === 0 || i === 0
						? date.toLocaleDateString(getCanonicalLocale(), { month: step === 12 ? undefined : 'short', year: 'numeric', timeZone: 'UTC' })
						: date.toLocaleDateString(getCanonicalLocale(), { month: 'short', timeZone: 'UTC' }))
					: ''
				ticks.push({ key: i, x: this.x(date.getTime() / DAY), label, year: month === 0 })
			}
			return ticks
		},
		todayX() {
			return this.data.today ? this.x(day(this.data.today) + 0.5) : null
		},
		placed() {
			return this.data.bars.map(b => ({
				...b,
				x1: this.x(day(b.start)),
				// Ende ist der letzte Tag einschließlich
				x2: this.x(day(b.end) + 1),
				marks: b.reports.map(r => ({ ...r, x: this.x(day(r.date) + 0.5) })),
			}))
		},
		tipStyle() {
			const i = this.data.bars.findIndex(b => b.id === this.hover.id)
			const b = this.placed[i]
			const left = Math.max(0, Math.min((b.x1 + b.x2) / 2, this.width - 280))
			return { left: left + 'px', top: (this.rowY(i) + ROW + 2) + 'px' }
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
		x(dayNumber) {
			const { from, to } = this.range
			return this.labelW + this.plotW * (dayNumber - from) / (to - from || 1)
		},
		rowY(i) {
			return this.pad.top + i * ROW
		},
		shorten(title) {
			const max = Math.max(8, Math.floor((this.labelW - this.keyW - 8) / 6.6))
			return title.length > max ? title.slice(0, max - 1) + '…' : title
		},
		describe(b) {
			return `${b.key} ${b.title}: ${b.released ? t('nc_nexus', 'released') : t('nc_nexus', 'planned, not yet released')}, ${formatIsoDay(b.start)} – ${formatIsoDay(b.end)}, ${n('nc_nexus', '%n status report', '%n status reports', b.reports.length)}`
		},
		// Farbe folgt dem Bucket wie in der Portfolio-Matrix
		bucketStyle(code) {
			const i = this.data.buckets.findIndex(b => b.code === code)
			return { '--c': i >= 0 && i < BUCKET_COLORS ? `var(--nx-b${i + 1})` : 'var(--color-text-maxcontrast)' }
		},
		bucketName(code) {
			const b = this.data.buckets.find(x => x.code === code)
			return b ? `${b.code} · ${b.name}` : code
		},
		open(b) {
			this.$emit(b.released ? 'project' : 'open', b.id)
		},
	},
}
</script>

<style scoped lang="scss">
.tl {
	position: relative;

	&__plot {
		position: relative;
	}

	svg {
		display: block;
		overflow: visible;
	}

	&__grid {
		stroke: var(--color-border);
		stroke-width: 1;

		&--year {
			stroke: var(--color-border-dark);
		}
	}

	&__tick,
	&__today-lab {
		font-size: 12px;
		fill: var(--color-text-maxcontrast);
	}

	&__today-lab {
		fill: var(--color-error-text);
	}

	&__row {
		cursor: pointer;
		outline: none;
	}

	&__hit {
		fill: transparent;

		&--hover {
			fill: var(--color-background-hover);
		}
	}

	&__row:focus-visible &__hit {
		fill: var(--color-background-hover);
		stroke: var(--color-main-text);
	}

	&__key {
		font-size: 12px;
		font-weight: 600;
		fill: var(--color-text-maxcontrast);
		font-variant-numeric: tabular-nums;
	}

	&__title {
		font-size: 13px;
		fill: var(--color-main-text);
	}

	// Fläche hell in Bucket-Farbe, Rand voll: gestrichelt = geplant, durchgehend = freigegeben
	&__bar {
		fill: var(--c, var(--color-primary-element));
		fill-opacity: 0.35;
		stroke: var(--c, var(--color-primary-element));
		stroke-width: 2;

		&--planned {
			fill-opacity: 0.12;
			stroke-dasharray: 5 3;
		}

		&--paused {
			fill-opacity: 0.08;
		}

		&--neutral {
			--c: var(--color-text-maxcontrast);
		}
	}

	&__report {
		stroke: var(--color-main-text);
		stroke-width: 2;
	}

	&__today {
		stroke: var(--color-error-text);
		stroke-width: 1.5;
		stroke-dasharray: 4 3;
	}

	&__tip {
		position: absolute;
		z-index: 2;
		max-width: 280px;
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

	&__tiprep {
		display: inline-flex;
		align-items: center;
		gap: 3px;
		margin-inline-start: 6px;
	}

	&__dot {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		background: var(--color-text-maxcontrast);

		&--green {
			background: var(--color-success-text);
		}

		&--yellow {
			background: var(--color-warning-text);
		}

		&--red {
			background: var(--color-error-text);
		}
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
}
</style>
