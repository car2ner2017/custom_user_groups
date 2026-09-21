<template>
	<NcModal
		v-if="show"
		:name="''"
		size="normal"
		@close="$emit('close')">
		<form class="group-form" @submit.prevent="submitForm">
			<h2 class="form-title">
				{{ isEdit ? 'Редактировать группу' : 'Создать новую группу' }}
			</h2>

			<!-- Group Name Field -->
			<div class="form-group">
				<label for="group-name" class="form-label">
					Название группы <span class="required">*</span>
				</label>
				<NcTextField
					id="group-name"
					v-model="name"
					placeholder="Например, Команда проекта или Бухгалтерия"
					:disabled="loading || !canEditName"
					required />
				<small v-if="isEdit && !canEditName" class="help-text text-warning">
					Переименование группы доступно только создателю, администратору или управляющему.
				</small>
			</div>

			<!-- Group Owner Field (Transfer ownership) -->
			<div v-if="isEdit && canTransferOwnership" class="form-group">
				<label for="group-owner" class="form-label">
					Передать владение группой
				</label>
				<div class="current-owner-info">
					Текущий владелец: <strong>{{ currentOwnerDisplayName }}</strong> <span v-if="currentOwnerEmail">({{ currentOwnerEmail }})</span>
				</div>
				<select
					id="group-owner"
					v-model="selectedNewOwnerId"
					class="owner-select"
					:disabled="loading">
					<option value="">
						-- Без изменений --
					</option>
					<option
						v-for="user in selectedUsers"
						:key="user.uid"
						:value="user.uid"
						:disabled="user.uid === currentOwnerUid">
						{{ user.displayName }} ({{ user.email || ('@' + user.uid) }}){{ user.uid === currentOwnerUid ? ' (Текущий владелец)' : '' }}
					</option>
				</select>
			</div>

			<!-- Selected Group Members Section -->
			<div class="form-group">
				<label class="form-label">
					Участники группы ({{ selectedUsers.length }})
				</label>

				<!-- Filter input for already selected members (only in edit mode) -->
				<div v-if="isEdit && selectedUsers.length > 0" class="filter-selected-wrapper">
					<NcTextField
						v-model="selectedMembersFilter"
						placeholder="Поиск участников группы (по имени, email или логину)..."
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
							type="error"
							size="small"
							:disabled="loading"
							@click.stop="removeUser(user.uid)">
							- Удалить
						</NcButton>
					</div>
					<div v-if="filteredSelectedUsers.length === 0" class="no-filtered-selected">
						По запросу «{{ selectedMembersFilter }}» участники не найдены
					</div>
				</div>
				<div v-else class="no-members-hint">
					Участники еще не выбраны. Добавьте их из списка ниже.
				</div>

				<!-- Search users input for adding new members -->
				<div class="search-user-wrapper">
					<NcTextField
						v-model="userSearchQuery"
						placeholder="Поиск пользователей Nextcloud для добавления..."
						:disabled="loading" />
				</div>

				<!-- Available users list -->
				<div v-if="loadingUsers" class="loading-users">
					<NcLoadingIcon :size="20" /> Загрузка пользователей...
				</div>
				<div v-else-if="filteredAvailableUsers.length > 0" class="available-users-list">
					<div
						v-for="user in filteredAvailableUsers"
						:key="user.uid"
						class="user-item"
						@click="addUser(user)">
						<div class="user-item-info">
							<span class="user-displayname">{{ user.displayName }}</span>
							<span class="user-email-uid">{{ user.email || ('@' + user.uid) }}</span>
						</div>
						<NcButton
							type="tertiary"
							size="small"
							:disabled="loading"
							@click.stop="addUser(user)">
							+ Добавить
						</NcButton>
					</div>
				</div>
				<div v-else class="empty-users">
					{{ userSearchQuery.trim() !== '' ? 'Пользователи не найдены по запросу' : 'Все доступные пользователи уже выбраны' }}
				</div>
			</div>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton
					type="secondary"
					:disabled="loading"
					@click="$emit('close')">
					Отмена
				</NcButton>
				<NcButton
					type="primary"
					native-type="submit"
					:disabled="loading || name.trim() === ''">
					{{ isEdit ? 'Сохранить изменения' : 'Создать группу' }}
				</NcButton>
			</div>
		</form>

		<!-- Self-removal Warning Modal -->
		<ConfirmModal
			:show="showSelfRemoveConfirm"
			title="Предупреждение"
			message="Внимание: вы удаляете себя из этой пользовательской группы. После сохранения вы потеряете доступ к группе и делегированные права управления/модерации, если они были назначены."
			confirm-text="Удалить себя"
			@close="cancelSelfRemoval"
			@confirm="confirmSelfRemoval" />
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
import ConfirmModal from './ConfirmModal.vue'
import type { CustomGroup, UserOption } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup | null
	isAdmin?: boolean
	currentUserId?: string | null
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
const availableUsers = ref<UserOption[]>([])
const userSearchQuery = ref('')
const loading = ref(false)
const loadingUsers = ref(false)

const showSelfRemoveConfirm = ref(false)
const pendingRemoveUid = ref<string | null>(null)

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen) {
			if (props.group) {
				name.value = props.group.name
				selectedUsers.value = props.group.members.map((m) => ({ ...m }))
				selectedNewOwnerId.value = ''
			} else {
				name.value = ''
				selectedUsers.value = []
				selectedNewOwnerId.value = ''
			}
			selectedMembersFilter.value = ''
			userSearchQuery.value = ''
			fetchUsers()
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

const filteredAvailableUsers = computed(() => {
	const selectedUids = new Set(selectedUsers.value.map((u) => u.uid))
	const unselected = availableUsers.value.filter((u) => !selectedUids.has(u.uid))
	const query = userSearchQuery.value.trim().toLowerCase()
	if (query === '') return unselected
	return unselected.filter(
		(u) =>
			u.displayName.toLowerCase().includes(query)
			|| u.uid.toLowerCase().includes(query)
			|| (u.email && u.email.toLowerCase().includes(query)),
	)
})

async function fetchUsers() {
	loadingUsers.value = true
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/users', { search: '', limit: 500 })
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
		const isOwner = (uid === currentOwnerUid.value)
		// Owner cannot be removed without transferring ownership first
		if (isOwner && (!selectedNewOwnerId.value || selectedNewOwnerId.value === currentOwnerUid.value)) {
			showError('Владелец группы не может быть удален из списка участников. Для этого необходимо сначала передать владение группой другому участнику.')
			return
		}

		// Check if user is removing themselves and has manage or moderate role
		const isSelf = Boolean(props.currentUserId && uid === props.currentUserId)
		const userDelegationLevel = props.group.permissions?.delegation_level
		const isDelegate = props.group.delegations?.some((d) => d.user_id === uid)
			|| props.group.delegates_manage?.some((d) => d.user_id === uid)
			|| props.group.delegates_moderate?.some((d) => d.user_id === uid)
		const isManagerOrModerator = userDelegationLevel === 'manage' || userDelegationLevel === 'moderate' || isDelegate

		if (isSelf && (isManagerOrModerator || isOwner)) {
			pendingRemoveUid.value = uid
			showSelfRemoveConfirm.value = true
			return
		}
	}

	selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== uid)
	if (selectedNewOwnerId.value === uid) {
		selectedNewOwnerId.value = ''
	}
}

async function submitForm() {
	if (name.value.trim() === '') {
		showError('Пожалуйста, введите название группы')
		return
	}

	loading.value = true
	const memberIds = selectedUsers.value.map((u) => u.uid)

	try {
		if (isEdit.value && props.group) {
			const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}`)
			const payload: { name: string; memberIds: string[]; newOwnerId?: string } = {
				name: name.value.trim(),
				memberIds,
			}
			if (canTransferOwnership.value && selectedNewOwnerId.value && selectedNewOwnerId.value !== currentOwnerUid.value) {
				if (!selectedUsers.value.some((u) => u.uid === selectedNewOwnerId.value)) {
					showError('Выбранный новый владелец должен быть участником группы')
					loading.value = false
					return
				}
				payload.newOwnerId = selectedNewOwnerId.value
			}
			const response = await axios.put(url, payload)
			showSuccess('Группа успешно обновлена')
			emit('saved', response.data)
		} else {
			const url = generateUrl('/apps/customusergroups/api/v1/groups')
			const response = await axios.post(url, {
				name: name.value.trim(),
				memberIds,
			})
			showSuccess('Группа успешно создана')
			emit('saved', response.data)
		}
		emit('close')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Произошла ошибка при сохранении группы'
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
	color: var(--color-error);
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
	font-weight: 500;
	font-size: 13px;
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
