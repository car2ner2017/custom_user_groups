<template>
	<NcModal
		v-if="show"
		:name="''"
		size="normal"
		@close="$emit('close')">
		<form class="add-member-form" @submit.prevent="submitForm">
			<h2 class="form-title">
				{{ t('Add member') }}
			</h2>

			<p class="form-description">
				{{ t('Select users to add to group "{group}".', { group: group.name }) }}
			</p>

			<!-- Selected Users to Add Section -->
			<div class="form-group">
				<label class="form-label">
					{{ t('Members to add ({count})', { count: selectedUsers.length }) }}
				</label>

				<!-- Selected users list with scroll and max height -->
				<div v-if="selectedUsers.length > 0" class="selected-users-list">
					<div
						v-for="user in selectedUsers"
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
							:disabled="saving"
							@click="removeSelected(user.uid)">
							- {{ t('Remove') }}
						</NcButton>
					</div>
				</div>
				<div v-else class="no-members-hint">
					{{ t('No members selected yet. Choose users from the list below.') }}
				</div>

				<!-- Search input for available users -->
				<div class="search-user-wrapper">
					<NcTextField
						v-model="userSearchQuery"
						:placeholder="t('Search Nextcloud users to add...')"
						:disabled="saving" />
				</div>

				<!-- Available users list -->
				<div v-if="loadingUsers" class="loading-users">
					<NcLoadingIcon :size="20" /> {{ t('Loading users...') }}
				</div>
				<div v-else-if="filteredAvailableUsers.length > 0" class="available-users-list">
					<div
						v-for="user in filteredAvailableUsers"
						:key="user.uid"
						class="user-item"
						@click="addSelected(user)">
						<div class="user-item-info">
							<span class="user-displayname">{{ user.displayName }}</span>
							<span class="user-email-uid">{{ user.email || ('@' + user.uid) }}</span>
						</div>
						<NcButton
							type="button"
							variant="tertiary"
							size="small"
							:disabled="saving"
							@click.stop="addSelected(user)">
							+ {{ t('Add') }}
						</NcButton>
					</div>
				</div>
				<div v-else class="empty-users">
					{{ userSearchQuery.trim() !== '' ? t('No users found for query') : t('All available users are already added to the group') }}
				</div>
			</div>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton
					type="button"
					variant="secondary"
					:disabled="saving"
					@click="$emit('close')">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="saving || selectedUsers.length === 0">
					{{ saving ? t('Saving...') : t('Save') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import type { CustomGroup, UserOption } from '../types'
import { t } from '../utils/l10n'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'saved'): void
}>()

const selectedUsers = ref<UserOption[]>([])
const availableUsers = ref<UserOption[]>([])
const userSearchQuery = ref('')
const loadingUsers = ref(false)
const saving = ref(false)

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen) {
			selectedUsers.value = []
			userSearchQuery.value = ''
			fetchUsers()
		}
	},
	{ immediate: true },
)

const filteredAvailableUsers = computed(() => {
	const currentGroupUids = new Set(props.group?.member_ids || [])
	const selectedUids = new Set(selectedUsers.value.map((u) => u.uid))
	const unselected = availableUsers.value.filter((u) => !currentGroupUids.has(u.uid) && !selectedUids.has(u.uid))

	const query = userSearchQuery.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)

	return unselected.filter((u) => {
		const nameMatch = u.displayName.toLowerCase().includes(query)
		const uidMatch = u.uid.toLowerCase().includes(query)
		const emailMatch = u.email ? u.email.toLowerCase().includes(query) : false
		return nameMatch || uidMatch || emailMatch
	})
})

async function fetchUsers() {
	loadingUsers.value = true
	try {
		const url = generateUrl('/apps/user_groups_hzs/api/v1/users')
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.users)) {
			availableUsers.value = response.data.users
		}
	} catch (err: unknown) {
		console.error('Error fetching Nextcloud users:', err)
	} finally {
		loadingUsers.value = false
	}
}

function addSelected(user: UserOption) {
	if (!selectedUsers.value.some((u) => u.uid === user.uid)) {
		selectedUsers.value.push({ ...user })
	}
}

function removeSelected(uid: string) {
	selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== uid)
}

async function submitForm() {
	if (selectedUsers.value.length === 0 || !props.group) return
	saving.value = true

	try {
		const existingIds = props.group.member_ids || []
		const newIds = selectedUsers.value.map((u) => u.uid)
		const allMemberIds = Array.from(new Set([...existingIds, ...newIds]))

		const url = generateUrl(`/apps/user_groups_hzs/api/v1/groups/${props.group.group_id}`)
		await axios.put(url, {
			name: props.group.name,
			memberIds: allMemberIds,
		})

		showSuccess(t('Members successfully added to group'))
		emit('saved')
		emit('close')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || t('Error adding members')
		showError(msg)
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.add-member-form {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-title {
	font-size: 20px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.form-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
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

.no-members-hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.search-user-wrapper {
	margin-top: 4px;
}

.loading-users,
.empty-users {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.available-users-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-height: 200px;
	overflow-y: auto;
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
	margin-top: 8px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
