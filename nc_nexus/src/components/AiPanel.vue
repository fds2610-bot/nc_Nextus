<template>
	<div v-if="ai && ai.enabled" class="nx-section" data-test="ai">
		<h3>{{ t('nc_nexus', 'AI assistance') }}</h3>
		<p class="ai__muted">
			{{ t('nc_nexus', 'The AI only receives the data of this project that you may see. Results are proposals: check them against the data; decisions stay with the responsible people.') }}
		</p>
		<!-- Hinweis am umgebenden Element: deaktivierte Buttons zeigen in manchen Browsern keinen Tooltip -->
		<div class="ai__actions"
			:title="ai.available ? '' : noService"
			data-test="ai-actions">
			<NcButton v-for="kind in ai.kinds"
				:key="kind"
				variant="secondary"
				:disabled="!ai.available || busy || pending(kind)"
				:data-test="'ai-request-' + kind"
				@click="request(kind)">
				{{ kindAction(kind) }}
			</NcButton>
		</div>
		<div v-for="r in ai.results"
			:key="r.taskId"
			class="ai__result"
			:data-test="'ai-result-' + r.kind">
			<div class="ai__head">
				<span class="nx-chip nx-chip--submitted">{{ t('nc_nexus', 'AI proposal') }}</span>
				<b>{{ kindTitle(r.kind) }}</b>
				<span class="ai__muted">{{ formatDate(r.createdAt) }}</span>
			</div>
			<p v-if="r.status === 'scheduled' || r.status === 'running'" class="ai__muted" data-test="ai-pending">
				<NcLoadingIcon :size="16" class="ai__spinner" />
				{{ r.status === 'running' ? t('nc_nexus', 'The AI service is working on it …') : t('nc_nexus', 'Waiting for the AI service. Depending on the setup this can take some minutes; you can close the project meanwhile.') }}
			</p>
			<p v-else-if="r.status === 'failed' || r.status === 'cancelled' || r.status === 'unknown'" class="ai__error" data-test="ai-failed">
				{{ r.error || t('nc_nexus', 'The AI service could not process the request.') }}
			</p>
			<template v-else>
				<div class="ai__text" data-test="ai-text">{{ r.text }}</div>
				<div class="ai__actions">
					<NcButton variant="tertiary" data-test="ai-copy" @click="copy(r.text)">
						{{ t('nc_nexus', 'Copy text') }}
					</NcButton>
				</div>
			</template>
			<details class="ai__basis">
				<summary>{{ t('nc_nexus', 'Data basis') }}</summary>
				<pre data-test="ai-basis">{{ r.basis }}</pre>
			</details>
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { errorMessage, getAi, requestAi } from '../api.js'
import { formatDate } from '../labels.js'

const POLL_MS = 3000

export default {
	name: 'AiPanel',
	components: { NcButton, NcLoadingIcon },
	props: {
		demandId: { type: Number, required: true },
		/** Statuswechsel ändern die möglichen Aufträge */
		status: { type: String, required: true },
	},
	setup() {
		return { t, formatDate }
	},
	data() {
		return { ai: null, busy: false, timer: null }
	},
	computed: {
		noService() {
			return t('nc_nexus', 'No AI service is set up in this Nextcloud. Administrators set one up under Administration settings → Artificial intelligence (e.g. a local model).')
		},
	},
	watch: {
		status: 'load',
	},
	mounted() {
		this.load()
	},
	beforeUnmount() {
		clearTimeout(this.timer)
	},
	methods: {
		async load() {
			clearTimeout(this.timer)
			try {
				this.ai = await getAi(this.demandId)
			} catch (e) {
				this.ai = null
			}
			this.schedulePoll()
		},
		schedulePoll() {
			if (this.ai?.results.some(r => r.status === 'scheduled' || r.status === 'running')) {
				this.timer = setTimeout(this.load, POLL_MS)
			}
		},
		pending(kind) {
			return !!this.ai?.results.some(r => r.kind === kind && (r.status === 'scheduled' || r.status === 'running'))
		},
		async request(kind) {
			this.busy = true
			clearTimeout(this.timer)
			try {
				this.ai = await requestAi(this.demandId, kind)
				this.schedulePoll()
			} catch (e) {
				showError(errorMessage(e))
			} finally {
				this.busy = false
			}
		},
		async copy(text) {
			try {
				await navigator.clipboard.writeText(text)
				showSuccess(t('nc_nexus', 'Copied'))
			} catch (e) {
				showError(t('nc_nexus', 'Copying is not possible in this browser'))
			}
		},
		kindAction(kind) {
			return {
				summary: t('nc_nexus', 'Summarize'),
				contradictions: t('nc_nexus', 'Check for contradictions'),
				proposal: t('nc_nexus', 'Draft decision template'),
			}[kind] || kind
		},
		kindTitle(kind) {
			return {
				summary: t('nc_nexus', 'Summary'),
				contradictions: t('nc_nexus', 'Contradictions'),
				proposal: t('nc_nexus', 'Draft decision template'),
			}[kind] || kind
		},
	},
}
</script>

<style scoped lang="scss">
.ai__muted {
	color: var(--color-text-maxcontrast);
}

.ai__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 6px;
}

.ai__result {
	margin-top: 12px;
	padding: 10px 12px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
}

.ai__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.ai__text {
	white-space: pre-wrap;
	line-height: 1.5;
}

.ai__spinner {
	display: inline-block;
	vertical-align: middle;
}

.ai__error {
	color: var(--color-error-text);
}

.ai__basis {
	margin-top: 8px;

	summary {
		cursor: pointer;
		color: var(--color-text-maxcontrast);
	}

	pre {
		white-space: pre-wrap;
		font-size: 12px;
		max-height: 240px;
		overflow-y: auto;
		background: var(--color-background-dark);
		padding: 8px;
		border-radius: var(--border-radius);
	}
}
</style>
