<template>
	<NxDialog :title="t('nc_nexus', 'Complete analysis: {title}', { title: demand.title })"
		:subtitle="t('nc_nexus', 'The result is added to the comments; the portfolio office then triages the demand again.')"
		data-test="analysis-dialog"
		@close="$emit('close')">
		<label class="nx-field" data-test="field-analysis-result">
			<span>{{ t('nc_nexus', 'Result of the analysis') }} *</span>
			<textarea v-model="result"
				class="nx-input"
				:placeholder="t('nc_nexus', 'e.g. effort estimate, affected systems, recommendation')" />
		</label>
		<template #actions>
			<NcButton variant="tertiary" :disabled="busy" @click="$emit('close')">
				{{ result.trim() ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !result.trim()"
				data-test="save-analysis"
				@click="save">
				{{ t('nc_nexus', 'Complete analysis') }}
			</NcButton>
		</template>
	</NxDialog>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NxDialog from './NxDialog.vue'
import { completeAnalysis, errorMessage } from '../api.js'

export default {
	name: 'AnalysisDialog',
	components: { NcButton, NxDialog },
	props: {
		demand: { type: Object, required: true },
	},
	emits: ['close', 'done'],
	setup() {
		return { t }
	},
	data() {
		return { result: '', busy: false }
	},
	methods: {
		async save() {
			if (!this.result.trim()) {
				return
			}
			this.busy = true
			try {
				const demand = await completeAnalysis(this.demand.id, this.result)
				showSuccess(t('nc_nexus', 'Analysis completed'))
				this.$emit('done', demand)
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
