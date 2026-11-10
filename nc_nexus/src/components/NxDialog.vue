<template>
	<!-- Gemeinsame Dialog-Hülle: Kopf- und Fußzeile fest, nur der Inhalt scrollt.
	     label-id statt :name, damit kein sichtbarer h2 die Suchleiste von NC 34 überlagert. -->
	<NcModal :size="size"
		:label-id="titleId"
		:close-on-click-outside="false"
		@close="$emit('close')">
		<div ref="root"
			class="nx-dialog"
			:data-test="dataTest"
			@keydown.esc="onEscape">
			<h2 :id="titleId" class="nx-hidden-visually">
				{{ title }}
			</h2>
			<header class="nx-dialog__head nx-summary">
				<div class="nx-summary__title">
					{{ title }}
				</div>
				<div v-if="subtitle" class="nx-summary__sub">
					{{ subtitle }}
				</div>
			</header>
			<div class="nx-dialog__body">
				<slot />
			</div>
			<footer class="nx-dialog__actions">
				<slot name="actions" />
			</footer>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'

let counter = 0

export default {
	name: 'NxDialog',
	components: { NcModal },
	props: {
		title: { type: String, required: true },
		subtitle: { type: String, default: '' },
		size: { type: String, default: 'normal' },
		dataTest: { type: String, default: undefined },
	},
	emits: ['close', 'ready'],
	data() {
		return { titleId: `nx-dialog-title-${++counter}` }
	},
	mounted() {
		// Liegt der Dialog über einem anderen Modal, muss dessen Fokusfalle diesen Dialog einschließen,
		// sonst blockiert sie Klicks und Eingaben hier (Muster aus VertragsWerk, NewContactDialog)
		this.$nextTick(() => {
			const mask = this.$refs.root?.closest('.modal-mask')
			if (mask) {
				this.$emit('ready', mask)
			}
		})
	},
	methods: {
		onEscape(event) {
			// NcModal ignoriert Esc aus Eingabefeldern; offene Auswahllisten schließt Esc zuerst selbst
			if (!event.target.closest?.('.vs--open')) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.nx-dialog {
	display: flex;
	flex-direction: column;
	max-height: min(90vh, 100vh - 2 * var(--header-height) - 2 * var(--body-container-margin));

	&__head {
		flex: none;
		padding: 18px 56px 14px 22px;
		border-bottom: 1px solid var(--color-border);
	}

	&__body {
		flex: 1 1 auto;
		min-height: 0;
		overflow-y: auto;
		padding: 18px 22px;
	}

	&__actions {
		flex: none;
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding: 12px 22px;
		border-top: 1px solid var(--color-border);

		:deep(.nx-dialog__left) {
			margin-inline-end: auto;
		}
	}
}
</style>
