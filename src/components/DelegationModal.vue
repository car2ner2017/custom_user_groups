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
				<h3>Назначенные делегаты ({{ localDelegations.length }})</h3>
			</div>

			<!-- Filter input for currently assigned delegates -->
			<div v-if="localDelegations.length > 0" class="filter-delegates-wrapper">
				<NcTextField
					v-model="delegatesFilter"
					placeholder="Поиск среди назначенных делегатов (по имени, email или логину)..."
					size="small" />
			</div>

			<div v-if="loadingDelegations" class="loading-state">
				<NcLoadingIcon :size="24" /> Загрузка делегатов...
			</div>
			<div v-else-if="localDelegations.length === 0" class="empty-state">
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
						<!-- If manager viewing another manager: only show static label, cannot change or revoke -->
						<template v-if="isManagerOnly && del.level === 'manage'">
							<span class="static-level-chip manage-chip">Управление</span>
						</template>

						<!-- If manager viewing a moderator: show static label and revoke button -->
						<template v-else-if="isManagerOnly && del.level === 'moderate'">
							<span class="static-level-chip moderate-chip">Модерация</span>
							<NcButton
								type="tertiary-no-background"
								size="small"
								title="Отозвать права"
								:disabled="saving"
								@click="revoke(del.user_id)">
								Отозвать
							</NcButton>
						</template>

						<!-- If owner or admin: allow selecting level or revoking -->
						<template v-else>
							<select
								class="level-select"
								:value="del.level"
								:disabled="saving"
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
								:disabled="saving"
								@click="revoke(del.user_id)">
								Отозвать
							</NcButton>
						</template>
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
					:disabled="saving" />
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
							v-if="!isManagerOnly"
							v-model="memberLevels[member.uid]"
							class="level-select"
							:disabled="saving">
							<option value="manage">
								Управление
							</option>
							<option value="moderate">
								Модерация
							</option>
						</select>
						<span v-else class="static-level-chip moderate-chip">
							Модерация
						</span>

						<NcButton
							type="tertiary"
							size="small"
							:disabled="saving"
							@click="assignRights(member)">
							Назначить права
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton
					type="secondary"
					:disabled="saving"
					@click="$emit('close')">
					Отмена
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || loadingDelegations || !hasChanges"
					@click="saveChanges">
					{{ saving ? 'Сохранение...' : 'Сохранить' }}
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
import type { CustomGroup, Delegation, UserOption } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup
	isAdmin?: boolean
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'updated'): void
}>()

const isOwnerOrAdmin = computed(() => {
	return Boolean(props.group?.is_owner || props.isAdmin)
})

const isManagerOnly = computed(() => {
	return !isOwnerOrAdmin.value && props.group?.permissions?.delegation_level === 'manage'
})

const initialDelegations = ref<Delegation[]>([])
const localDelegations = ref<Delegation[]>([])
const loadingDelegations = ref(false)
const saving = ref(false)
const delegatesFilter = ref('')
const memberSearchQuery = ref('')
const memberLevels = ref<Record<string, 'manage' | 'moderate'>>({})

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
	const defaultLevel = isManagerOnly.value ? 'moderate' : 'manage'
	const map: Record<string, 'manage' | 'moderate'> = {}
	for (const m of props.group.members) {
		const existingDel = localDelegations.value.find((d) => d.user_id === m.uid)
		map[m.uid] = existingDel ? existingDel.level : defaultLevel
	}
	memberLevels.value = map
}

const filteredDelegations = computed(() => {
	const query = delegatesFilter.value.trim().toLowerCase()
	if (!query) return localDelegations.value
	return localDelegations.value.filter((d) => {
		const nameMatch = d.displayName.toLowerCase().includes(query)
		const uidMatch = d.user_id.toLowerCase().includes(query)
		const emailMatch = d.email ? d.email.toLowerCase().includes(query) : false
		return nameMatch || uidMatch || emailMatch
	})
})

const filteredMembers = computed(() => {
	if (!props.group) return []
	const ownerId = props.group.owner_id || props.group.creator_id
	const delegatedUids = new Set(localDelegations.value.map((d) => d.user_id))
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

const hasChanges = computed(() => {
	if (loadingDelegations.value) return false

	const initialMap = new Map<string, 'manage' | 'moderate'>()
	for (const d of initialDelegations.value) {
		initialMap.set(d.user_id, d.level)
	}

	const localMap = new Map<string, 'manage' | 'moderate'>()
	for (const d of localDelegations.value) {
		localMap.set(d.user_id, d.level)
	}

	if (initialMap.size !== localMap.size) {
		return true
	}

	for (const [uid, level] of localMap.entries()) {
		if (!initialMap.has(uid) || initialMap.get(uid) !== level) {
			return true
		}
	}

	return false
})

async function fetchDelegations() {
	loadingDelegations.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.delegations)) {
			initialDelegations.value = response.data.delegations
			localDelegations.value = JSON.parse(JSON.stringify(response.data.delegations))
			initMemberLevels()
		}
	} catch (err: unknown) {
		console.error('Failed to load delegations:', err)
	} finally {
		loadingDelegations.value = false
	}
}

function onLevelChange(userId: string, newLevel: string) {
	if (newLevel !== 'manage' && newLevel !== 'moderate') return
	const target = localDelegations.value.find((d) => d.user_id === userId)
	if (target) {
		target.level = newLevel as 'manage' | 'moderate'
	}
}

function revoke(userId: string) {
	localDelegations.value = localDelegations.value.filter((d) => d.user_id !== userId)
}

function assignRights(member: UserOption) {
	if (localDelegations.value.some((d) => d.user_id === member.uid)) {
		return
	}
	const level = isManagerOnly.value ? 'moderate' : (memberLevels.value[member.uid] || 'manage')
	localDelegations.value.push({
		id: 0,
		group_id: props.group.group_id,
		user_id: member.uid,
		displayName: member.displayName,
		email: member.email || '',
		level,
		created_at: '',
	})
}

async function saveChanges() {
	// Diff initialDelegations and localDelegations
	const initialMap = new Map<string, 'manage' | 'moderate'>()
	for (const d of initialDelegations.value) {
		initialMap.set(d.user_id, d.level)
	}

	const localMap = new Map<string, 'manage' | 'moderate'>()
	for (const d of localDelegations.value) {
		localMap.set(d.user_id, d.level)
	}

	const toRevoke: string[] = []
	for (const [uid] of initialMap.entries()) {
		if (!localMap.has(uid)) {
			toRevoke.push(uid)
		}
	}

	const toSave: Array<{ userId: string; level: 'manage' | 'moderate' }> = []
	for (const [uid, level] of localMap.entries()) {
		const initialLevel = initialMap.get(uid)
		if (!initialLevel || initialLevel !== level) {
			toSave.push({ userId: uid, level })
		}
	}

	if (toRevoke.length === 0 && toSave.length === 0) {
		emit('close')
		return
	}

	saving.value = true
	try {
		const promises = []
		for (const uid of toRevoke) {
			const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations/${uid}`)
			promises.push(axios.delete(url))
		}
		for (const item of toSave) {
			const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
			promises.push(axios.post(url, {
				userId: item.userId,
				level: item.level,
			}))
		}

		await Promise.all(promises)
		showSuccess('Изменения успешно сохранены')
		emit('updated')
		emit('close')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка при сохранении изменений'
		showError(msg)
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.delegation-modal-content {
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

.static-level-chip {
	font-size: 12px;
	padding: 4px 10px;
	border-radius: 5px;
	font-weight: 500;
	display: inline-flex;
	align-items: center;
}

.static-level-chip.manage-chip {
	background-color: var(--color-warning-element-light, #fff2d6);
	color: inherit;
}

.static-level-chip.moderate-chip {
	background-color: var(--color-primary-element-light);
	color: inherit;
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
	gap: 12px;
	margin-top: 8px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
