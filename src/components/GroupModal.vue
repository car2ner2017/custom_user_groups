<template>
	<NcModal
		v-if="show"
		:name="''"
		size="normal"
		@close="$emit('close')">
		<form class="group-form" @submit.prevent="submitForm">
			<h2 class="form-title">
				{{ isEdit ? t('Edit group') : t('Create new group') }}
			</h2>

			<!-- Group Name Field -->
			<div class="form-group">
				<label for="group-name" class="form-label">
					{{ t('Group name') }} <span class="required">*</span>
				</label>
				<NcTextField
					id="group-name"
					v-model="name"
					:placeholder="t('e.g. Project team or Accounting')"
					:disabled="loading || !canEditName"
					required />
				<small v-if="isEdit && !canEditName" class="help-text text-warning">
					{{ t('Renaming the group is only available to the creator, administrator or manager.') }}
				</small>
			</div>

			<!-- Add Self Checkbox (only in Create mode) -->
			<div v-if="!isEdit" class="form-group add-self-group">
				<NcCheckboxRadioSwitch
					v-model="isSelfSelected"
					type="checkbox"
					:disabled="loading">
					{{ t('Add myself as a group member') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Group Owner Field (Transfer ownership) -->
			<div v-if="isEdit && canTransferOwnership" class="form-group">
				<label for="group-owner" class="form-label">
					{{ t('Transfer group ownership') }}
				</label>
				<div class="current-owner-info">
					{{ t('Current owner:') }} <strong>{{ currentOwnerDisplayName }}</strong> <span v-if="currentOwnerEmail">({{ currentOwnerEmail }})</span>
				</div>
				<select
					id="group-owner"
					v-model="selectedNewOwnerId"
					class="owner-select"
					:disabled="loading">
					<option value="">
						-- {{ t('No change') }} --
					</option>
					<option
						v-for="user in selectedUsers"
						:key="user.uid"
						:value="user.uid"
						:disabled="user.uid === currentOwnerUid">
						{{ user.displayName }} ({{ user.email || ('@' + user.uid) }}){{ user.uid === currentOwnerUid ? ' (' + t('Current owner') + ')' : '' }}
					</option>
				</select>
			</div>

			<!-- Selected Group Members Section -->
			<div class="form-group">
				<label class="form-label">
					{{ t('Group members') }} ({{ selectedUsers.length }})
				</label>

				<!-- Filter input for already selected members (only when list is long) -->
				<div v-if="selectedUsers.length > 5" class="filter-selected-wrapper">
					<NcTextField
						v-model="selectedMembersFilter"
						:placeholder="t('Search group members (by name, email or username)…')"
						size="small"
						:disabled="loading" />
				</div>

				<!-- Selected members list with scroll and max height -->
				<div v-if="selectedUsers.length > 0" class="selected-users-list">
					<div
						v-for="user in filteredSelectedUsers"
						:key="user.uid"
						class="user-item">
						<div class="user-item-info">
							<span class="user-displayname">{{ user.displayName }}</span>
							<span class="user-email-uid">{{ user.email || ('@' + user.uid) }}</span>
						</div>
						<NcButton
							type="button"
							variant="error"
							size="small"
							:disabled="loading"
							@click.stop="removeUser(user.uid)">
							- {{ t('Remove') }}
						</NcButton>
					</div>
					<div v-if="filteredSelectedUsers.length === 0" class="no-filtered-selected">
						{{ t('No members found matching "{query}"', { query: selectedMembersFilter }) }}
					</div>
				</div>
				<div v-else class="no-members-hint">
					{{ t('No members selected yet. Use the search dropdown below to add members.') }}
				</div>

				<!-- Search users dropdown in Nextcloud Files Sharing style -->
				<UserSearchDropdown
					:label="isEdit ? t('Add member') : t('Search Nextcloud users to add')"
					input-id="group-add-member-search"
					:disabled="loading"
					:exclude-uids="selectedUserUids"
					:placeholder="t('Search Nextcloud users to add…')"
					@select="addUser" />
			</div>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton
					type="button"
					variant="secondary"
					:disabled="loading"
					@click="$emit('close')">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="loading || name.trim() === '' || (isEdit && !hasChanges)">
					{{ isEdit ? t('Save changes') : t('Create group') }}
				</NcButton>
			</div>
		</form>

		<!-- Self-removal Warning Modal -->
		<ConfirmModal
			:show="showSelfRemoveConfirm"
			:title="t('Warning')"
			:message="t('Warning: you are removing yourself from this custom group. After removal, you will lose access to the group and delegated management/moderation rights. Continue?')"
			:confirm-text="t('Continue')"
			@close="cancelSelfRemoval"
			@confirm="confirmSelfRemoval" />
	</NcModal>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '../utils/l10n'
import ConfirmModal from './ConfirmModal.vue'
import UserSearchDropdown from './UserSearchDropdown.vue'
import type { CustomGroup, UserOption } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup | null
	isAdmin?: boolean
	currentUserId?: string | null
	currentUserDisplayName?: string
	currentUserEmail?: string
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'saved', group: CustomGroup): void
}>()

const isEdit = computed(() => props.group !== null)
const canEditName = computed(() => {
	if (!props.group) return true
	return props.group.permissions ? props.group.permissions.can_edit_name : true
})
const canTransferOwnership = computed(() => {
	if (!props.group) return false
	return isEdit.value && Boolean(props.group.is_owner || props.isAdmin || props.group.permissions?.can_transfer_ownership)
})

const currentOwnerUid = computed(() => {
	if (!props.group) return ''
	return props.group.owner_id || props.group.creator_id || ''
})

const currentOwnerDisplayName = computed(() => {
	if (!props.group) return ''
	return props.group.owner_displayName || props.group.creator_displayName || currentOwnerUid.value
})

const currentOwnerEmail = computed(() => {
	if (!props.group) return ''
	return props.group.owner_email || props.group.creator_email || ''
})

const name = ref('')
const selectedNewOwnerId = ref<string>('')
const selectedUsers = ref<UserOption[]>([])
const selectedMembersFilter = ref('')
const selectedUserUids = computed(() => selectedUsers.value.map((u) => u.uid))
const loading = ref(false)

const showSelfRemoveConfirm = ref(false)
const pendingRemoveUid = ref<string | null>(null)

const isSelfSelected = computed({
	get: () => {
		if (!props.currentUserId) return false
		return selectedUsers.value.some((u) => u.uid === props.currentUserId)
	},
	set: (val: boolean) => {
		toggleAddSelf(val)
	},
})

function toggleAddSelf(checked: boolean) {
	if (!props.currentUserId) return
	if (checked) {
		if (!selectedUsers.value.some((u) => u.uid === props.currentUserId)) {
			const found = availableUsers.value.find((u) => u.uid === props.currentUserId)
			selectedUsers.value.push({
				uid: props.currentUserId,
				displayName: found?.displayName || props.currentUserDisplayName || props.currentUserId,
				email: found?.email || props.currentUserEmail || '',
			})
		}
	} else {
		selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== props.currentUserId)
	}
}

const hasChanges = computed(() => {
	if (!props.group) {
		return false
	}
	if (name.value.trim() !== (props.group.name || '').trim()) {
		return true
	}
	if (selectedNewOwnerId.value !== '' && selectedNewOwnerId.value !== currentOwnerUid.value) {
		return true
	}
	const originalUids = new Set(
		props.group.members && props.group.members.length > 0
			? props.group.members.map((m) => m.uid || m.id || '')
			: (props.group.member_ids || []),
	)
	const currentUids = new Set(selectedUsers.value.map((u) => u.uid || u.id || ''))
	if (originalUids.size !== currentUids.size) {
		return true
	}
	for (const uid of originalUids) {
		if (!currentUids.has(uid)) {
			return true
		}
	}
	return false
})

watch(
	[() => props.show, () => props.group],
	([isOpen, grp]) => {
		if (isOpen) {
			if (grp) {
				name.value = grp.name
				selectedUsers.value = (grp.members || []).map((m) => ({ ...m }))
				selectedNewOwnerId.value = ''
			} else {
				name.value = ''
				selectedNewOwnerId.value = ''
				if (props.currentUserId) {
					selectedUsers.value = [
						{
							uid: props.currentUserId,
							displayName: props.currentUserDisplayName || props.currentUserId,
							email: props.currentUserEmail || '',
						},
					]
				} else {
					selectedUsers.value = []
				}
			}
			selectedMembersFilter.value = ''
		}
		showSelfRemoveConfirm.value = false
		pendingRemoveUid.value = null
	},
	{ immediate: true },
)

const filteredSelectedUsers = computed(() => {
	const query = selectedMembersFilter.value.trim().toLowerCase()
	if (query === '') return selectedUsers.value
	return selectedUsers.value.filter((u) => {
		const nameMatch = u.displayName.toLowerCase().includes(query)
		const uidMatch = u.uid.toLowerCase().includes(query)
		const emailMatch = u.email ? u.email.toLowerCase().includes(query) : false
		return nameMatch || uidMatch || emailMatch
	})
})

function addUser(user: UserOption) {
	if (!selectedUsers.value.some((u) => u.uid === user.uid)) {
		selectedUsers.value.push({ ...user })
	}
}

function cancelSelfRemoval() {
	showSelfRemoveConfirm.value = false
	pendingRemoveUid.value = null
}

function confirmSelfRemoval() {
	if (pendingRemoveUid.value) {
		const uid = pendingRemoveUid.value
		selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== uid)
		if (selectedNewOwnerId.value === uid) {
			selectedNewOwnerId.value = ''
		}
	}
	showSelfRemoveConfirm.value = false
	pendingRemoveUid.value = null
}

function removeUser(uid: string) {
	if (isEdit.value && props.group) {
		const isOwner = Boolean(props.group.is_owner || uid === currentOwnerUid.value)
		const isSelf = Boolean(props.currentUserId && uid === props.currentUserId)

		if (isSelf && !isOwner) {
			const userDelegationLevel = props.group.permissions?.delegation_level
			const isDelegate = props.group.delegations?.some((d) => d.user_id === uid)
				|| props.group.delegates_manage?.some((d) => d.user_id === uid)
				|| props.group.delegates_moderate?.some((d) => d.user_id === uid)
			const isManagerOrModerator = userDelegationLevel === 'manage' || userDelegationLevel === 'moderate' || isDelegate

			if (isManagerOrModerator) {
				pendingRemoveUid.value = uid
				showSelfRemoveConfirm.value = true
				return
			}
		}
	}

	selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== uid)
	if (selectedNewOwnerId.value === uid) {
		selectedNewOwnerId.value = ''
	}
}

async function submitForm() {
	if (name.value.trim() === '') {
		showError(t('Please enter a group name'))
		return
	}

	loading.value = true
	const memberIds = selectedUsers.value.map((u) => u.uid)

	try {
		if (isEdit.value && props.group) {
			const url = generateUrl(`/apps/user_groups_hzs/api/v1/groups/${props.group.group_id}`)
			const payload: { name: string; memberIds: string[]; newOwnerId?: string } = {
				name: name.value.trim(),
				memberIds,
			}
			if (canTransferOwnership.value && selectedNewOwnerId.value && selectedNewOwnerId.value !== currentOwnerUid.value) {
				if (!selectedUsers.value.some((u) => u.uid === selectedNewOwnerId.value)) {
					showError(t('The selected new owner must be a member of the group'))
					loading.value = false
					return
				}
				payload.newOwnerId = selectedNewOwnerId.value
			}
			const response = await axios.put(url, payload)
			showSuccess(t('Group updated successfully'))
			emit('saved', response.data)
		} else {
			const url = generateUrl('/apps/user_groups_hzs/api/v1/groups')
			const response = await axios.post(url, {
				name: name.value.trim(),
				memberIds,
			})
			showSuccess(t('Group created successfully'))
			emit('saved', response.data)
		}
		emit('close')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || t('An error occurred while saving the group')
		showError(msg)
	} finally {
		loading.value = false
	}
}
</script>

<style scoped>
.group-form {
	padding: 16px 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.add-self-group {
	margin-top: -6px;
	margin-bottom: 4px;
}

.form-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.current-owner-info {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.required {
	color: var(--color-text-error, var(--color-error));
}

.form-title {
	font-size: 20px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.help-text {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.text-warning {
}

.filter-selected-wrapper {
	margin-bottom: 6px;
}

.selected-users-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-height: 160px;
	overflow-y: auto;
	padding: 8px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.no-members-hint,
.no-filtered-selected {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.user-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	cursor: pointer;
	background: var(--color-main-background);
}

.user-item:hover {
	background-color: var(--color-background-hover);
}

.user-item-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.user-displayname {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.user-email-uid {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.owner-select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	width: 100%;
	box-sizing: border-box;
}

.owner-select:focus {
	border-color: var(--color-primary-element);
	outline: none;
}
</style>
