<template>
	<div class="nx-view" data-test="dashboard-view">
		<div class="nx-view__header">
			<h2>{{ t('nc_nexus', 'Management overview') }}</h2>
			<span class="nx-view__spacer" />
			<span v-if="data" class="dash__stamp">{{ t('nc_nexus', 'As of {time}', { time: formatDate(data.generatedAt) }) }}</span>
		</div>
		<p class="nx-view__hint">
			{{ t('nc_nexus', 'The portfolio on one screen. Each tile leads to the detailed view; entries in the tiles open the demand or project directly.') }}
		</p>

		<p v-if="loading && !data" class="nx-hint">
			{{ t('nc_nexus', 'Loading …') }}
		</p>

		<div v-if="data" class="dash">
			<!-- Portfolio -->
			<section class="dash__tile" :class="'dash__tile--' + levels.portfolio" data-test="tile-portfolio">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiViewGridOutline" :size="20" />
					<h3>{{ t('nc_nexus', 'Portfolio') }}</h3>
					<button type="button"
						class="dash__more"
						data-test="drill-portfolio"
						@click="$emit('navigate', 'portfolio')">
						{{ t('nc_nexus', 'Details') }} ›
					</button>
				</header>
				<div class="dash__figures">
					<div class="dash__fig">
						<span class="dash__num" data-test="portfolio-active">{{ p.active }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'active') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ p.planned }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'planned') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ p.phases.demand }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'in intake') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ p.onHold + p.cancelled }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'on hold / stopped') }}</span>
					</div>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Status of running projects') }}</span>
					<span class="dash__chips" data-test="portfolio-rag">
						<span v-for="rag in ['green', 'yellow', 'red']"
							:key="rag"
							class="nx-chip"
							:class="ragChip(rag)">{{ p.rag[rag] }} {{ ragLabel(rag) }}</span>
						<span v-if="p.rag.none" class="nx-chip nx-chip--draft">{{ p.rag.none }} {{ t('nc_nexus', 'no report') }}</span>
					</span>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Strategic coverage') }}</span>
					<span data-test="portfolio-coverage">{{ p.objectives ? t('nc_nexus', '{covered} of {total} objectives backed by demands', { covered: p.objectivesCovered, total: p.objectives }) : t('nc_nexus', 'no strategic objectives') }}</span>
				</div>
				<ul v-if="p.uncovered.length" class="dash__list">
					<li v-for="o in p.uncovered" :key="o.id">
						<button type="button" class="dash__item" @click="$emit('navigate', 'objectives')">
							<span class="dash__key">{{ o.code }}</span>
							<span class="dash__title">{{ o.title }}</span>
							<span class="dash__val">{{ t('nc_nexus', 'not covered') }}</span>
						</button>
					</li>
				</ul>
			</section>

			<!-- Finanzen -->
			<section class="dash__tile" :class="'dash__tile--' + levels.finance" data-test="tile-finance">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiCashMultiple" :size="20" />
					<h3>{{ t('nc_nexus', 'Finances') }}</h3>
					<button type="button"
						class="dash__more"
						data-test="drill-finance"
						@click="$emit('navigate', 'projects')">
						{{ t('nc_nexus', 'Details') }} ›
					</button>
				</header>
				<div class="dash__figures dash__figures--3">
					<div class="dash__fig">
						<span class="dash__num dash__num--money">{{ formatMoney(f.frame) }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'Budget') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num dash__num--money">{{ formatMoney(f.actual) }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'Actual') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num dash__num--money" data-test="finance-forecast">{{ formatMoney(f.forecast) }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'Forecast') }}</span>
					</div>
				</div>
				<div v-if="f.frame > 0" class="dash__bar" :title="t('nc_nexus', 'Actual and forecast against the budget')">
					<span class="dash__bar-fill" :style="{ width: Math.min(100, f.actual / f.frame * 100) + '%' }" />
					<span class="dash__bar-mark" :style="{ left: Math.min(100, f.forecast / f.frame * 100) + '%' }" />
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ n('nc_nexus', '%n running project', '%n running projects', f.projects) }}</span>
					<span data-test="finance-deviation">{{ f.deviationPct === null ? '–' : t('nc_nexus', 'Forecast {pct} against budget', { pct: signedPct(f.deviationPct) }) }}</span>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Over budget (from {pct} %)', { pct: formatNumber(f.threshold) }) }}</span>
					<span data-test="finance-over">{{ f.overBudget }}</span>
				</div>
				<ul v-if="f.top.length" class="dash__list">
					<li v-for="x in f.top" :key="x.id">
						<button type="button" class="dash__item" @click="$emit('project', x.id)">
							<span class="dash__key">{{ x.key }}</span>
							<span class="dash__title">{{ x.title }}</span>
							<span class="dash__val dash__val--bad">{{ signedPct(x.deviationPct) }}</span>
						</button>
					</li>
				</ul>
			</section>

			<!-- Kapazität -->
			<section class="dash__tile" :class="'dash__tile--' + levels.capacity" data-test="tile-capacity">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiSpeedometer" :size="20" />
					<h3>{{ t('nc_nexus', 'Capacity') }}</h3>
					<button type="button"
						class="dash__more"
						data-test="drill-capacity"
						@click="$emit('navigate', 'capacity')">
						{{ t('nc_nexus', 'Details') }} ›
					</button>
				</header>
				<div class="dash__figures">
					<div class="dash__fig">
						<span class="dash__num" data-test="capacity-bottlenecks">{{ c.bottlenecks }}</span>
						<span class="dash__lab">{{ n('nc_nexus', 'overbooked bottleneck pool', 'overbooked bottleneck pools', c.bottlenecks) }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ c.cut.startable + c.cut.shifted }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'can start') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num" data-test="capacity-waiting">{{ c.cut.waiting }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'waiting for capacity') }}</span>
					</div>
				</div>
				<p class="dash__note">
					{{ t('nc_nexus', 'Load of the bottleneck pools over the next {months} months', { months: c.months }) }}
				</p>
				<p v-if="!c.pools.length" class="nx-hint">
					{{ t('nc_nexus', 'No bottleneck pool defined.') }}
				</p>
				<ul class="dash__list">
					<li v-for="pool in c.pools" :key="pool.id" :data-test="'capacity-pool-' + pool.code">
						<button type="button" class="dash__item dash__item--pool" @click="$emit('navigate', 'capacity')">
							<span class="dash__key">{{ pool.code }}</span>
							<span class="dash__title">{{ pool.name }}</span>
							<span class="dash__gauge" :title="t('nc_nexus', 'Average {avg} %, peak {peak} %', { avg: pool.average, peak: pool.peak })">
								<span class="dash__gauge-fill" :class="'dash__gauge-fill--' + loadClass(pool.peak)" :style="{ width: Math.min(100, pool.peak / 1.5) + '%' }" />
							</span>
							<span class="dash__val" :class="{ 'dash__val--bad': pool.peak > 100 }">{{ t('nc_nexus', 'peak {peak} %', { peak: pool.peak }) }}</span>
						</button>
					</li>
				</ul>
			</section>

			<!-- Risiken -->
			<section class="dash__tile" :class="'dash__tile--' + levels.risks" data-test="tile-risks">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiAlertOutline" :size="20" />
					<h3>{{ t('nc_nexus', 'Risks') }}</h3>
					<button type="button"
						class="dash__more"
						data-test="drill-risks"
						@click="$emit('navigate', 'risks')">
						{{ t('nc_nexus', 'Details') }} ›
					</button>
				</header>
				<div class="dash__figures dash__figures--3">
					<div class="dash__fig">
						<span class="dash__num" data-test="risks-open">{{ r.open }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'open') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ r.critical }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'critical (score ≥ {s})', { s: r.threshold }) }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num" data-test="risks-escalated">{{ r.escalated }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'escalated') }}</span>
					</div>
				</div>
				<div v-if="r.escalationHints" class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Escalation recommended') }}</span>
					<span class="dash__val--bad">{{ r.escalationHints }}</span>
				</div>
				<p v-if="!r.top.length" class="nx-hint">
					{{ t('nc_nexus', 'No escalated or cross-project risks.') }}
				</p>
				<ul class="dash__list">
					<li v-for="x in r.top" :key="x.id">
						<button type="button" class="dash__item" @click="x.project ? $emit('project', x.project.id) : $emit('navigate', 'risks')">
							<span class="dash__key">{{ x.project ? x.project.key : '' }}</span>
							<span class="dash__title">{{ x.title }}</span>
							<span class="dash__val" :class="{ 'dash__val--bad': x.score >= r.threshold }">{{ x.escalated ? t('nc_nexus', 'escalated') + ' · ' : '' }}{{ t('nc_nexus', 'score {s}', { s: x.score }) }}</span>
						</button>
					</li>
				</ul>
			</section>

			<!-- Entscheidungen -->
			<section class="dash__tile" :class="'dash__tile--' + levels.decisions" data-test="tile-decisions">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiGavel" :size="20" />
					<h3>{{ t('nc_nexus', 'Decisions') }}</h3>
					<button type="button"
						class="dash__more"
						data-test="drill-decisions"
						@click="$emit('navigate', 'conditions')">
						{{ t('nc_nexus', 'Details') }} ›
					</button>
				</header>
				<div class="dash__figures">
					<div class="dash__fig">
						<span class="dash__num" data-test="decisions-pending">{{ d.pending }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'awaiting decision') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ d.oldestDays === null ? '–' : d.oldestDays }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'days, oldest') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num">{{ d.openActions }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'open follow-up actions') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num" :class="{ 'dash__val--bad': d.overdueActions }" data-test="decisions-overdue">{{ d.overdueActions }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'overdue') }}</span>
					</div>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Triage / gates') }}</span>
					<span>{{ d.triage }} / {{ d.gates }}{{ d.medianDays === null ? '' : ' · ' + t('nc_nexus', 'median {d} days', { d: d.medianDays }) }}</span>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Next session') }}</span>
					<button v-if="d.nextSession"
						type="button"
						class="dash__link"
						data-test="decisions-next-session"
						@click="$emit('navigate', 'sessions')">
						{{ formatDay(d.nextSession.scheduledAt) }} · {{ d.nextSession.bodyName }} · {{ n('nc_nexus', '%n item', '%n items', d.nextSession.items) }}
					</button>
					<span v-else>{{ t('nc_nexus', 'none planned') }}</span>
				</div>
				<ul class="dash__list">
					<li v-for="x in d.oldest" :key="x.kind + x.id + (x.gate || '')">
						<button type="button" class="dash__item" @click="$emit('open', x.id)">
							<span class="dash__key">{{ x.key }}</span>
							<span class="dash__title">{{ x.title }}</span>
							<span class="dash__val" :class="{ 'dash__val--bad': x.ageDays > 30 }">{{ x.gate ? x.gate : t('nc_nexus', 'Triage') }} · {{ n('nc_nexus', '%n day', '%n days', x.ageDays) }}</span>
						</button>
					</li>
				</ul>
			</section>

			<!-- Nutzen -->
			<section class="dash__tile" :class="'dash__tile--' + levels.benefits" data-test="tile-benefits">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiCashCheck" :size="20" />
					<h3>{{ t('nc_nexus', 'Benefits') }}</h3>
					<button type="button"
						class="dash__more"
						data-test="drill-benefits"
						@click="$emit('navigate', 'benefits')">
						{{ t('nc_nexus', 'Details') }} ›
					</button>
				</header>
				<div class="dash__figures dash__figures--3">
					<div class="dash__fig">
						<span class="dash__num dash__num--money">{{ formatMoney(b.committed) }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'planned') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num dash__num--money">{{ formatMoney(b.forecast) }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'forecast') }}</span>
					</div>
					<div class="dash__fig">
						<span class="dash__num dash__num--money" data-test="benefits-realized">{{ formatMoney(b.realized) }}</span>
						<span class="dash__lab">{{ t('nc_nexus', 'realized') }}</span>
					</div>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Realization of measured positions') }}</span>
					<span>{{ b.realizationPct === null ? t('nc_nexus', 'not measured yet') : b.realizationPct + ' %' }}</span>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ n('nc_nexus', '%n committed position', '%n committed positions', b.positions) }}</span>
					<span class="dash__chips">
						<span v-for="(count, s) in b.statuses"
							v-show="count"
							:key="s"
							class="nx-chip"
							:class="realizationStatusChip(s)">{{ count }} {{ realizationStatusLabel(s) }}</span>
					</span>
				</div>
				<div class="dash__row">
					<span class="dash__rowlab">{{ t('nc_nexus', 'Benefit reviews due') }}</span>
					<span data-test="benefits-due">{{ b.reviewsDue }}</span>
				</div>
				<ul v-if="b.due.length" class="dash__list">
					<li v-for="x in b.due" :key="x.demandId + x.nextReview">
						<button type="button" class="dash__item" @click="$emit('navigate', 'benefits')">
							<span class="dash__key">{{ x.key }}</span>
							<span class="dash__title">{{ x.title }}</span>
							<span class="dash__val">{{ formatIsoDay(x.nextReview) }}</span>
						</button>
					</li>
				</ul>
			</section>

			<!-- Portfolio-Matrix: Reifegrad × Aufwand, Fläche = Kosten -->
			<section class="dash__tile dash__tile--wide" data-test="tile-maturity">
				<header class="dash__head">
					<NcIconSvgWrapper :path="mdiChartBubble" :size="20" />
					<h3>{{ t('nc_nexus', 'Portfolio matrix') }}</h3>
				</header>
				<p class="dash__note">
					{{ t('nc_nexus', 'Every active demand and project as a circle: to the right the more mature (by status), upwards the larger (effort in person days), circle area by cost. Dashed lines: release at G3 and the class boundary M.') }}
				</p>
				<p v-if="!data.maturity.points.length" class="nx-hint">
					{{ t('nc_nexus', 'No active demands with an effort estimate yet.') }}
				</p>
				<MaturityChart v-else
					:data="data.maturity"
					@open="id => $emit('open', id)"
					@project="id => $emit('project', id)" />
			</section>
		</div>
	</div>
</template>

<script>
import { mdiAlertOutline, mdiChartBubble, mdiCashCheck, mdiCashMultiple, mdiGavel, mdiSpeedometer, mdiViewGridOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import MaturityChart from '../components/MaturityChart.vue'
import { errorMessage, managementDashboard } from '../api.js'
import { formatDate, formatDay, formatIsoDay, formatMoney, formatNumber, ragChip, ragLabel, realizationStatusChip, realizationStatusLabel } from '../labels.js'

export default {
	name: 'DashboardView',
	components: { MaturityChart, NcIconSvgWrapper },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['navigate', 'project', 'open'],
	setup() {
		return {
			mdiAlertOutline,
			mdiChartBubble,
			mdiCashCheck,
			mdiCashMultiple,
			mdiGavel,
			mdiSpeedometer,
			mdiViewGridOutline,
			n,
			t,
			formatDate,
			formatDay,
			formatIsoDay,
			formatMoney,
			formatNumber,
			ragChip,
			ragLabel,
			realizationStatusChip,
			realizationStatusLabel,
		}
	},
	data() {
		return { data: null, loading: true }
	},
	computed: {
		p() { return this.data.portfolio },
		f() { return this.data.finance },
		c() { return this.data.capacity },
		r() { return this.data.risks },
		d() { return this.data.decisions },
		b() { return this.data.benefits },
		// Ampel je Kachel: rot = handeln, gelb = beobachten
		levels() {
			const { p, f, c, r, d, b } = this
			return {
				portfolio: p.rag.red ? 'red' : (p.rag.yellow || p.uncovered.length ? 'yellow' : 'green'),
				finance: f.deviationPct !== null && f.deviationPct >= f.threshold ? 'red' : (f.overBudget ? 'yellow' : 'green'),
				capacity: c.pools.some(x => x.peak > 100) ? 'red' : (c.pools.some(x => x.peak >= 80) || c.cut.waiting ? 'yellow' : 'green'),
				risks: r.escalationHints ? 'red' : (r.critical || r.escalated ? 'yellow' : 'green'),
				decisions: d.overdueActions || (d.oldestDays ?? 0) > 30 ? 'red' : (d.pending ? 'yellow' : 'green'),
				benefits: b.realizationPct !== null && b.realizationPct < 50 ? 'red' : (b.reviewsDue || (b.realizationPct !== null && b.realizationPct < 90) ? 'yellow' : 'green'),
			}
		},
	},
	watch: {
		refreshKey() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			this.loading = true
			try {
				this.data = await managementDashboard()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
		signedPct(v) {
			return (v > 0 ? '+' : '') + formatNumber(v) + ' %'
		},
		loadClass(load) {
			return load > 100 ? 'over' : (load >= 80 ? 'high' : 'ok')
		},
	},
}
</script>

<style scoped lang="scss">
.dash {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 16px;

	&__stamp {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__tile {
		display: flex;
		flex-direction: column;
		gap: 10px;
		min-width: 0;
		padding: 14px 16px 12px;
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		// Ampel als kräftiger Balken innen an der Oberkante (ein farbiger Rand verschwindet in der Rundung)
		box-shadow: inset 0 5px 0 var(--dash-level, var(--color-border));
		padding-top: 18px;

		&--wide {
			grid-column: 1 / -1;
		}

		&--green {
			--dash-level: var(--color-success-text);
		}

		&--yellow {
			--dash-level: var(--color-warning-text);
		}

		&--red {
			--dash-level: var(--color-error-text);
		}
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;

		h3 {
			flex: 1;
			margin: 0;
			font-size: 16px;
			font-weight: 700;
		}
	}

	&__more,
	&__link {
		padding: 2px 8px;
		border: none;
		border-radius: var(--border-radius-element, 8px);
		background: none;
		color: var(--color-primary-element);
		font: inherit;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__link {
		padding: 0 4px;
		text-align: end;
	}

	&__figures {
		display: grid;
		grid-template-columns: repeat(4, minmax(0, 1fr));
		gap: 8px;

		&--3 {
			grid-template-columns: repeat(3, minmax(0, 1fr));
		}
	}

	&__fig {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__num {
		font-size: 24px;
		font-weight: 700;
		line-height: 1.2;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;

		&--money {
			font-size: 18px;
		}
	}

	&__lab,
	&__note {
		font-size: 13px;
		color: var(--color-text-maxcontrast);
	}

	&__note {
		margin: 0;
	}

	&__row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: 4px 10px;
		font-size: 14px;
	}

	&__rowlab {
		color: var(--color-text-maxcontrast);
	}

	&__chips {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
	}

	&__bar {
		position: relative;
		height: 8px;
		border-radius: 4px;
		background: var(--color-background-dark);
	}

	&__bar-fill {
		position: absolute;
		inset: 0 auto 0 0;
		border-radius: 4px;
		background: var(--color-primary-element);
	}

	&__bar-mark {
		position: absolute;
		top: -3px;
		bottom: -3px;
		width: 3px;
		margin-left: -1px;
		background: var(--color-main-text);
	}

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
		border-top: 1px solid var(--color-border);
	}

	&__item {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr) auto;
		align-items: center;
		gap: 10px;
		width: 100%;
		padding: 5px 4px;
		border: none;
		border-radius: var(--border-radius-element, 8px);
		background: none;
		color: var(--color-main-text);
		font: inherit;
		font-size: 14px;
		text-align: start;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}

		&--pool {
			grid-template-columns: auto minmax(0, 1fr) 90px auto;
		}
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

	&__val {
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		font-variant-numeric: tabular-nums;

		&--bad {
			color: var(--color-error-text);
			font-weight: 600;
		}
	}

	&__gauge {
		position: relative;
		height: 8px;
		border-radius: 4px;
		background: var(--color-background-dark);
		overflow: hidden;
	}

	// Markierung bei 100 % (die Leiste reicht bis 150 %)
	&__gauge::after {
		content: "";
		position: absolute;
		top: 0;
		bottom: 0;
		left: 66.7%;
		width: 2px;
		background: var(--color-main-text);
		opacity: 0.5;
	}

	&__gauge-fill {
		position: absolute;
		inset: 0 auto 0 0;

		&--ok {
			background: var(--color-success-text);
		}

		&--high {
			background: var(--color-warning-text);
		}

		&--over {
			background: var(--color-error-text);
		}
	}
}
</style>
