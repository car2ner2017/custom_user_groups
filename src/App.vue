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
							<span class="meta-tag creator-tag">Создатель: {{ selectedGroup.creator_displayName }}</span>
							<span class="meta-tag date-tag">Создана: {{ formatDate(selectedGroup.created_at) }}</span>
							<span v-if="selectedGroup.is_creator" class="role-badge creator-badge">Вы создатель</span>
							<span v-else-if="isAdmin" class="role-badge admin-badge">Вы Администратор</span>
						</div>
					</div>

					<div v-if="selectedGroup.can_edit" class="group-header-actions">
						<NcButton
							type="secondary"
							@click="openEditModal(selectedGroup)">
							Редактировать
						</NcButton>
						<NcButton
							type="error"
							@click="openDeleteModal(selectedGroup)">
							Удалить
						</NcButton>
					</div>
				</header>

				<section class="group-members-section">
					<div class="members-header">
						<h2>Участники группы ({{ selectedGroup.member_count }})</h2>
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
								<span class="member-uid">@{{ member.uid }}</span>
							</div>
							<span
								v-if="member.uid === selectedGroup.creator_id"
								class="member-creator-tag">
								Создатель
							</span>
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
import { ref, computed, onMounted } from 'vue'
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
import ConfirmModal from './components/ConfirmModal.vue'
import type { AppState, CustomGroup } from './types'

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
const showDeleteModal = ref(false)
const groupToDelete = ref<CustomGroup | null>(null)
const deleting = ref(false)

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

const filteredMembers = computed(() => {
	if (!selectedGroup.value) return []
	const query = memberSearchQuery.value.trim().toLowerCase()
	if (query === '') return selectedGroup.value.members
	return selectedGroup.value.members.filter(
		(m) =>
			m.displayName.toLowerCase().includes(query)
			|| m.uid.toLowerCase().includes(query),
	)
})

function selectGroup(groupId: string) {
	selectedGroupId.value = groupId
	memberSearchQuery.value = ''
}

function openCreateModal() {
	modalGroup.value = null
	showGroupModal.value = true
}

function openEditModal(group: CustomGroup) {
	modalGroup.value = group
	showGroupModal.value = true
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
	if (allGroups.value.length === 0) {
		reloadGroups()
	}
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
	max-width: 1000px;
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
	gap: 8px;
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

.group-header-actions {
	display: flex;
	gap: 10px;
}

.group-members-section {
	margin-top: 24px;
}

.members-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
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
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
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
}

.member-info {
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.member-display-name {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.member-uid {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.member-creator-tag {
	margin-left: auto;
	font-size: 10px;
	padding: 2px 6px;
	border-radius: 8px;
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.empty-state-wrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	padding: 40px;
}
</style>
