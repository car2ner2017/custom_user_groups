<template>
	<NcModal
		v-if="show"
		:name="''"
		size="normal"
		@close="$emit('close')">
		<div class="delegation-modal-content">
			<h2 class="form-title">
				Делегирование прав управления группой
			</h2>

			<p class="delegation-description">
				Вы можете делегировать права управления или модерации группы <strong>только действующим участникам</strong> этой группы.
			</p>

			<!-- Current Delegations Section -->
			<div class="section-title">
				<h3>Назначенные делегаты ({{ delegations.length }})</h3>
			</div>

			<!-- Filter input for currently assigned delegates -->
			<div v-if="delegations.length > 0" class="filter-delegates-wrapper">
				<NcTextField
					v-model="delegatesFilter"
					placeholder="Поиск среди назначенных делегатов (по имени, email или логину)..."
					size="small" />
			</div>

			<div v-if="loadingDelegations" class="loading-state">
				<NcLoadingIcon :size="24" /> Загрузка делегатов...
			</div>
			<div v-else-if="delegations.length === 0" class="empty-state">
				В группе пока нет назначенных делегатов.
			</div>
			<div v-else-if="filteredDelegations.length === 0" class="empty-state">
				По запросу «{{ delegatesFilter }}» назначенные делегаты не найдены.
			</div>
			<div v-else class="delegates-list">
				<div
					v-for="del in filteredDelegations"
					:key="del.user_id"
					class="delegate-card">
					<div class="delegate-info">
						<span class="delegate-name">{{ del.displayName }}</span>
						<span class="delegate-email">{{ del.email || ('@' + del.user_id) }}</span>
					</div>

					<div class="delegate-actions">
						<select
							class="level-select"
							:value="del.level"
							:disabled="updatingUid === del.user_id"
							@change="onLevelChange(del.user_id, ($event.target as HTMLSelectElement).value)">
							<option value="manage">
								Управление
							</option>
							<option value="moderate">
								Модерация
							</option>
						</select>

						<NcButton
							type="tertiary-no-background"
							size="small"
							title="Отозвать права"
							:disabled="updatingUid === del.user_id"
							@click="revoke(del.user_id)">
							Отозвать
						</NcButton>
					</div>
				</div>
			</div>

			<div class="divider" />

			<!-- Assign Rights Section -->
			<div class="section-title">
				<h3>Назначить делегата</h3>
			</div>

			<!-- Search filter for members -->
			<div class="search-member-wrapper">
				<NcTextField
					v-model="memberSearchQuery"
					placeholder="Поиск среди участников группы для назначения прав..."
					:disabled="assigningUid !== null" />
			</div>

			<!-- Candidate Members List -->
			<div v-if="filteredMembers.length === 0" class="empty-state">
				{{ memberSearchQuery.trim() ? 'Участники не найдены по запросу «' + memberSearchQuery + '»' : 'В группе нет участников, доступных для делегирования' }}
			</div>
			<div v-else class="available-users-list">
				<div
					v-for="member in filteredMembers"
					:key="member.uid"
					class="user-item">
					<div class="user-item-info">
						<span class="user-displayname">
							{{ member.displayName }}
						</span>
						<span class="user-email-uid">{{ member.email || ('@' + member.uid) }}</span>
					</div>

					<div class="member-assign-controls">
						<select
							v-model="memberLevels[member.uid]"
							class="level-select"
							:disabled="assigningUid === member.uid">
							<option value="manage">
								Управление
							</option>
							<option value="moderate">
								Модерация
							</option>
						</select>

						<NcButton
							type="tertiary"
							size="small"
							:disabled="assigningUid === member.uid"
							@click="assignRights(member.uid)">
							{{ assigningUid === member.uid ? 'Сохранение...' : 'Назначить права' }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton
					type="secondary"
					@click="$emit('close')">
					Закрыть
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
import type { CustomGroup, Delegation } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'updated'): void
}>()

const delegations = ref<Delegation[]>([])
const loadingDelegations = ref(false)
const delegatesFilter = ref('')
const memberSearchQuery = ref('')
const memberLevels = ref<Record<string, 'manage' | 'moderate'>>({})
const assigningUid = ref<string | null>(null)
const updatingUid = ref<string | null>(null)

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen && props.group) {
			delegatesFilter.value = ''
			memberSearchQuery.value = ''
			initMemberLevels()
			fetchDelegations()
		}
	},
	{ immediate: true },
)

function initMemberLevels() {
	if (!props.group) return
	const map: Record<string, 'manage' | 'moderate'> = {}
	for (const m of props.group.members) {
		const existingDel = delegations.value.find((d) => d.user_id === m.uid)
		map[m.uid] = existingDel ? existingDel.level : 'manage'
	}
	memberLevels.value = map
}

const filteredDelegations = computed(() => {
	const query = delegatesFilter.value.trim().toLowerCase()
	if (!query) return delegations.value
	return delegations.value.filter((d) => {
		const nameMatch = d.displayName.toLowerCase().includes(query)
		const uidMatch = d.user_id.toLowerCase().includes(query)
		const emailMatch = d.email ? d.email.toLowerCase().includes(query) : false
		return nameMatch || uidMatch || emailMatch
	})
})

const filteredMembers = computed(() => {
	if (!props.group) return []
	const ownerId = props.group.owner_id || props.group.creator_id
	const delegatedUids = new Set(delegations.value.map((d) => d.user_id))
	// Exclude owner and any member who already has delegated rights
	const members = props.group.members.filter((m) => m.uid !== ownerId && !delegatedUids.has(m.uid))
	const query = memberSearchQuery.value.trim().toLowerCase()
	if (!query) return members
	return members.filter((m) => {
		const nameMatch = m.displayName.toLowerCase().includes(query)
		const uidMatch = m.uid.toLowerCase().includes(query)
		const emailMatch = m.email ? m.email.toLowerCase().includes(query) : false
		return nameMatch || uidMatch || emailMatch
	})
})

async function fetchDelegations() {
	loadingDelegations.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.delegations)) {
			delegations.value = response.data.delegations
			initMemberLevels()
		}
	} catch (err: unknown) {
		console.error('Failed to load delegations:', err)
	} finally {
		loadingDelegations.value = false
	}
}

async function onLevelChange(userId: string, newLevel: string) {
	if (newLevel !== 'manage' && newLevel !== 'moderate') return
	updatingUid.value = userId
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.post(url, {
			userId,
			level: newLevel,
		})
		const idx = delegations.value.findIndex((d) => d.user_id === userId)
		if (idx !== -1) {
			delegations.value[idx] = response.data
		}
		if (memberLevels.value[userId]) {
			memberLevels.value[userId] = newLevel as 'manage' | 'moderate'
		}
		showSuccess('Уровень прав обновлен')
		emit('updated')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка обновления уровня'
		showError(msg)
		fetchDelegations()
	} finally {
		updatingUid.value = null
	}
}

async function revoke(userId: string) {
	updatingUid.value = userId
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations/${userId}`)
		await axios.delete(url)
		delegations.value = delegations.value.filter((d) => d.user_id !== userId)
		showSuccess('Делегирование прав успешно отозвано')
		emit('updated')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка при отзыве прав'
		showError(msg)
	} finally {
		updatingUid.value = null
	}
}

async function assignRights(uid: string) {
	if (delegations.value.some((d) => d.user_id === uid)) {
		return
	}
	const level = memberLevels.value[uid] || 'manage'
	assigningUid.value = uid
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.post(url, {
			userId: uid,
			level,
		})
		const idx = delegations.value.findIndex((d) => d.user_id === uid)
		if (idx !== -1) {
			delegations.value[idx] = response.data
		} else {
			delegations.value.push(response.data)
		}
		showSuccess('Права успешно делегированы')
		emit('updated')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка при назначении прав'
		showError(msg)
	} finally {
		assigningUid.value = null
	}
}
</script>

<style scoped>
.delegation-modal-content {
	padding: 16px 20px;
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

.delegation-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.section-title h3 {
	font-size: 15px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.filter-delegates-wrapper,
.search-member-wrapper {
	margin-bottom: 4px;
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

.delegates-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 180px;
	overflow-y: auto;
}

.delegate-card {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
}

.delegate-info {
	display: flex;
	flex-direction: column;
}

.delegate-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.delegate-email {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.delegate-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.level-select {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.divider {
	height: 1px;
	background-color: var(--color-border);
	margin: 4px 0;
}

.available-users-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 240px;
	overflow-y: auto;
}

.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
}

.user-item-info {
	display: flex;
	flex-direction: column;
}

.user-displayname {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.already-delegated-tag {
	font-size: 11px;
	font-weight: normal;
	color: var(--color-primary-element);
	margin-left: 4px;
}

.user-email-uid {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.member-assign-controls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 8px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
</style>
