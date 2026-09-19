<template>
	<NcContent app-name="customusergroups">
		<NcAppNavigation>
			<template #list>
				<!-- Create Group Button -->
				<div class="nav-header-actions">
					<NcButton
						type="primary"
						wide
						@click="openCreateModal">
						<template #icon>
							<span class="icon-plus">＋</span>
						</template>
						Создать группу
					</NcButton>
				</div>

				<!-- Search Groups Input -->
				<div class="nav-search-wrapper">
					<NcTextField
						v-model="groupSearchQuery"
						placeholder="Поиск групп..."
						size="small" />
				</div>

				<!-- Filter Categories -->
				<NcAppNavigationItem
					name="Все доступные"
					:active="currentFilter === 'all'"
					@click="currentFilter = 'all'">
					<template #counter>
						{{ allGroups.length }}
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					name="Созданные мной"
					:active="currentFilter === 'mine'"
					@click="currentFilter = 'mine'">
					<template #counter>
						{{ myGroups.length }}
					</template>
				</NcAppNavigationItem>

				<NcAppNavigationItem
					name="Группы со мной"
					:active="currentFilter === 'member'"
					@click="currentFilter = 'member'">
					<template #counter>
						{{ memberGroups.length }}
					</template>
				</NcAppNavigationItem>

				<div class="nav-divider" />

				<!-- Groups List -->
				<div v-if="loadingGroups" class="nav-loading">
					<NcLoadingIcon :size="24" /> Загрузка...
				</div>
				<div v-else-if="filteredGroups.length === 0" class="nav-empty">
					Группы не найдены
				</div>
				<div v-else class="groups-nav-list">
					<NcAppNavigationItem
						v-for="group in filteredGroups"
						:key="group.group_id"
						:name="group.name"
						:active="selectedGroupId === group.group_id"
						@click="selectGroup(group.group_id)">
						<template #counter>
							{{ group.member_count }}
						</template>
					</NcAppNavigationItem>
				</div>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<!-- Group Details -->
			<div v-if="selectedGroup" class="group-details-container">
				<header class="group-header">
					<div class="group-title-section">
						<h1 class="group-name">
							{{ selectedGroup.name }}
						</h1>
						<div class="group-meta">
							<span class="meta-tag id-tag">ID: {{ selectedGroup.group_id }}</span>
							<span class="meta-tag creator-tag">
								Создатель: {{ selectedGroup.creator_displayName }}
								<template v-if="selectedGroup.creator_email">({{ selectedGroup.creator_email }})</template>
							</span>
							<span class="meta-tag date-tag">Создана: {{ formatDate(selectedGroup.created_at) }}</span>
							<span v-if="selectedGroup.is_creator" class="role-badge creator-badge">Вы создатель</span>
							<span v-else-if="isAdmin" class="role-badge admin-badge">Вы Администратор</span>
							<span v-else-if="selectedGroup.permissions?.delegation_level === 'manage'" class="role-badge manage-badge">Вы управляющий</span>
							<span v-else-if="selectedGroup.permissions?.delegation_level === 'moderate'" class="role-badge moderate-badge">Вы модератор</span>
						</div>

						<!-- Delegations Metadata Section -->
						<div class="delegation-meta-box">
							<div class="delegation-line">
								<span class="delegation-label">Делегаты с правами «Управление»:</span>
								<span v-if="selectedGroup.delegates_manage?.length > 0" class="delegates-tags">
									<span
										v-for="d in selectedGroup.delegates_manage"
										:key="d.user_id"
										class="delegate-chip manage-chip">
										{{ d.displayName }} ({{ d.email || d.user_id }})
									</span>
								</span>
								<span v-else class="no-delegates">Нет делегатов</span>
							</div>

							<div class="delegation-line">
								<span class="delegation-label">Делегаты с правами «Модерация»:</span>
								<span v-if="selectedGroup.delegates_moderate?.length > 0" class="delegates-tags">
									<span
										v-for="d in selectedGroup.delegates_moderate"
										:key="d.user_id"
										class="delegate-chip moderate-chip">
										{{ d.displayName }} ({{ d.email || d.user_id }})
									</span>
								</span>
								<span v-else class="no-delegates">Нет делегатов</span>
							</div>
						</div>
					</div>

					<div class="group-header-actions">
						<NcButton
							v-if="selectedGroup.permissions?.can_delegate"
							type="tertiary"
							@click="openDelegationModal">
							Права управления
						</NcButton>
						<NcButton
							v-if="selectedGroup.permissions?.can_edit_members || selectedGroup.permissions?.can_edit_name"
							type="secondary"
							@click="openEditModal(selectedGroup)">
							Редактировать
						</NcButton>
						<NcButton
							v-if="selectedGroup.permissions?.can_delete"
							type="error"
							@click="openDeleteModal(selectedGroup)">
							Удалить
						</NcButton>
					</div>
				</header>

				<!-- Pending Membership Requests Section (for Owners, Delegates, Admin) -->
				<section v-if="selectedGroup.permissions?.can_moderate_requests" class="group-requests-section">
					<div class="requests-header">
						<h2>
							Запросы на добавление участников
							<span v-if="pendingRequestsCount > 0" class="pending-badge">{{ pendingRequestsCount }}</span>
						</h2>
						<NcButton
							type="tertiary-no-background"
							size="small"
							@click="fetchGroupRequests">
							Обновить заявки
						</NcButton>
					</div>

					<div v-if="loadingRequests" class="loading-requests">
						<NcLoadingIcon :size="20" /> Загрузка запросов...
					</div>
					<div v-else-if="groupRequests.length === 0" class="no-requests-hint">
						Нет активных запросов на рассмотрение
					</div>
					<div v-else class="requests-list">
						<div
							v-for="req in groupRequests"
							:key="req.id"
							class="request-card"
							:class="req.status">
							<div class="request-candidate">
								<span class="candidate-name">{{ req.candidate_displayName }}</span>
								<span class="candidate-email">{{ req.candidate_email || ('@' + req.candidate_id) }}</span>
							</div>

							<div class="request-meta">
								<span class="request-author">Запросил: {{ req.requester_displayName }}</span>
								<span class="request-date">{{ formatDate(req.created_at) }}</span>
							</div>

							<div class="request-status-actions">
								<template v-if="req.status === 'pending'">
									<NcButton
										type="primary"
										size="small"
										:disabled="processingRequestId === req.id"
										@click="approveRequest(req.id)">
										Принять
									</NcButton>
									<NcButton
										type="error"
										size="small"
										:disabled="processingRequestId === req.id"
										@click="rejectRequest(req.id)">
										Отклонить
									</NcButton>
								</template>
								<span v-else-if="req.status === 'approved'" class="status-badge status-approved">
									Принят
								</span>
								<span v-else-if="req.status === 'rejected'" class="status-badge status-rejected">
									Отклонен
								</span>
							</div>
						</div>
					</div>
				</section>

				<!-- Group Members Section -->
				<section class="group-members-section">
					<div class="members-header">
						<div class="members-header-title">
							<h2>Участники группы ({{ selectedGroup.member_count }})</h2>
							<NcButton
								v-if="selectedGroup.permissions?.can_request_member"
								type="tertiary"
								size="small"
								@click="openRequestMemberModal">
								+ Предложить участника
							</NcButton>
						</div>
						<div class="members-search-wrapper">
							<NcTextField
								v-model="memberSearchQuery"
								placeholder="Фильтр участников..."
								size="small" />
						</div>
					</div>

					<div v-if="filteredMembers.length === 0" class="empty-members-list">
						{{ selectedGroup.member_count === 0 ? 'В группе пока нет участников' : 'Участники по запросу не найдены' }}
					</div>
					<div v-else class="members-grid">
						<div
							v-for="member in filteredMembers"
							:key="member.uid"
							class="member-card">
							<div class="member-avatar">
								{{ member.displayName.charAt(0).toUpperCase() }}
							</div>
							<div class="member-info">
								<span class="member-display-name">{{ member.displayName }}</span>
								<span class="member-email">{{ member.email || ('@' + member.uid) }}</span>
							</div>

							<!-- Badges on card -->
							<div class="member-card-badges">
								<span
									v-if="member.uid === selectedGroup.creator_id"
									class="member-badge creator-tag">
									Создатель
								</span>
								<span
									v-if="getMemberDelegationLevel(member.uid) === 'manage'"
									class="member-badge manage-tag">
									Управление
								</span>
								<span
									v-else-if="getMemberDelegationLevel(member.uid) === 'moderate'"
									class="member-badge moderate-tag">
									Модерация
								</span>
							</div>
						</div>
					</div>
				</section>
			</div>

			<!-- Empty State -->
			<div v-else class="empty-state-wrapper">
				<NcEmptyContent
					name="Пользовательские группы"
					description="Выберите группу из списка слева или создайте новую для предоставления доступа к папкам и файлам">
					<template #action>
						<NcButton
							type="primary"
							@click="openCreateModal">
							Создать группу
						</NcButton>
					</template>
				</NcEmptyContent>
			</div>
		</NcAppContent>

		<!-- Create / Edit Modal -->
		<GroupModal
			:show="showGroupModal"
			:group="modalGroup"
			@close="showGroupModal = false"
			@saved="onGroupSaved" />

		<!-- Delegation Modal -->
		<DelegationModal
			v-if="selectedGroup"
			:show="showDelegationModal"
			:group="selectedGroup"
			@close="showDelegationModal = false"
			@updated="onDelegationUpdated" />

		<!-- Request Member Modal -->
		<RequestMemberModal
			v-if="selectedGroup"
			:show="showRequestModal"
			:group="selectedGroup"
			@close="showRequestModal = false"
			@submitted="onRequestSubmitted" />

		<!-- Delete Confirm Modal -->
		<ConfirmModal
			:show="showDeleteModal"
			title="Удалить группу"
			:message="`Вы уверены, что хотите удалить группу «${groupToDelete?.name}»? Это действие нельзя отменить.`"
			:loading="deleting"
			@close="showDeleteModal = false"
			@confirm="confirmDelete" />
	</NcContent>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import GroupModal from './components/GroupModal.vue'
import DelegationModal from './components/DelegationModal.vue'
import RequestMemberModal from './components/RequestMemberModal.vue'
import ConfirmModal from './components/ConfirmModal.vue'
import type { AppState, CustomGroup, MembershipRequest } from './types'

// Load initial state
const initialState = loadState<AppState>('customusergroups', 'customusergroups-state', {
	current_user_id: null,
	is_admin: false,
	groups: [],
})

const currentUserId = ref(initialState.current_user_id)
const isAdmin = ref(initialState.is_admin)
const allGroups = ref<CustomGroup[]>(initialState.groups || [])
const selectedGroupId = ref<string | null>(
	allGroups.value.length > 0 ? allGroups.value[0].group_id : null,
)

const currentFilter = ref<'all' | 'mine' | 'member'>('all')
const groupSearchQuery = ref('')
const memberSearchQuery = ref('')
const loadingGroups = ref(false)

// Modals state
const showGroupModal = ref(false)
const modalGroup = ref<CustomGroup | null>(null)
const showDelegationModal = ref(false)
const showRequestModal = ref(false)
const showDeleteModal = ref(false)
const groupToDelete = ref<CustomGroup | null>(null)
const deleting = ref(false)

// Requests state
const groupRequests = ref<MembershipRequest[]>([])
const loadingRequests = ref(false)
const processingRequestId = ref<number | null>(null)

// Filter groups
const myGroups = computed(() => {
	if (!currentUserId.value) return []
	return allGroups.value.filter((g) => g.creator_id === currentUserId.value)
})

const memberGroups = computed(() => {
	if (!currentUserId.value) return []
	return allGroups.value.filter((g) => g.member_ids.includes(currentUserId.value!))
})

const filteredGroups = computed(() => {
	let list = allGroups.value
	if (currentFilter.value === 'mine') {
		list = myGroups.value
	} else if (currentFilter.value === 'member') {
		list = memberGroups.value
	}

	const query = groupSearchQuery.value.trim().toLowerCase()
	if (query !== '') {
		list = list.filter(
			(g) =>
				g.name.toLowerCase().includes(query)
				|| g.group_id.toLowerCase().includes(query),
		)
	}

	return list
})

const selectedGroup = computed(() => {
	if (!selectedGroupId.value) return null
	return allGroups.value.find((g) => g.group_id === selectedGroupId.value) || null
})

const pendingRequestsCount = computed(() => {
	return groupRequests.value.filter((r) => r.status === 'pending').length
})

const filteredMembers = computed(() => {
	if (!selectedGroup.value) return []
	const query = memberSearchQuery.value.trim().toLowerCase()
	if (query === '') return selectedGroup.value.members
	return selectedGroup.value.members.filter(
		(m) =>
			m.displayName.toLowerCase().includes(query)
			|| m.uid.toLowerCase().includes(query)
			|| (m.email && m.email.toLowerCase().includes(query)),
	)
})

function getMemberDelegationLevel(uid: string): 'manage' | 'moderate' | null {
	if (!selectedGroup.value?.delegations) return null
	const del = selectedGroup.value.delegations.find((d) => d.user_id === uid)
	return del ? del.level : null
}

function selectGroup(groupId: string) {
	selectedGroupId.value = groupId
	memberSearchQuery.value = ''
}

watch(
	() => selectedGroupId.value,
	(newGid) => {
		if (newGid && selectedGroup.value?.permissions?.can_moderate_requests) {
			fetchGroupRequests()
		} else {
			groupRequests.value = []
		}
	},
	{ immediate: true },
)

function openCreateModal() {
	modalGroup.value = null
	showGroupModal.value = true
}

function openEditModal(group: CustomGroup) {
	modalGroup.value = group
	showGroupModal.value = true
}

function openDelegationModal() {
	showDelegationModal.value = true
}

function openRequestMemberModal() {
	showRequestModal.value = true
}

function openDeleteModal(group: CustomGroup) {
	groupToDelete.value = group
	showDeleteModal.value = true
}

function onGroupSaved(savedGroup: CustomGroup) {
	const index = allGroups.value.findIndex((g) => g.group_id === savedGroup.group_id)
	if (index !== -1) {
		allGroups.value[index] = savedGroup
	} else {
		allGroups.value.unshift(savedGroup)
	}
	selectedGroupId.value = savedGroup.group_id
	reloadGroups()
}

async function onDelegationUpdated() {
	await reloadGroups()
}

async function onRequestSubmitted() {
	if (selectedGroup.value?.permissions?.can_moderate_requests) {
		await fetchGroupRequests()
	}
}

async function fetchGroupRequests() {
	if (!selectedGroupId.value) return
	loadingRequests.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${selectedGroupId.value}/requests`)
		const res = await axios.get(url)
		if (res.data && Array.isArray(res.data.requests)) {
			groupRequests.value = res.data.requests
		}
	} catch (err) {
		console.error('Failed to fetch requests:', err)
	} finally {
		loadingRequests.value = false
	}
}

async function approveRequest(requestId: number) {
	if (!selectedGroupId.value) return
	processingRequestId.value = requestId
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${selectedGroupId.value}/requests/${requestId}/approve`)
		await axios.post(url)
		showSuccess('Запрос одобрен, пользователь добавлен в группу')
		await reloadGroups()
		await fetchGroupRequests()
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка одобрения запроса'
		showError(msg)
	} finally {
		processingRequestId.value = null
	}
}

async function rejectRequest(requestId: number) {
	if (!selectedGroupId.value) return
	processingRequestId.value = requestId
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${selectedGroupId.value}/requests/${requestId}/reject`)
		await axios.post(url)
		showSuccess('Запрос отклонен')
		await fetchGroupRequests()
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка отклонения запроса'
		showError(msg)
	} finally {
		processingRequestId.value = null
	}
}

async function confirmDelete() {
	if (!groupToDelete.value) return
	deleting.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${groupToDelete.value.group_id}`)
		await axios.delete(url)
		showSuccess('Группа успешно удалена')
		allGroups.value = allGroups.value.filter((g) => g.group_id !== groupToDelete.value?.group_id)
		if (selectedGroupId.value === groupToDelete.value.group_id) {
			selectedGroupId.value = allGroups.value.length > 0 ? allGroups.value[0].group_id : null
		}
		showDeleteModal.value = false
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка при удалении группы'
		showError(msg)
	} finally {
		deleting.value = false
	}
}

function formatDate(dateStr: string) {
	if (!dateStr) return ''
	try {
		const d = new Date(dateStr)
		return d.toLocaleDateString('ru-RU', {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		})
	} catch {
		return dateStr
	}
}

async function reloadGroups() {
	loadingGroups.value = true
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/groups')
		const res = await axios.get(url)
		if (res.data && Array.isArray(res.data.groups)) {
			allGroups.value = res.data.groups
			isAdmin.value = res.data.isAdmin
			currentUserId.value = res.data.currentUserId
		}
	} catch (err) {
		console.error('Failed to reload groups:', err)
	} finally {
		loadingGroups.value = false
	}
}

onMounted(() => {
	reloadGroups()
})
</script>

<style scoped>
.nav-header-actions {
	padding: 12px 14px 8px;
}

.icon-plus {
	font-weight: bold;
	margin-right: 4px;
}

.nav-search-wrapper {
	padding: 0 14px 10px;
}

.nav-divider {
	height: 1px;
	background-color: var(--color-border);
	margin: 8px 14px;
}

.nav-loading,
.nav-empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.groups-nav-list {
	display: flex;
	flex-direction: column;
}

.group-details-container {
	padding: 24px 32px;
	max-width: 1040px;
}

.group-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
	gap: 20px;
}

.group-title-section {
	display: flex;
	flex-direction: column;
	gap: 10px;
	flex: 1;
}

.group-name {
	font-size: 26px;
	font-weight: 700;
	color: var(--color-main-text);
	margin: 0;
}

.group-meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
}

.meta-tag {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	padding: 3px 8px;
	border-radius: 4px;
}

.role-badge {
	font-size: 11px;
	font-weight: 600;
	padding: 3px 8px;
	border-radius: 10px;
}

.creator-badge {
	background-color: var(--color-success-element);
	color: var(--color-success-element-text);
}

.admin-badge {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.manage-badge {
	background-color: var(--color-warning-element, #e29300);
	color: #fff;
}

.moderate-badge {
	background-color: var(--color-info-element, #0082c9);
	color: #fff;
}

.delegation-meta-box {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 14px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
	margin-top: 4px;
}

.delegation-line {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 13px;
}

.delegation-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.delegates-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.delegate-chip {
	font-size: 11px;
	font-weight: 500;
	padding: 2px 8px;
	border-radius: 12px;
}

.manage-chip {
	background-color: var(--color-warning-element-light, #fff2d6);
	color: var(--color-warning-element-light-text, #915d00);
	border: 1px solid var(--color-warning-element, #e29300);
}

.moderate-chip {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border: 1px solid var(--color-primary-element);
}

.no-delegates {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: 12px;
}

.group-header-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
}

/* Membership Requests Section */
.group-requests-section {
	margin-top: 24px;
	padding: 16px 20px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.requests-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.requests-header h2 {
	font-size: 16px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	gap: 8px;
}

.pending-badge {
	background-color: var(--color-error);
	color: #fff;
	font-size: 11px;
	font-weight: bold;
	padding: 1px 7px;
	border-radius: 10px;
}

.loading-requests,
.no-requests-hint {
	padding: 12px;
	text-align: center;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.requests-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.request-card {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 14px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
}

.request-candidate {
	display: flex;
	flex-direction: column;
}

.candidate-name {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-main-text);
}

.candidate-email {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.request-meta {
	display: flex;
	flex-direction: column;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.request-status-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
}

.status-approved {
	background-color: var(--color-success-element);
	color: #fff;
}

.status-rejected {
	background-color: var(--color-text-maxcontrast);
	color: #fff;
}

/* Members Section */
.group-members-section {
	margin-top: 24px;
}

.members-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.members-header-title {
	display: flex;
	align-items: center;
	gap: 14px;
}

.members-header h2 {
	font-size: 18px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.members-search-wrapper {
	width: 240px;
}

.empty-members-list {
	padding: 32px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.members-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 14px;
}

.member-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	position: relative;
}

.member-avatar {
	width: 36px;
	height: 36px;
	border-radius: 50%;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: 600;
	font-size: 16px;
	flex-shrink: 0;
}

.member-info {
	display: flex;
	flex-direction: column;
	overflow: hidden;
	flex: 1;
}

.member-display-name {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.member-email {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.member-card-badges {
	display: flex;
	flex-direction: column;
	gap: 4px;
	align-items: flex-end;
}

.member-badge {
	font-size: 10px;
	padding: 2px 6px;
	border-radius: 8px;
	font-weight: 600;
	white-space: nowrap;
}

.creator-tag {
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.manage-tag {
	background-color: var(--color-warning-element-light, #fff2d6);
	color: var(--color-warning-element-light-text, #915d00);
	border: 1px solid var(--color-warning-element, #e29300);
}

.moderate-tag {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	border: 1px solid var(--color-primary-element);
}

.empty-state-wrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	padding: 40px;
}
</style>
