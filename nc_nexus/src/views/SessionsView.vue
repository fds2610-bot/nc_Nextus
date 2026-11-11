<template>
	<div class="nx-view" data-test="sessions-view">
		<!-- Liste -->
		<template v-if="!current">
			<div class="nx-view__header">
				<h2>{{ t('nc_nexus', 'Sessions') }}</h2>
				<span class="nx-view__count">{{ n('nc_nexus', '%n session', '%n sessions', sessions.length) }}</span>
				<span class="nx-view__spacer" />
				<NcButton v-if="canManage && bodies.length"
					variant="primary"
					data-test="new-session"
					@click="dialog = { session: null }">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
					{{ t('nc_nexus', 'New session') }}
				</NcButton>
			</div>
			<p v-if="canManage && !bodies.length" class="nx-view__hint">
				{{ t('nc_nexus', 'Create a body under Governance first.') }}
			</p>
			<p v-if="!sessions.length" class="nx-view__empty">
				{{ t('nc_nexus', 'No sessions yet.') }}
			</p>
			<div v-else class="nx-view__table">
				<div v-for="s in sessions"
					:key="s.id"
					class="nx-row nx-row--click ses__row"
					:data-test="'session-' + s.id"
					@click="open(s.id)">
					<NcIconSvgWrapper :path="mdiCalendarAccount" />
					<div class="nx-row__name">
						<span class="nx-row__title">{{ s.title }}</span>
						<span class="nx-row__meta">{{ s.bodyName }}{{ s.location ? ' · ' + s.location : '' }}</span>
					</div>
					<span class="nx-row__meta">{{ formatDate(s.scheduledAt) }}</span>
					<span class="nx-row__meta">{{ n('nc_nexus', '%n item', '%n items', s.items) }}</span>
					<span class="nx-chip" :class="statusChip(s.status)">{{ sessionStatusLabel(s.status) }}</span>
				</div>
			</div>
		</template>

		<!-- Sitzungsmappe -->
		<template v-else>
			<div class="nx-view__header">
				<NcButton variant="tertiary"
					:aria-label="t('nc_nexus', 'Back')"
					data-test="session-back"
					@click="close">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowLeft" />
					</template>
				</NcButton>
				<h2 data-test="session-title">
					{{ current.title }}
				</h2>
				<span class="nx-chip" :class="statusChip(current.status)" data-test="session-status">{{ sessionStatusLabel(current.status) }}</span>
				<span class="nx-view__spacer" />
				<NcButton v-if="current.can.edit"
					variant="tertiary"
					data-test="edit-session"
					@click="dialog = { session: current }">
					{{ t('nc_nexus', 'Edit') }}
				</NcButton>
				<NcButton v-if="current.can.delete"
					variant="tertiary"
					data-test="delete-session"
					@click="remove">
					{{ t('nc_nexus', 'Delete') }}
				</NcButton>
				<NcButton v-if="current.can.finalize"
					variant="secondary"
					data-test="finalize-session"
					:disabled="busy || !current.items.length"
					@click="setStatus('agenda_final')">
					{{ t('nc_nexus', 'Finalize agenda') }}
				</NcButton>
				<NcButton v-if="current.can.start"
					:variant="current.status === 'agenda_final' ? 'primary' : 'secondary'"
					data-test="start-session"
					:disabled="busy"
					@click="setStatus('held')">
					{{ t('nc_nexus', 'Start session') }}
				</NcButton>
				<NcButton v-if="current.can.close"
					variant="primary"
					data-test="close-session"
					:disabled="busy"
					@click="minutesOpen = true">
					{{ t('nc_nexus', 'Close and minute') }}
				</NcButton>
			</div>
			<div class="nx-summary__facts ses__facts">
				<div class="nx-fact">
					{{ t('nc_nexus', 'Body') }}<b>{{ current.body.name }}</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'When') }}<b>{{ formatDate(current.scheduledAt) }} · {{ current.durationMin }} min</b>
				</div>
				<div v-if="current.location" class="nx-fact">
					{{ t('nc_nexus', 'Location') }}<b>{{ current.location }}</b>
				</div>
				<div class="nx-fact">
					{{ t('nc_nexus', 'Quorum') }}<b>{{ current.body.quorumMin }}{{ current.body.requiredSeats.length ? ' · ' + current.body.requiredSeats.join(', ') : '' }}</b>
				</div>
				<div v-if="current.talkUrl" class="nx-fact">
					Talk<b><a :href="current.talkUrl"
						target="_blank"
						rel="noopener"
						data-test="session-talk">{{ t('nc_nexus', 'Join conversation') }}</a></b>
				</div>
				<div v-if="current.calendarUid" class="nx-fact">
					{{ t('nc_nexus', 'Calendar') }}<b>{{ t('nc_nexus', 'Event created') }}</b>
				</div>
			</div>
			<p v-if="current.minutes" class="ses__minutes" data-test="session-minutes">
				<b>{{ t('nc_nexus', 'Minutes') }}:</b> {{ current.minutes }}
			</p>

			<section class="nx-view__section">
				<div class="nx-view__section-head">
					<h3>{{ t('nc_nexus', 'Agenda') }}</h3>
					<div v-if="current.can.edit" class="ses__agenda-actions">
						<NcButton variant="secondary"
							data-test="generate-agenda"
							:disabled="busy || !current.readyCount"
							@click="agenda">
							{{ n('nc_nexus', 'Add %n gate ready for decision', 'Add %n gates ready for decision', current.readyCount) }}
						</NcButton>
						<NcButton variant="tertiary" data-test="add-topic" @click="topicOpen = !topicOpen">
							{{ t('nc_nexus', 'Add topic') }}
						</NcButton>
					</div>
				</div>
				<div v-if="topicOpen" class="ses__topic">
					<input v-model="topic"
						class="nx-input"
						:placeholder="t('nc_nexus', 'Topic, e.g. capacity report')"
						data-test="topic-title"
						@keydown.enter="addTopicItem">
					<NcButton variant="secondary" :disabled="!topic.trim()" @click="addTopicItem">
						{{ t('nc_nexus', 'Add') }}
					</NcButton>
				</div>
				<p v-if="!current.items.length" class="nx-view__empty">
					{{ t('nc_nexus', 'The agenda is empty. Gates are added once the portfolio office has submitted them to this body.') }}
				</p>
				<article v-for="(item, i) in current.items"
					:key="item.id"
					class="ses__item"
					:data-test="'item-' + i">
					<header class="ses__item-head">
						<span class="ses__pos">{{ i + 1 }}</span>
						<div class="nx-row__name">
							<span class="nx-row__title">{{ item.title }}</span>
							<span v-if="item.brief" class="nx-row__meta">
								{{ item.brief.key }} · {{ statusLabel(item.brief.status) }}{{ item.brief.projectClass ? ' · ' + t('nc_nexus', 'Class {c}', { c: item.brief.projectClass }) : '' }} · {{ item.brief.requestorId }}
							</span>
						</div>
						<span class="nx-chip" :class="itemChip(item)" data-test="item-status">{{ itemStatus(item) }}</span>
						<div v-if="current.can.edit && item.status !== 'decided'" class="ses__item-tools">
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Move up')"
								:disabled="i === 0"
								@click="move(item, -1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowUp" />
								</template>
							</NcButton>
							<NcButton variant="tertiary"
								:aria-label="t('nc_nexus', 'Move down')"
								:disabled="i === current.items.length - 1"
								@click="move(item, 1)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiArrowDown" />
								</template>
							</NcButton>
							<NcButton variant="tertiary" :aria-label="t('nc_nexus', 'Remove from agenda')" @click="removeItem(item)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiClose" />
								</template>
							</NcButton>
						</div>
					</header>

					<!-- Entscheidungsvorlage auf einer Bildschirmseite -->
					<div v-if="item.brief" class="ses__brief" data-test="item-brief">
						<div class="ses__brief-text">
							<p v-if="item.brief.problemStatement">
								<b>{{ t('nc_nexus', 'Problem') }}:</b> {{ item.brief.problemStatement }}
							</p>
							<p v-if="item.brief.expectedBenefit">
								<b>{{ t('nc_nexus', 'Expected benefit') }}:</b> {{ item.brief.expectedBenefit }}
							</p>
							<div v-if="item.brief.change" class="ses__change" data-test="item-change">
								<p><b>{{ t('nc_nexus', 'Change request') }}:</b> {{ item.brief.change.title }} · {{ changeTypeLabel(item.brief.change.changeType) }}</p>
								<p>
									<template v-if="item.brief.change.deltaBudget">
										{{ formatMoney(item.brief.change.deltaBudget) }} ·
									</template>
									<template v-if="item.brief.change.deltaDays">
										{{ t('nc_nexus', '{n} days', { n: item.brief.change.deltaDays }) }} ·
									</template>
									{{ (item.brief.change.thresholdReasons || []).map(thresholdReasonLabel).join(', ') }}
								</p>
								<p v-if="item.brief.change.impactAnalysis">
									<b>{{ t('nc_nexus', 'Impact analysis') }}:</b> {{ item.brief.change.impactAnalysis }}
								</p>
							</div>
							<ul v-if="item.brief.checks.length" class="ses__checks">
								<li v-for="c in item.brief.checks" :key="c.key" :class="c.ok ? 'ses__ok' : 'ses__fail'">
									{{ c.ok ? '✓' : '✗' }} {{ checkLabel(c.key) }}
								</li>
							</ul>
							<p v-if="item.brief.conditions.length" class="ses__open-cond">
								{{ n('nc_nexus', '%n open condition', '%n open conditions', item.brief.conditions.length) }}:
								{{ item.brief.conditions.map(c => c.title).join(' · ') }}
							</p>
						</div>
						<div class="ses__brief-facts nx-summary__facts">
							<div v-if="item.brief.kpis" class="nx-fact">
								{{ t('nc_nexus', 'One-time cost') }}<b>{{ formatMoney(item.brief.kpis.oneTimeCost) }}</b>
							</div>
							<div v-if="item.brief.kpis" class="nx-fact">
								{{ t('nc_nexus', 'Benefit p. a.') }}<b>{{ formatMoney(item.brief.kpis.benefitPerYear) }}</b>
							</div>
							<div v-if="item.brief.kpis && item.brief.kpis.npv !== null" class="nx-fact">
								{{ t('nc_nexus', 'Net present value') }}<b>{{ formatMoney(item.brief.kpis.npv) }}</b>
							</div>
							<div v-if="item.brief.rank" class="nx-fact">
								{{ t('nc_nexus', 'Rank') }}<b>{{ item.brief.rank }}</b>
							</div>
						</div>
					</div>

					<div v-if="item.gateCode" class="ses__recommendation">
						<label v-if="current.can.edit && item.status === 'open'" class="nx-field" data-test="item-recommendation">
							<span>{{ t('nc_nexus', 'Recommendation of the portfolio office') }}</span>
							<textarea v-model="drafts[item.id]"
								class="nx-input"
								@blur="saveRecommendation(item)" />
						</label>
						<p v-else-if="item.recommendation">
							<b>{{ t('nc_nexus', 'Recommendation') }}:</b> {{ item.recommendation }}
						</p>
						<div class="ses__options">
							<span class="nx-row__meta">{{ t('nc_nexus', 'Options') }}:</span>
							<span v-for="o in item.options" :key="o" class="ses__option">{{ gateOutcomeLabel(item.gateCode, o) }}</span>
						</div>
					</div>

					<!-- Abstimmung -->
					<div v-if="item.gateCode" class="ses__votes" data-test="item-votes">
						<div class="ses__tally" :class="{ 'ses__tally--ok': item.quorum.ok && !item.quorum.missingSeats.length }" data-test="item-quorum">
							{{ t('nc_nexus', 'Yes {yes} · No {no} · Abstain {abstain}', item.quorum.tally) }}
							<template v-if="item.quorum.tally.conflict">
								· {{ n('nc_nexus', '%n conflict of interest', '%n conflicts of interest', item.quorum.tally.conflict) }}
							</template>
							· {{ t('nc_nexus', 'Quorum {present}/{required}', { present: item.quorum.present, required: item.quorum.required }) }}
							<template v-if="item.quorum.missingSeats.length">
								· {{ t('nc_nexus', 'missing seats: {seats}', { seats: item.quorum.missingSeats.join(', ') }) }}
							</template>
						</div>
						<ul class="ses__vote-list">
							<li v-for="m in votingMembers" :key="m.userId">
								<NcAvatar :user="m.userId" :size="22" :disable-menu="true" />
								<span>{{ m.userId }}<small v-if="m.seat"> · {{ m.seat }}</small></span>
								<b :class="'ses__vote--' + (voteOf(item, m.userId)?.vote ?? 'none')">
									{{ voteText(voteOf(item, m.userId)) }}
								</b>
								<small v-if="voteOf(item, m.userId) && voteOf(item, m.userId).castBy !== m.userId">
									({{ t('nc_nexus', 'by deputy {user}', { user: voteOf(item, m.userId).castBy }) }})
								</small>
							</li>
						</ul>
						<div v-for="memberId in item.can.voteFor"
							:key="memberId"
							class="ses__my-vote"
							:data-test="'vote-as-' + memberId">
							<span>{{ memberId === me ? t('nc_nexus', 'Your vote') : t('nc_nexus', 'Vote as deputy for {user}', { user: memberId }) }}:</span>
							<NcButton v-for="v in votes"
								:key="v"
								:variant="voteOf(item, memberId)?.vote === v && !voteOf(item, memberId)?.conflict ? 'primary' : 'secondary'"
								:data-test="'vote-' + v"
								:disabled="busy"
								@click="vote(item, memberId, v, false)">
								{{ voteLabel(v) }}
							</NcButton>
							<NcButton :variant="voteOf(item, memberId)?.conflict ? 'primary' : 'tertiary'"
								data-test="vote-conflict"
								:disabled="busy"
								@click="vote(item, memberId, 'abstain', true)">
								{{ t('nc_nexus', 'Conflict of interest') }}
							</NcButton>
						</div>
					</div>

					<footer v-if="item.decision || item.can.decide || item.can.adjourn" class="ses__item-foot">
						<span v-if="item.decision" data-test="item-decision">
							<b>{{ item.decision.key }}</b> · {{ gateOutcomeLabel(item.gateCode, item.decision.outcome) }}{{ item.decision.rationale ? ' · ' + item.decision.rationale : '' }}
						</span>
						<span class="nx-view__spacer" />
						<NcButton v-if="item.can.adjourn"
							variant="tertiary"
							data-test="adjourn-item"
							:disabled="busy"
							@click="adjourn(item)">
							{{ t('nc_nexus', 'Adjourn') }}
						</NcButton>
						<NcButton v-if="item.can.decide"
							variant="primary"
							data-test="decide-item"
							:disabled="busy"
							@click="decideItem = item">
							{{ t('nc_nexus', 'Record decision') }}
						</NcButton>
					</footer>
				</article>
			</section>

			<section v-if="current.followUp.length" class="nx-view__section" data-test="session-follow-up">
				<div class="nx-view__section-head">
					<h3>{{ t('nc_nexus', 'Follow-up: open conditions and actions of this body') }}</h3>
				</div>
				<div class="nx-view__table">
					<div v-for="c in current.followUp"
						:key="c.id"
						class="nx-row ses__follow"
						:class="{ 'nx-row--warn': c.overdue }">
						<span class="nx-row__meta">{{ c.demandKey }}</span>
						<span>{{ c.title }}</span>
						<span class="nx-row__meta">{{ c.ownerId }}</span>
						<span class="nx-row__meta">{{ formatIsoDay(c.dueDate) }}</span>
					</div>
				</div>
			</section>
		</template>

		<SessionDialog v-if="dialog"
			:session="dialog.session"
			:bodies="bodies"
			:collab="collab"
			@close="dialog = null"
			@saved="onSaved" />
		<GateDialog v-if="decideItem"
			:item-id="decideItem.id"
			:title="decideItem.brief ? decideItem.brief.title : decideItem.title"
			:step="decideItem.gateCode"
			:checks="decideItem.brief ? decideItem.brief.checks : []"
			:outcomes="decideItem.options"
			:ready="!decideItem.brief || decideItem.brief.checks.every(c => c.ok)"
			:tranche="decideItem.gateCode !== 'change'"
			@close="decideItem = null"
			@decided="onDecided" />
		<NxDialog v-if="minutesOpen"
			:title="t('nc_nexus', 'Close and minute')"
			:subtitle="t('nc_nexus', 'Undecided items are adjourned to the next session. Votes can no longer be changed afterwards.')"
			data-test="minutes-dialog"
			@close="minutesOpen = false">
			<label class="nx-field">
				<span>{{ t('nc_nexus', 'Minutes') }}</span>
				<textarea v-model="minutes" class="nx-input ses__minutes-input" data-test="minutes-text" />
			</label>
			<template #actions>
				<NcButton variant="tertiary" @click="minutesOpen = false">
					{{ minutes.trim() ? t('nc_nexus', 'Cancel') : t('nc_nexus', 'Close') }}
				</NcButton>
				<NcButton variant="primary"
					data-test="save-minutes"
					:disabled="busy"
					@click="closeSession">
					{{ t('nc_nexus', 'Close session') }}
				</NcButton>
			</template>
		</NxDialog>
	</div>
</template>

<script>
import { mdiArrowDown, mdiArrowLeft, mdiArrowUp, mdiCalendarAccount, mdiClose, mdiPlus } from '@mdi/js'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import GateDialog from '../components/GateDialog.vue'
import NxDialog from '../components/NxDialog.vue'
import SessionDialog from '../components/SessionDialog.vue'
import { addTopic, adjournItem, castVote, deleteSession, errorMessage, generateAgenda, getSession, governance, listSessions, removeAgendaItem, sessionStatus, updateAgendaItem } from '../api.js'
import { changeTypeLabel, checkLabel, formatDate, thresholdReasonLabel, formatIsoDay, formatMoney, gateOutcomeLabel, instanceStatusLabel, sessionStatusLabel, statusLabel, voteLabel } from '../labels.js'

export default {
	name: 'SessionsView',
	components: { GateDialog, NcAvatar, NcButton, NcIconSvgWrapper, NxDialog, SessionDialog },
	props: {
		me: { type: String, default: '' },
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['changed'],
	setup() {
		return {
			mdiArrowDown,
			mdiArrowLeft,
			mdiArrowUp,
			mdiCalendarAccount,
			mdiClose,
			mdiPlus,
			n,
			t,
			changeTypeLabel,
			thresholdReasonLabel,
			checkLabel,
			formatDate,
			formatIsoDay,
			formatMoney,
			gateOutcomeLabel,
			sessionStatusLabel,
			statusLabel,
			voteLabel,
			votes: ['yes', 'no', 'abstain'],
		}
	},
	data() {
		return {
			sessions: [],
			bodies: [],
			collab: { talk: false, calendar: false },
			canManage: false,
			current: null,
			dialog: null,
			decideItem: null,
			busy: false,
			topicOpen: false,
			topic: '',
			drafts: {},
			minutesOpen: false,
			minutes: '',
		}
	},
	computed: {
		votingMembers() {
			return (this.current?.body.members ?? []).filter(m => m.voting === 1)
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
			try {
				const g = await governance()
				this.canManage = g.canManage
				this.bodies = g.bodies.filter(b => b.active)
				this.collab = g.collab
				this.sessions = await listSessions()
				if (this.current) {
					this.show(await getSession(this.current.id))
				}
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		show(session) {
			this.current = session
			this.drafts = Object.fromEntries(session.items.map(i => [i.id, i.recommendation ?? '']))
		},
		async open(id) {
			try {
				this.show(await getSession(id))
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		close() {
			this.current = null
			this.load()
		},
		async run(action, message = null) {
			this.busy = true
			try {
				const session = await action()
				if (session && session.items) {
					this.show(session)
				}
				if (message) {
					showSuccess(message)
				}
				this.$emit('changed')
				return true
			} catch (e) {
				showError(errorMessage(e))
				return false
			} finally {
				this.busy = false
			}
		},
		onSaved(session) {
			this.show(session)
			this.load()
		},
		agenda() {
			return this.run(() => generateAgenda(this.current.id))
		},
		async addTopicItem() {
			if (this.topic.trim() && await this.run(() => addTopic(this.current.id, this.topic))) {
				this.topic = ''
				this.topicOpen = false
			}
		},
		move(item, delta) {
			return this.run(() => updateAgendaItem(item.id, { move: delta }))
		},
		removeItem(item) {
			return this.run(() => removeAgendaItem(item.id))
		},
		saveRecommendation(item) {
			if ((this.drafts[item.id] ?? '') !== (item.recommendation ?? '')) {
				return this.run(() => updateAgendaItem(item.id, { recommendation: this.drafts[item.id] }))
			}
		},
		setStatus(status) {
			return this.run(() => sessionStatus(this.current.id, status))
		},
		async closeSession() {
			if (await this.run(() => sessionStatus(this.current.id, 'minuted', this.minutes), t('nc_nexus', 'Session closed'))) {
				this.minutesOpen = false
				this.minutes = ''
			}
		},
		async remove() {
			if (await this.run(() => deleteSession(this.current.id).then(() => null), t('nc_nexus', 'Session deleted'))) {
				this.close()
			}
		},
		vote(item, memberId, vote, conflict) {
			return this.run(() => castVote(item.id, { vote, conflict, memberId: memberId === this.me ? null : memberId }))
		},
		adjourn(item) {
			return this.run(() => adjournItem(item.id))
		},
		onDecided(session) {
			this.show(session)
			this.$emit('changed')
		},
		voteOf(item, memberId) {
			return item.votes.find(v => v.memberId === memberId) ?? null
		},
		voteText(v) {
			if (!v) {
				return '–'
			}
			return v.conflict ? t('nc_nexus', 'Conflict of interest') : voteLabel(v.vote)
		},
		itemStatus(item) {
			if (item.status === 'decided') {
				return instanceStatusLabel('decided')
			}
			if (item.status === 'adjourned') {
				return instanceStatusLabel('adjourned')
			}
			return item.gateCode ? t('nc_nexus', 'Pending') : t('nc_nexus', 'Topic')
		},
		itemChip(item) {
			return { decided: 'nx-chip--accepted', adjourned: 'nx-chip--on-hold' }[item.status] ?? 'nx-chip--submitted'
		},
		statusChip(status) {
			return { planned: 'nx-chip--draft', agenda_final: 'nx-chip--submitted', held: 'nx-chip--on-hold', minuted: 'nx-chip--accepted' }[status]
		},
	},
}
</script>

<style scoped lang="scss">
.ses__row {
	grid-template-columns: auto minmax(0, 1fr) 170px 90px 130px;
}

.ses__facts {
	margin-bottom: 14px;
}

.ses__minutes {
	margin: 0 0 16px;
	white-space: pre-line;
}

.ses__minutes-input {
	min-height: 160px;
}

.ses__agenda-actions,
.ses__topic {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ses__topic {
	margin-bottom: 12px;
}

.ses__item {
	margin-bottom: 14px;
	padding: 12px 14px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container-large, 12px);
}

.ses__item-head {
	display: grid;
	grid-template-columns: 30px minmax(0, 1fr) auto auto;
	gap: 10px;
	align-items: center;
}

.ses__pos {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: 700;
}

.ses__item-tools {
	display: flex;
}

.ses__brief {
	display: grid;
	grid-template-columns: minmax(0, 2fr) minmax(200px, 1fr);
	gap: 16px;
	margin: 10px 0 0 40px;
	font-size: 13px;

	p {
		margin: 0 0 6px;
	}
}

.ses__brief-facts.nx-summary__facts {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 10px 16px;
	align-content: start;
	margin-top: 0;
}

.ses__checks {
	margin: 4px 0;
	padding: 0;
	list-style: none;
}

.ses__ok {
	color: var(--color-success-text);
}

.ses__fail,
.ses__open-cond {
	color: var(--color-error-text);
}

.ses__recommendation {
	margin: 10px 0 0 40px;
	font-size: 13px;

	p {
		margin: 0 0 6px;
	}
}

.ses__options {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.ses__option {
	padding: 1px 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-pill, 20px);
	font-size: 12px;
}

.ses__votes {
	margin: 10px 0 0 40px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	font-size: 13px;
}

.ses__tally {
	margin-bottom: 6px;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.ses__tally--ok {
	color: var(--color-success-text);
}

.ses__vote-list {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
	gap: 2px 14px;
	margin: 0 0 8px;
	padding: 0;
	list-style: none;

	li {
		display: flex;
		gap: 6px;
		align-items: center;
	}

	small {
		color: var(--color-text-maxcontrast);
	}
}

.ses__vote--yes {
	color: var(--color-success-text);
}

.ses__vote--no {
	color: var(--color-error-text);
}

.ses__vote--none,
.ses__vote--abstain {
	color: var(--color-text-maxcontrast);
}

.ses__my-vote {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
	margin-top: 6px;
}

.ses__item-foot {
	display: flex;
	gap: 8px;
	align-items: center;
	margin: 10px 0 0 40px;
	font-size: 13px;
}

.ses__follow {
	grid-template-columns: 130px minmax(0, 1fr) 140px 120px;
	min-height: 40px;
	font-size: 13px;
}
</style>
