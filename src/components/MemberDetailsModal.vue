<template>
	<NcModal
		v-if="show"
		:name="t('Group member')"
		size="normal"
		@close="$emit('close')">
		<div v-if="member" class="member-details-modal">
			<div class="member-profile-card">
				<NcAvatar
					:user="member.uid"
					:display-name="member.displayName"
					:size="72"
					disable-menu
					disable-tooltip />
				<div class="member-profile-info">
					<h3 class="profile-name">
						{{ member.displayName }}
					</h3>
					<div v-if="member.email" class="profile-email">
						{{ member.email }}
					</div>
					<div class="profile-uid">
						@{{ member.uid }}
					</div>

					<!-- Role badge if applicable -->
					<div v-if="memberRole" class="profile-badge-row">
						<span class="role-badge" :class="memberRole.className">
							{{ memberRole.label }}
						</span>
					</div>
				</div>
			</div>

			<!-- Actions: Exclude & Close -->
			<div class="modal-actions">
				<NcButton
					v-if="canExclude"
					type="error"
					:disabled="loading"
					@click="$emit('exclude', member)">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="16" />
					</template>
					{{ t('Exclude') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="loading"
					@click="$emit('close')">
					{{ t('Close') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import { t } from '../utils/l10n'
import type { CustomGroup, UserOption } from '../types'

const props = defineProps<{
	show: boolean
	member: UserOption | null
	group: CustomGroup | null
	canExclude?: boolean
	loading?: boolean
}>()

defineEmits<{
	(e: 'close'): void
	(e: 'exclude', member: UserOption): void
}>()

const memberRole = computed(() => {
	if (!props.member || !props.group) return null
	const uid = props.member.uid
	const ownerId = props.group.owner_id || props.group.creator_id

	if (uid === ownerId) {
		return { label: t('Owner'), className: 'owner-tag' }
	}
	if (uid === props.group.creator_id && props.group.creator_id !== ownerId) {
		return { label: t('Creator'), className: 'creator-tag' }
	}
	const manageDel = props.group.delegates_manage?.some((d) => d.user_id === uid)
		|| props.group.delegations?.some((d) => d.user_id === uid && d.level === 'manage')
	if (manageDel) {
		return { label: t('Manage'), className: 'manage-tag' }
	}
	const moderateDel = props.group.delegates_moderate?.some((d) => d.user_id === uid)
		|| props.group.delegations?.some((d) => d.user_id === uid && d.level === 'moderate')
	if (moderateDel) {
		return { label: t('Moderate'), className: 'moderate-tag' }
	}
	return null
})
</script>

<style scoped>
.member-details-modal {
	padding: 24px 20px 20px 20px;
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.member-profile-card {
	display: flex;
	align-items: center;
	gap: 20px;
	padding: 16px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.member-profile-info {
	display: flex;
	flex-direction: column;
	gap: 4px;
	overflow: hidden;
}

.profile-name {
	font-size: 18px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.profile-email {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.profile-uid {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	opacity: 0.85;
}

.profile-badge-row {
	margin-top: 6px;
}

.role-badge {
	display: inline-block;
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
	text-transform: uppercase;
}

.owner-tag {
	background: var(--color-warning);
	color: var(--color-warning-text, #fff);
}

.creator-tag {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.manage-tag {
	background: #8e44ad;
	color: #fff;
}

.moderate-tag {
	background: #27ae60;
	color: #fff;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
