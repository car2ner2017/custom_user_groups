<template>
	<NcModal
		v-if="show"
		:name="isEdit ? 'Редактировать группу' : 'Создать новую группу'"
		size="normal"
		@close="$emit('close')">
		<form class="group-form" @submit.prevent="submitForm">
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

			<!-- Selected Group Members Section -->
			<div class="form-group">
				<label class="form-label">
					Участники группы ({{ selectedUsers.length }})
				</label>

				<!-- Filter input for already selected members -->
				<div v-if="selectedUsers.length > 0" class="filter-selected-wrapper">
					<NcTextField
						v-model="selectedMembersFilter"
						placeholder="Поиск участников группы (по имени, email или логину)..."
						size="small"
						:disabled="loading" />
				</div>

				<!-- Selected members chips with scroll and max height -->
				<div v-if="selectedUsers.length > 0" class="selected-chips">
					<div
						v-for="user in filteredSelectedUsers"
						:key="user.uid"
						class="user-chip">
						<span class="chip-name">
							{{ user.displayName }}
							<span class="chip-email">({{ user.email || user.uid }})</span>
						</span>
						<button
							type="button"
							class="chip-remove-btn"
							title="Удалить из группы"
							:disabled="loading"
							@click="removeUser(user.uid)">
							✕
						</button>
					</div>
					<div v-if="filteredSelectedUsers.length === 0" class="no-filtered-selected">
						По запросу «{{ selectedMembersFilter }}» участники не найдены
					</div>
				</div>
				<div v-else class="no-members-hint">
					Участники еще не выбраны. Вы можете найти и добавить их из списка ниже.
				</div>

				<!-- Search users input for adding new members -->
				<div class="search-user-wrapper">
					<NcTextField
						v-model="userSearchQuery"
						placeholder="Поиск пользователей Nextcloud для добавления..."
						:disabled="loading"
						@input="onSearchInput" />
				</div>

				<!-- Available users list -->
				<div v-if="loadingUsers" class="loading-users">
					<NcLoadingIcon :size="20" /> Поиск пользователей...
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
							size="small">
							+ Добавить
						</NcButton>
					</div>
				</div>
				<div v-else-if="userSearchQuery.trim() !== ''" class="empty-users">
					Пользователи не найдены
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

const props = defineProps<{
	show: boolean
	group: CustomGroup | null
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

const name = ref('')
const selectedUsers = ref<UserOption[]>([])
const selectedMembersFilter = ref('')
const availableUsers = ref<UserOption[]>([])
const userSearchQuery = ref('')
const loading = ref(false)
const loadingUsers = ref(false)
let searchTimeout: ReturnType<typeof setTimeout> | null = null

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen) {
			if (props.group) {
				name.value = props.group.name
				selectedUsers.value = props.group.members.map((m) => ({ ...m }))
			} else {
				name.value = ''
				selectedUsers.value = []
			}
			selectedMembersFilter.value = ''
			userSearchQuery.value = ''
			fetchUsers('')
		}
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
	return availableUsers.value.filter((u) => !selectedUids.has(u.uid))
})

const onSearchInput = () => {
	clearTimeout(searchTimeout)
	searchTimeout = setTimeout(() => {
		fetchUsers(userSearchQuery.value)
	}, 300)
}

async function fetchUsers(search: string) {
	loadingUsers.value = true
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/users', { search, limit: 30 })
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

function removeUser(uid: string) {
	selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== uid)
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
			const response = await axios.put(url, {
				name: name.value.trim(),
				memberIds,
			})
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

.required {
	color: var(--color-error);
}

.help-text {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.text-warning {
	color: var(--color-warning, #e29300);
}

.filter-selected-wrapper {
	margin-bottom: 6px;
}

.selected-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 8px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
	max-height: 160px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
}

.user-chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border-radius: 16px;
	font-size: 13px;
	max-width: 100%;
}

.chip-name {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.chip-email {
	font-size: 11px;
	opacity: 0.85;
	margin-left: 3px;
}

.chip-remove-btn {
	border: none;
	background: transparent;
	color: inherit;
	cursor: pointer;
	font-size: 12px;
	line-height: 1;
	padding: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0.7;
}

.chip-remove-btn:hover {
	opacity: 1;
}

.no-members-hint,
.no-filtered-selected {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 6px 0;
}

.search-user-wrapper {
	margin-top: 10px;
}

.available-users-list {
	display: flex;
	flex-direction: column;
	max-height: 160px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	margin-top: 4px;
}

.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}

.user-item:last-child {
	border-bottom: none;
}

.user-item:hover {
	background-color: var(--color-background-hover);
}

.user-item-info {
	display: flex;
	flex-direction: column;
}

.user-displayname {
	font-weight: 500;
	font-size: 13px;
	color: var(--color-main-text);
}

.user-email-uid {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.loading-users,
.empty-users {
	padding: 10px;
	text-align: center;
	font-size: 13px;
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
</style>
