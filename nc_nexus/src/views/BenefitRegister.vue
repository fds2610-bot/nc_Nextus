<template>
	<div class="benefits" data-test="benefit-register">
		<div class="benefits__header">
			<h2>{{ t('nc_nexus', 'Benefit register') }}</h2>
			<span class="benefits__count">{{ n('nc_nexus', '%n benefit item', '%n benefit items', rows.length) }} · {{ t('nc_nexus', '{sum} p. a.', { sum: formatMoney(total) }) }}</span>
		</div>
		<p class="benefits__intro">
			{{ t('nc_nexus', 'The portfolio-wide view of promised benefit: all benefit items of the business cases you may see. The benefit owner stays responsible after the project ends.') }}
		</p>
		<NcEmptyContent v-if="!loading && !rows.length" :name="t('nc_nexus', 'No benefit items yet')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCashMultiple" />
			</template>
		</NcEmptyContent>
		<div v-else class="benefits__table">
			<div class="benefit benefit--head" aria-hidden="true">
				<span>{{ t('nc_nexus', 'Project') }}</span>
				<span>{{ t('nc_nexus', 'Benefit item') }}</span>
				<span>{{ t('nc_nexus', 'Per year') }}</span>
				<span>{{ t('nc_nexus', 'Metric: baseline → target') }}</span>
				<span>{{ t('nc_nexus', 'Benefit owner') }}</span>
			</div>
			<div v-for="(r, i) in rows"
				:key="i"
				class="benefit"
				:data-test="'benefit-row-' + r.demandKey">
				<span class="benefit__demand">
					<a href="#" @click.prevent="$emit('open', r.demandId)">{{ r.demandKey }}</a>
					<small>{{ r.demandTitle }}</small>
				</span>
				<span>
					<strong>{{ r.title }}</strong>
					<small>{{ benefitTypeLabel(r.type) }}</small>
				</span>
				<span class="benefit__amount">{{ r.amount === null ? '–' : formatMoney(r.amount) }}</span>
				<span>
					{{ r.metric }}
					<small>{{ r.baseline }} → {{ r.target || '?' }}</small>
				</span>
				<span>{{ r.ownerUserId }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { mdiCashMultiple } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { benefitRegister, errorMessage } from '../api.js'
import { benefitTypeLabel, formatMoney } from '../labels.js'

export default {
	name: 'BenefitRegister',
	components: { NcEmptyContent, NcIconSvgWrapper },
	props: {
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['open'],
	setup() {
		return { mdiCashMultiple, n, t, benefitTypeLabel, formatMoney }
	},
	data() {
		return { rows: [], loading: true }
	},
	computed: {
		total() {
			return this.rows.reduce((s, r) => s + (r.amount || 0), 0)
		},
	},
	watch: {
		refreshKey: 'load',
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.rows = await benefitRegister()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.benefits {
	padding: 8px 20px 20px 50px;

	&__header {
		display: flex;
		align-items: center;
		gap: 12px;
		min-height: var(--default-clickable-area, 34px);
		margin: 4px 0 8px;

		h2 {
			margin: 0;
			font-size: 20px;
			font-weight: 600;
		}
	}

	&__count,
	&__intro {
		color: var(--color-text-maxcontrast);
	}

	&__intro {
		margin: 0 0 16px;
		font-size: 13px;
	}

	&__table {
		background: var(--color-main-background);
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-container-large, 12px);
		overflow: hidden;
	}
}

.benefit {
	display: grid;
	grid-template-columns: 200px minmax(0, 1fr) 120px minmax(0, 1fr) 130px;
	align-items: center;
	gap: 12px;
	min-height: 52px;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&--head {
		min-height: 0;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
	}

	small {
		display: block;
		overflow: hidden;
		font-size: 12px;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
		text-overflow: ellipsis;
	}

	&__amount {
		font-variant-numeric: tabular-nums;
		text-align: end;
	}
}
</style>
