<template>
	<NcModal
		v-if="show"
		name="Запрос на добавление пользователя в группу"
		size="normal"
		@close="$emit('close')">
		<div class="request-modal-content">
			<p class="request-description">
				Вы можете предложить добавить пользователя Nextcloud в группу «{{ group.name }}».
				Запрос поступит на рассмотрение создателю и модераторам группы.
			</p>

			<div class="form-group">
				<label class="form-label">
					Поиск пользователя <span class="required">*</span>
				</label>
				<NcTextField
					v-model="searchQuery"
					placeholder="Введите имя, email или логин пользователя..."
					:disabled="submitting"
					@input="onSearchInput" />
			</div>

			<div v-if="loadingUsers" class="loading-state">
				<NcLoadingIcon :size="20" /> Поиск пользователей...
			</div>
			<div v-else-if="filteredUsers.length > 0" class="users-list">
				<div
					v-for="user in filteredUsers"
					:key="user.uid"
					class="user-row"
					:class="{ selected: selectedCandidate?.uid === user.uid }"
					@click="selectedCandidate = user">
					<div class="user-row-info">
						<span class="user-name">{{ user.displayName }}</span>
						<span class="user-email">{{ user.email || ('@' + user.uid) }}</span>
					</div>
					<NcButton
						:type="selectedCandidate?.uid === user.uid ? 'primary' : 'tertiary'"
						size="small">
						{{ selectedCandidate?.uid === user.uid ? 'Выбран' : 'Выбрать' }}
					</NcButton>
				</div>
			</div>
			<div v-else-if="searchQuery.trim() !== ''" class="empty-state">
				Пользователи не найдены или уже состоят в этой группе
			</div>

			<div class="modal-actions">
				<NcButton
					type="secondary"
					:disabled="submitting"
					@click="$emit('close')">
					Отмена
				</NcButton>
				<NcButton
					type="primary"
					:disabled="submitting || !selectedCandidate"
					@click="submit">
					{{ submitting ? 'Отправка...' : 'Отправить запрос' }}
				</NcButton>
			</div>
		</div>
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
	group: CustomGroup
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'submitted'): void
}>()

const searchQuery = ref('')
const availableUsers = ref<UserOption[]>([])
const selectedCandidate = ref<UserOption | null>(null)
const loadingUsers = ref(false)
const submitting = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen) {
			searchQuery.value = ''
			selectedCandidate.value = null
			fetchUsers('')
		}
	},
	{ immediate: true },
)

const filteredUsers = computed(() => {
	if (!props.group) return []
	const existingUids = new Set(props.group.member_ids)
	return availableUsers.value.filter((u) => !existingUids.has(u.uid))
})

const onSearchInput = () => {
	clearTimeout(searchTimer)
	searchTimer = setTimeout(() => {
		fetchUsers(searchQuery.value)
	}, 300)
}

async function fetchUsers(search: string) {
	loadingUsers.value = true
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/users', { search, limit: 30 })
		const res = await axios.get(url)
		if (res.data && Array.isArray(res.data.users)) {
			availableUsers.value = res.data.users
		}
	} catch (err) {
		console.error('Failed to search users:', err)
	} finally {
		loadingUsers.value = false
	}
}

async function submit() {
	if (!selectedCandidate.value || !props.group) return
	submitting.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/requests`)
		await axios.post(url, {
			candidateId: selectedCandidate.value.uid,
		})
		showSuccess('Запрос на добавление пользователя успешно отправлен')
		emit('submitted')
		emit('close')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка отправки запроса'
		showError(msg)
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
.request-modal-content {
	padding: 16px 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.request-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.form-label {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
}

.required {
	color: var(--color-error);
}

.loading-state,
.empty-state {
	padding: 12px;
	text-align: center;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.users-list {
	display: flex;
	flex-direction: column;
	max-height: 200px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.user-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border);
}

.user-row:last-child {
	border-bottom: none;
}

.user-row:hover,
.user-row.selected {
	background-color: var(--color-background-hover);
}

.user-row.selected {
	border-left: 3px solid var(--color-primary-element);
}

.user-row-info {
	display: flex;
	flex-direction: column;
}

.user-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.user-email {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 8px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
</style>

