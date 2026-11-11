<template>
	<NxDialog :title="session ? session.title : t('nc_nexus', 'New session')"
		:subtitle="t('nc_nexus', 'A session collects the gates that are ready for decision. Members see the agenda once it is final.')"
		data-test="session-dialog"
		@ready="$emit('ready', $event)"
		@close="$emit('close')">
		<label class="nx-field" data-test="field-session-body">
			<span>{{ t('nc_nexus', 'Body') }} *</span>
			<select v-model="form.bodyId" class="nx-input" :disabled="!!session">
				<option v-for="b in bodies" :key="b.id" :value="b.id">{{ b.name }}</option>
			</select>
		</label>
		<label class="nx-field" data-test="field-session-title">
			<span>{{ t('nc_nexus', 'Title') }}</span>
			<input v-model="form.title" class="nx-input" :placeholder="t('nc_nexus', 'Empty: body and date')">
		</label>
		<div class="nx-field-row">
			<label class="nx-field" data-test="field-session-when">
				<span>{{ t('nc_nexus', 'Date and time') }} *</span>
				<input v-model="form.scheduledAt" type="datetime-local" class="nx-input">
			</label>
			<label class="nx-field">
				<span>{{ t('nc_nexus', 'Duration (minutes)') }}</span>
				<NxNumberInput v-model="form.durationMin"
					:min="15"
					:max="600"
					:max-decimals="0"
					class="nx-input" />
			</label>
		</div>
		<label class="nx-field">
			<span>{{ t('nc_nexus', 'Location') }}</span>
			<input v-model="form.location" class="nx-input">
		</label>
		<template v-if="!session">
			<label v-if="collab.calendar" class="session__opt" data-test="field-session-calendar">
				<input v-model="form.calendar" type="checkbox">
				<span>{{ t('nc_nexus', 'Create a calendar event and invite the members') }}</span>
			</label>
			<label v-if="collab.talk" class="session__opt" data-test="field-session-talk">
				<input v-model="form.talk" type="checkbox">
				<span>{{ t('nc_nexus', 'Create a Talk conversation for the session') }}</span>
			</label>
		</template>
		<template #actions>
			<NcButton :variant="dirty ? 'tertiary' : 'primary'" :disabled="busy" @click="$emit('close')">
				{{ dirty ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
			</NcButton>
			<NcButton variant="primary"
				:disabled="busy || !form.bodyId || !form.scheduledAt || (session && !dirty)"
				data-test="save-session"
				@click="save">
				{{ session ? t('nc_nexus', 'Save') : t('nc_nexus', 'Create') }}
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
import { createSession, errorMessage, updateSession } from '../api.js'

const local = (ts) => {
	const d = new Date(ts * 1000)
	const pad = (v) => String(v).padStart(2, '0')
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export default {
	name: 'SessionDialog',
	components: { NcButton, NxDialog, NxNumberInput },
	props: {
		session: { type: Object, default: null },
		bodies: { type: Array, required: true },
		collab: { type: Object, default: () => ({ talk: false, calendar: false }) },
	},
	emits: ['close', 'saved', 'ready'],
	setup() {
		return { t }
	},
	data() {
		const form = this.session
			? { bodyId: this.session.bodyId, title: this.session.title, scheduledAt: local(this.session.scheduledAt), durationMin: this.session.durationMin, location: this.session.location ?? '', calendar: false, talk: false }
			: { bodyId: this.bodies[0]?.id ?? null, title: '', scheduledAt: '', durationMin: 90, location: '', calendar: this.collab.calendar, talk: this.collab.talk }
		return { busy: false, form, saved: JSON.stringify(form) }
	},
	computed: {
		dirty() {
			return JSON.stringify(this.form) !== this.saved
		},
	},
	methods: {
		async save() {
			this.busy = true
			try {
				// Ortszeit des Browsers als ISO mit Zeitzone, damit der Server den richtigen Zeitpunkt speichert
				const offset = -new Date(this.form.scheduledAt).getTimezoneOffset()
				const sign = offset >= 0 ? '+' : '-'
				const tz = sign + String(Math.floor(Math.abs(offset) / 60)).padStart(2, '0') + ':' + String(Math.abs(offset) % 60).padStart(2, '0')
				const values = { ...this.form, scheduledAt: this.form.scheduledAt + ':00' + tz }
				const session = this.session ? await updateSession(this.session.id, values) : await createSession(values)
				showSuccess(this.session ? t('nc_nexus', 'Session saved') : t('nc_nexus', 'Session created'))
				this.$emit('saved', session)
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
.session__opt {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 4px 0;
}
</style>
