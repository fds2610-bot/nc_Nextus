<template>
	<div class="nx-section" data-test="comments">
		<h3>{{ t('nc_nexus', 'Comments') }}</h3>
		<p v-if="!comments.length" class="thread__empty">
			{{ t('nc_nexus', 'No comments yet.') }}
		</p>
		<ul v-else class="thread">
			<li v-for="c in comments"
				:key="c.id"
				class="thread__item"
				:class="'thread__item--' + c.kind"
				:data-test="'comment-' + c.kind">
				<NcAvatar :user="c.userId" :size="28" :disable-menu="true" />
				<div class="thread__body">
					<div class="thread__meta">
						<strong>{{ c.userId }}</strong>
						<span v-if="c.kind !== 'comment'" class="thread__kind">{{ kindLabel(c.kind) }}</span>
						<span class="thread__when">{{ formatDate(c.createdAt) }}</span>
					</div>
					<p class="thread__text">
						{{ c.message }}
					</p>
				</div>
			</li>
		</ul>
		<div class="thread__new">
			<label class="nx-field" data-test="field-comment">
				<span class="nx-hidden-visually">{{ t('nc_nexus', 'New comment') }}</span>
				<textarea v-model="draft"
					class="nx-input thread__input"
					:placeholder="t('nc_nexus', 'Write a comment …')"
					@keydown.ctrl.enter="send"
					@keydown.meta.enter="send" />
			</label>
			<NcButton variant="secondary"
				:disabled="busy || !draft.trim()"
				data-test="send-comment"
				@click="send">
				{{ t('nc_nexus', 'Comment') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import { addComment, errorMessage, listComments } from '../api.js'
import { formatDate } from '../labels.js'

export default {
	name: 'CommentThread',
	components: { NcAvatar, NcButton },
	props: {
		demandId: { type: Number, required: true },
		/** Zähler von außen erhöhen, um neu zu laden (z. B. nach Rückfrage oder Antwort) */
		refreshKey: { type: Number, default: 0 },
	},
	emits: ['dirty'],
	setup() {
		return { t, formatDate }
	},
	data() {
		return { comments: [], draft: '', busy: false }
	},
	watch: {
		refreshKey: 'load',
		draft(value) {
			this.$emit('dirty', value.trim() !== '')
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		kindLabel(kind) {
			return { query: t('nc_nexus', 'Query'), answer: t('nc_nexus', 'Answer'), analysis: t('nc_nexus', 'Analysis') }[kind] || ''
		},
		async load() {
			try {
				this.comments = await listComments(this.demandId)
			} catch (e) {
				showError(errorMessage(e))
			}
		},
		async send() {
			if (!this.draft.trim() || this.busy) {
				return
			}
			this.busy = true
			try {
				this.comments.push(await addComment(this.demandId, this.draft))
				this.draft = ''
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
.thread {
	margin: 0 0 12px;
	padding: 0;
	list-style: none;

	&__empty {
		color: var(--color-text-maxcontrast);
	}

	&__item {
		display: flex;
		gap: 10px;
		padding: 8px 0;

		&--query .thread__kind {
			background: rgba(var(--color-warning-rgb), 0.14);
			color: var(--color-warning-text);
		}

		&--analysis .thread__kind {
			background: var(--color-primary-element-light);
			color: var(--color-primary-element-light-text);
		}

		&--answer .thread__kind {
			background: rgba(var(--color-success-rgb), 0.12);
			color: var(--color-success-text);
		}
	}

	&__body {
		flex: 1;
		min-width: 0;
	}

	&__meta {
		display: flex;
		align-items: center;
		gap: 8px;
		font-size: 13px;
	}

	&__kind {
		padding: 0 8px;
		border-radius: var(--border-radius-pill);
		font-size: 12px;
		font-weight: 600;
	}

	&__when {
		color: var(--color-text-maxcontrast);
	}

	&__text {
		margin: 2px 0 0;
		white-space: pre-wrap;
		overflow-wrap: anywhere;
	}

	&__new {
		display: flex;
		align-items: flex-end;
		gap: 8px;

		.nx-field {
			flex: 1;
			margin: 0;
		}
	}

	&__input.nx-input.nx-input {
		min-height: 3em;
	}
}
</style>
