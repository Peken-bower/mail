<template>
	<div>
		<transition name="multiselect-header">
			<div v-if="selectMode" key="multiselect-header" class="multiselect-header">
				<div class="button primary" @click.prevent="markSelectedSeenOrUnseen">
					<span id="action-label">{{
						areAllSelectedRead
							? n(
								'mail',
								'Mark {number} unread',
								'Mark {number} unread',
								selection.length,
								{
									number: selection.length,
								}
							)
							: n(
								'mail',
								'Mark {number} read',
								'Mark {number} read',
								selection.length,
								{
									number: selection.length,
								}
							)
					}}</span>
				</div>
				<Actions class="app-content-list-item-menu" menu-align="right">
					<ActionButton
						v-if="isAtLeastOneSelectedUnimportant"
						icon="icon-important"
						:close-after-click="true"
						@click.prevent="markSelectionImportant">
						{{
							n(
								'mail',
								'Mark {number} as important',
								'Mark {number} as important',
								selection.length,
								{
									number: selection.length,
								}
							)
						}}
					</ActionButton>
					<ActionButton
						v-if="isAtLeastOneSelectedImportant"
						icon="icon-important"
						:close-after-click="true"
						@click.prevent="markSelectionUnimportant">
						{{
							n(
								'mail',
								'Mark {number} as unimportant',
								'Mark {number} as unimportant',
								selection.length,
								{
									number: selection.length,
								}
							)
						}}
					</ActionButton>
					<ActionButton icon="icon-starred"
						:close-after-click="true"
						@click.prevent="favoriteOrUnfavoriteAll">
						{{
							areAllSelectedFavorite
								? n(
									'mail',
									'Unfavorite {number}',
									'Unfavorite {number}',
									selection.length,
									{
										number: selection.length,
									}
								)
								: n(
									'mail',
									'Favorite {number}',
									'Favorite {number}',
									selection.length,
									{
										number: selection.length,
									}
								)
						}}
					</ActionButton>
					<ActionButton icon="icon-close"
						:close-after-click="true"
						@click.prevent="unselectAll">
						{{ n(
							'mail',
							'Unselect {number}',
							'Unselect {number}',
							selection.length,
							{
								number: selection.length,
							}
						) }}
					</ActionButton>
					<ActionButton
						v-if="!account.isUnified"
						icon="icon-external"
						:close-after-click="true"
						@click.prevent="onOpenMoveModal">
						{{ n(
							'mail',
							'Move {number} thread',
							'Move {number} threads',
							selection.length,
							{
								number: selection.length,
							}
						) }}
					</ActionButton>
					<ActionButton
						icon="icon-forward"
						:close-after-click="true"
						@click.prevent="forwardSelectedAsAttachment">
						{{ n(
							'mail',
							'Forward {number} as attachment',
							'Forward {number} as attachment',
							selection.length,
							{
								number: selection.length,
							}
						) }}
					</ActionButton>
					<ActionButton icon="icon-delete"
						:close-after-click="true"
						@click.prevent="deleteAllSelected">
						{{ n(
							'mail',
							'Delete {number} thread',
							'Delete {number} threads',
							selection.length,
							{
								number: selection.length,
							}
						) }}
					</ActionButton>
				</Actions>
				<MoveModal
					v-if="showMoveModal"
					:account="account"
					:envelopes="selectedEnvelopes"
					:move-thread="true"
					@close="onCloseMoveModal" />
			</div>
		</transition>
		<transition-group name="list">
			<div id="list-refreshing"
				key="loading"
				class="icon-loading-small"
				:class="{refreshing: refreshing}" />
			<Envelope
				v-for="(env, index) in envelopes"
				:key="env.databaseId"
				:data="env"
				:mailbox="mailbox"
				:selected="selection.includes(env.databaseId)"
				:select-mode="selectMode"
				:selected-envelopes="selectedEnvelopes"
				@delete="$emit('delete', env.databaseId)"
				@update:selected="onEnvelopeSelectToggle(env, index, ...$event)"
				@select-multiple="onEnvelopeSelectMultiple(env, index)" />
			<div
				v-if="loadMoreButton && !loadingMore"
				:key="'list-collapse-' + searchQuery"
				class="load-more"
				@click="$emit('loadMore')">
				{{ t('mail', 'Load more') }}
			</div>
			<div id="load-more-mail-messages" key="loadingMore" :class="{'icon-loading-small': loadingMore}" />
		</transition-group>
	</div>
</template>

<script>
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import { showError } from '@nextcloud/dialogs'

import Envelope from './Envelope'
import logger from '../logger'
import MoveModal from './MoveModal'
import { matchError } from '../errors/match'
import NoTrashMailboxConfiguredError
	from '../errors/NoTrashMailboxConfiguredError'
import { differenceWith } from 'ramda'
import dragEventBus from '../directives/drag-and-drop/util/dragEventBus'

export default {
	name: 'EnvelopeList',
	components: {
		Actions,
		ActionButton,
		Envelope,
		MoveModal,
	},
	props: {
		account: {
			type: Object,
			required: true,
		},
		mailbox: {
			type: Object,
			required: true,
		},
		envelopes: {
			type: Array,
			required: true,
		},
		searchQuery: {
			type: String,
			required: false,
			default: undefined,
		},
		refreshing: {
			type: Boolean,
			required: true,
			default: true,
		},
		loadingMore: {
			type: Boolean,
			required: true,
		},
		loadMoreButton: {
			type: Boolean,
			required: false,
			default: false,
		},
	},
	data() {
		return {
			selection: [],
			showMoveModal: false,
			lastToggledIndex: undefined,
			forwardedMessages: [],
		}
	},
	computed: {
		selectMode() {
			// returns true when in selection mode (where the user selects several emails at once)
			return this.selection.length > 0
		},
		areAllSelectedRead() {
			// returns false if at least one selected message has not been read yet
			return this.selectedEnvelopes.every((env) => env.flags.seen === true)
		},
		isAtLeastOneSelectedImportant() {
			// returns true if at least one selected message is marked as important
			return this.selectedEnvelopes.some((env) => {
				return this.$store.getters
					.getEnvelopeTags(env.databaseId)
					.some((tag) => tag.imapLabel === '$label1')
			})
		},
		isAtLeastOneSelectedUnimportant() {
			// returns true if at least one selected message is not marked as important
			return this.selectedEnvelopes.some((env) => {
				return !this.$store.getters
					.getEnvelopeTags(env.databaseId)
					.some((tag) => tag.imapLabel === '$label1')
			})
		},
		areAllSelectedFavorite() {
			// returns false if at least one selected message has not been favorited yet
			return this.selectedEnvelopes.every((env) => env.flags.flagged === true)
		},
		selectedEnvelopes() {
			return this.envelopes.filter((env) => this.selection.includes(env.databaseId))
		},
	},
	watch: {
		envelopes(newVal, oldVal) {
			// Unselect vanished envelopes
			const newIds = newVal.map((env) => env.databaseId)
			this.selection = this.selection.filter((id) => newIds.includes(id))
			differenceWith((a, b) => a.databaseId === b.databaseId, oldVal, newVal)
				.forEach((env) => {
					env.flags.selected = false
				})
		},
	},
	mounted() {
		dragEventBus.$on('envelopesDropped', this.unselectAll)
	},
	beforeDestroy() {
		dragEventBus.$off('envelopesDropped', this.unselectAll)
	},
	methods: {
		isEnvelopeSelected(idx) {
			if (this.selection.length === 0) {
				return false
			}

			return this.selection.includes(idx)
		},
		markSelectedSeenOrUnseen() {
			const seen = !this.areAllSelectedRead
			this.selectedEnvelopes.forEach((envelope) => {
				this.$store.dispatch('toggleEnvelopeSeen', {
					envelope,
					seen,
				})
			})
			this.unselectAll()
		},
		markSelectionImportant() {
			this.selectedEnvelopes.forEach((envelope) => {
				this.$store.dispatch('markEnvelopeImportantOrUnimportant', {
					envelope,
					addTag: true,
				})
			})
			this.unselectAll()
		},
		markSelectionUnimportant() {
			this.selectedEnvelopes.forEach((envelope) => {
				this.$store.dispatch('markEnvelopeImportantOrUnimportant', {
					envelope,
					addTag: false,
				})
			})
			this.unselectAll()
		},
		favoriteOrUnfavoriteAll() {
			const favFlag = !this.areAllSelectedFavorite
			this.selectedEnvelopes.forEach((envelope) => {
				this.$store.dispatch('markEnvelopeFavoriteOrUnfavorite', {
					envelope,
					favFlag,
				})
			})
			this.unselectAll()
		},
		async deleteAllSelected() {
			let nextEnvelopeToNavigate
			let isAllSelected

			if (this.selectedEnvelopes.length === this.envelopes.length) {
				isAllSelected = true
			} else {
				const indexSelectedEnvelope = this.selectedEnvelopes.findIndex((selectedEnvelope) =>
					selectedEnvelope.databaseId === this.$route.params.threadId)

				// one of threads is selected
				if (indexSelectedEnvelope !== -1) {
					const lastSelectedEnvelope = this.selectedEnvelopes[this.selectedEnvelopes.length - 1]
					const diff = this.envelopes.filter(envelope => envelope === lastSelectedEnvelope || !this.selectedEnvelopes.includes(envelope))
					const lastIndex = diff.indexOf(lastSelectedEnvelope)
					nextEnvelopeToNavigate = diff[lastIndex === 0 ? 1 : lastIndex - 1]
				}
			}

			await Promise.all(this.selectedEnvelopes.map(async(envelope) => {
				logger.info(`deleting thread ${envelope.threadRootId}`)
				await this.$store.dispatch('deleteThread', {
					envelope,
				})
			})).catch(async error => {
				showError(await matchError(error, {
					[NoTrashMailboxConfiguredError.getName()]() {
						return t('mail', 'No trash mailbox configured')
					},
					default(error) {
						logger.error('could not delete message', error)
						return t('mail', 'Could not delete message')
					},
				}))
			})
			if (nextEnvelopeToNavigate) {
				await this.$router.push({
					name: 'message',
					params: {
						mailboxId: this.$route.params.mailboxId,
						threadId: nextEnvelopeToNavigate.databaseId,
					},
				})

				// Get new messages
				await this.$store.dispatch('fetchNextEnvelopes', {
					mailboxId: this.mailbox.databaseId,
					query: this.searchQuery,
					quantity: this.selectedEnvelopes.length,
				})
			} else if (isAllSelected) {
				await this.$router.push({
					name: 'mailbox',
					params: {
						mailboxId: this.$route.params.mailboxId,
					},
				})
			}
			this.unselectAll()
		},
		setEnvelopeSelected(envelope, selected) {
			const alreadySelected = this.selection.includes(envelope.databaseId)
			if (selected && !alreadySelected) {
				envelope.flags.selected = true
				this.selection.push(envelope.databaseId)
			} else if (!selected && alreadySelected) {
				envelope.flags.selected = false
				this.selection.splice(this.selection.indexOf(envelope.databaseId), 1)
			}
		},
		onEnvelopeSelectToggle(envelope, index, selected) {
			this.lastToggledIndex = index
			this.setEnvelopeSelected(envelope, selected)
		},
		onEnvelopeSelectMultiple(envelope, index) {
			if (this.lastToggledIndex === undefined) {
				return
			}

			const start = Math.min(this.lastToggledIndex, index)
			const end = Math.max(this.lastToggledIndex, index)
			const selected = this.selection.includes(envelope.databaseId)
			for (let i = start; i <= end; i++) {
				this.setEnvelopeSelected(this.envelopes[i], !selected)
			}
			this.lastToggledIndex = index
		},
		unselectAll() {
			this.envelopes.forEach((env) => {
				env.flags.selected = false
			})
			this.selection = []
		},
		onOpenMoveModal() {
			this.showMoveModal = true
		},
		async forwardSelectedAsAttachment() {
			this.forwardedMessages = this.selection
			await this.$store.dispatch('showMessageComposer', {
				forwardedMessages: this.forwardedMessages,
			})
			this.forwardedMessages = []
			this.unselectAll()
		},
		onCloseMoveModal() {
			this.showMoveModal = false
			this.unselectAll()
		},
	},
}
</script>

<style lang="scss" scoped>
div {
	// So we can align the loading spinner in the Priority inbox
	position: relative;
}

.load-more {
	text-align: center;
	margin-top: 10px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.multiselect-header {
	display: flex;
	flex-direction: row;
	align-items: center;
	justify-content: center;
	background-color: var(--color-main-background-translucent);
	position: sticky;
	top: 0px;
	height: 48px;
	z-index: 100;
}

#load-more-mail-messages {
	margin: 10px auto;
	padding: 10px;
	margin-top: 50px;
	margin-bottom: 50px;
}

/* TODO: put this in core icons.css as general rule for buttons with icons */
#load-more-mail-messages.icon-loading-small {
	padding-left: 32px;
	background-position: 9px center;
}

#list-refreshing {
	position: absolute;
	left: calc(50% - 8px);
	overflow: hidden;
	padding: 12px;
	background-color: var(--color-main-background);
	z-index: 1;
	border-radius: var(--border-radius-pill);
	border: 1px solid var(--color-border);
	top: -24px;
	opacity: 0;
	transition-property: top, opacity;
	transition-duration: 0.5s;
	transition-timing-function: ease-in-out;

	&.refreshing {
		top: 4px;
		opacity: 1;
	}
}

.multiselect-header-enter-active,
.multiselect-header-leave-active,
.list-enter-active,
.list-leave-active {
	transition: all var(--animation-slow);
}

.multiselect-header-enter,
.multiselect-header-leave-to,
.list-enter,
.list-leave-to {
	opacity: 0;
	height: 0px;
	transform: scaleY(0);
}

#action-label {
	vertical-align: middle;
}
@media only screen and (min-width: 600px) {
	#action-label {
		display: block;
	}
}
</style>
