<template>
	<div class="admin-settings-container">
		<!-- Section 1: Group Creation Restriction -->
		<NcSettingsSection
			name="Настройка пользовательских групп"
			description="Настройте, кто имеет право создавать новые пользовательские группы в системе.">
			<div class="settings-content">
				<NcCheckboxRadioSwitch
					v-model="createRestrictionEnabled"
					type="switch">
					Ограничить создание групп (разрешить только выбранным пользователям и группам)
				</NcCheckboxRadioSwitch>

				<div v-if="createRestrictionEnabled" class="restriction-details">
					<div class="info-callout">
						<strong>Примечание:</strong> Администраторы системы Nextcloud всегда имеют право создавать пользовательские группы, независимо от установленных ограничений.
					</div>

					<!-- Allowed Users Selection -->
					<div class="picker-group">
						<label class="picker-label">Разрешенные пользователи ({{ createAllowedUsers.length }})</label>

						<!-- Selected users list -->
						<div v-if="createAllowedUsersDetails.length > 0" class="selected-items-list">
							<div
								v-for="user in createAllowedUsersDetails"
								:key="user.uid"
								class="selected-item">
								<div class="item-info">
									<span class="item-primary">{{ user.displayName }}</span>
									<span class="item-secondary">{{ user.email || ('@' + user.uid) }}</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeCreateUser(user.uid)">
									- Удалить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							Пользователи пока не добавлены
						</div>

						<!-- Search users for creation -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="userSearchCreate"
								placeholder="Поиск пользователей Nextcloud для добавления..."
								:disabled="saving" />
						</div>

						<!-- Available users to add -->
						<div v-if="filteredAvailableCreateUsers.length > 0" class="available-items-list">
							<div
								v-for="user in filteredAvailableCreateUsers"
								:key="user.uid"
								class="available-item"
								@click="addCreateUser(user)">
								<div class="item-info">
									<span class="item-primary">{{ user.displayName }}</span>
									<span class="item-secondary">{{ user.email || ('@' + user.uid) }}</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addCreateUser(user)">
									+ Добавить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ userSearchCreate.trim() ? 'Пользователи не найдены' : 'Все доступные пользователи добавлены' }}
						</div>
					</div>

					<!-- Allowed Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">Разрешенные группы ({{ createAllowedGroups.length }})</label>

						<!-- Selected groups list -->
						<div v-if="createAllowedGroupsDetails.length > 0" class="selected-items-list">
							<div
								v-for="grp in createAllowedGroupsDetails"
								:key="grp.id"
								class="selected-item">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">Пользовательская группа</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeCreateGroup(grp.id)">
									- Удалить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							Группы пока не добавлены
						</div>

						<!-- Search groups for creation -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="groupSearchCreate"
								placeholder="Поиск групп Nextcloud для добавления..."
								:disabled="saving" />
						</div>

						<!-- Available groups to add -->
						<div v-if="filteredAvailableCreateGroups.length > 0" class="available-items-list">
							<div
								v-for="grp in filteredAvailableCreateGroups"
								:key="grp.id"
								class="available-item"
								@click="addCreateGroup(grp)">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">Пользовательская</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addCreateGroup(grp)">
									+ Добавить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ groupSearchCreate.trim() ? 'Группы не найдены' : 'Все доступные группы добавлены' }}
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>

		<!-- Section 2: App Access Restriction -->
		<NcSettingsSection
			name="Ограничение доступа к приложению"
			description="Настройте список пользователей и групп, которым запрещен доступ к веб-интерфейсу «Пользовательские группы».">
			<div class="settings-content">
				<NcCheckboxRadioSwitch
					v-model="accessRestrictionEnabled"
					type="switch">
					Ограничить доступ к приложению (запретить определенным пользователям и группам)
				</NcCheckboxRadioSwitch>

				<div v-if="accessRestrictionEnabled" class="restriction-details">
					<div class="info-callout">
						<strong>Примечание:</strong> Пользователи с запретом доступа не видят приложение в верхнем меню навигации и получают отказ при попытке прямого перехода. При этом они остаются полноценными участниками существующих групп и могут получать общий доступ к файлам и папкам.
					</div>

					<!-- Forbidden Users Selection -->
					<div class="picker-group">
						<label class="picker-label">Запрещенные пользователи ({{ accessForbiddenUsers.length }})</label>

						<!-- Selected forbidden users list -->
						<div v-if="accessForbiddenUsersDetails.length > 0" class="selected-items-list">
							<div
								v-for="user in accessForbiddenUsersDetails"
								:key="user.uid"
								class="selected-item item-forbidden">
								<div class="item-info">
									<span class="item-primary">{{ user.displayName }}</span>
									<span class="item-secondary">{{ user.email || ('@' + user.uid) }}</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeAccessUser(user.uid)">
									- Удалить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							Пользователи пока не добавлены
						</div>

						<!-- Search users for access restriction -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="userSearchAccess"
								placeholder="Поиск пользователей для ограничения доступа..."
								:disabled="saving" />
						</div>

						<!-- Available users to forbid -->
						<div v-if="filteredAvailableAccessUsers.length > 0" class="available-items-list">
							<div
								v-for="user in filteredAvailableAccessUsers"
								:key="user.uid"
								class="available-item"
								@click="addAccessUser(user)">
								<div class="item-info">
									<span class="item-primary">{{ user.displayName }}</span>
									<span class="item-secondary">{{ user.email || ('@' + user.uid) }}</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addAccessUser(user)">
									+ Запретить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ userSearchAccess.trim() ? 'Пользователи не найдены' : 'Все доступные пользователи уже добавлены' }}
						</div>
					</div>

					<!-- Forbidden Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">Запрещенные группы ({{ accessForbiddenGroups.length }})</label>

						<!-- Selected forbidden groups list -->
						<div v-if="accessForbiddenGroupsDetails.length > 0" class="selected-items-list">
							<div
								v-for="grp in accessForbiddenGroupsDetails"
								:key="grp.id"
								class="selected-item item-forbidden">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">Пользовательская группа</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeAccessGroup(grp.id)">
									- Удалить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							Группы пока не добавлены
						</div>

						<!-- Search groups for access restriction -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="groupSearchAccess"
								placeholder="Поиск групп для ограничения доступа..."
								:disabled="saving" />
						</div>

						<!-- Available groups to forbid -->
						<div v-if="filteredAvailableAccessGroups.length > 0" class="available-items-list">
							<div
								v-for="grp in filteredAvailableAccessGroups"
								:key="grp.id"
								class="available-item"
								@click="addAccessGroup(grp)">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">Пользовательская</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addAccessGroup(grp)">
									+ Запретить
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ groupSearchAccess.trim() ? 'Группы не найдены' : 'Все доступные группы уже добавлены' }}
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>

		<!-- Save Button and status -->
		<div class="actions-bar">
			<NcButton
				type="primary"
				:disabled="saving"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
				</template>
				{{ saving ? 'Сохранить настройки' : 'Сохранить настройки' }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import type { AdminSettingsData, AdminSettingsResponse, GroupOption, UserOption } from '../types'

const defaultSettings: AdminSettingsData = {
	create_restriction_enabled: false,
	create_allowed_users: [],
	create_allowed_groups: [],
	access_restriction_enabled: false,
	access_forbidden_users: [],
	access_forbidden_groups: [],
}

const initialData = loadState<AdminSettingsData>('customusergroups', 'customUserGroupsSettings', defaultSettings)

const createRestrictionEnabled = ref(initialData.create_restriction_enabled)
const createAllowedUsers = ref<string[]>(initialData.create_allowed_users || [])
const createAllowedGroups = ref<string[]>(initialData.create_allowed_groups || [])
const createAllowedUsersDetails = ref<UserOption[]>([])
const createAllowedGroupsDetails = ref<GroupOption[]>([])

const accessRestrictionEnabled = ref(initialData.access_restriction_enabled)
const accessForbiddenUsers = ref<string[]>(initialData.access_forbidden_users || [])
const accessForbiddenGroups = ref<string[]>(initialData.access_forbidden_groups || [])
const accessForbiddenUsersDetails = ref<UserOption[]>([])
const accessForbiddenGroupsDetails = ref<GroupOption[]>([])

const allSystemUsers = ref<UserOption[]>([])
const allSystemGroups = ref<GroupOption[]>([])
const saving = ref(false)

// Search fields
const userSearchCreate = ref('')
const groupSearchCreate = ref('')
const userSearchAccess = ref('')
const groupSearchAccess = ref('')

onMounted(async () => {
	await Promise.all([
		fetchSettings(),
		fetchAllUsers(),
		fetchAllGroups(),
	])
})

function mergeUsers(users: UserOption[]) {
	const existing = new Set(allSystemUsers.value.map((u) => u.uid))
	for (const u of users) {
		if (!existing.has(u.uid)) {
			allSystemUsers.value.push(u)
			existing.add(u.uid)
		}
	}
}

function mergeGroups(groups: GroupOption[]) {
	const existing = new Set(allSystemGroups.value.map((g) => g.id))
	for (const g of groups) {
		if (!existing.has(g.id)) {
			allSystemGroups.value.push(g)
			existing.add(g.id)
		}
	}
}

async function fetchSettings() {
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/admin/settings')
		const res = await axios.get<AdminSettingsResponse>(url)
		if (res.data && res.data.settings) {
			const s = res.data.settings
			createRestrictionEnabled.value = s.create_restriction_enabled
			createAllowedUsers.value = s.create_allowed_users || []
			createAllowedGroups.value = s.create_allowed_groups || []
			createAllowedUsersDetails.value = res.data.create_allowed_users_details || []
			createAllowedGroupsDetails.value = res.data.create_allowed_groups_details || []

			accessRestrictionEnabled.value = s.access_restriction_enabled
			accessForbiddenUsers.value = s.access_forbidden_users || []
			accessForbiddenGroups.value = s.access_forbidden_groups || []
			accessForbiddenUsersDetails.value = res.data.access_forbidden_users_details || []
			accessForbiddenGroupsDetails.value = res.data.access_forbidden_groups_details || []

			mergeUsers(createAllowedUsersDetails.value)
			mergeUsers(accessForbiddenUsersDetails.value)
			mergeGroups(createAllowedGroupsDetails.value)
			mergeGroups(accessForbiddenGroupsDetails.value)
		}
	} catch (err) {
		console.error('Failed to load settings:', err)
	}
}

async function fetchAllUsers() {
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/admin/users-search', { search: '', limit: 500 })
		const res = await axios.get<{ users: UserOption[] }>(url)
		if (res.data && Array.isArray(res.data.users)) {
			mergeUsers(res.data.users)
		}
	} catch (err) {
		console.error('Failed to preload users:', err)
	}
}

async function fetchAllGroups() {
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/admin/groups-list', { search: '', limit: 500 })
		const res = await axios.get<{ groups: GroupOption[] }>(url)
		if (res.data && Array.isArray(res.data.groups)) {
			mergeGroups(res.data.groups)
		}
	} catch (err) {
		console.error('Failed to preload groups:', err)
	}
}

// Filtered lists for Group Creation restriction
const filteredAvailableCreateUsers = computed(() => {
	const selected = new Set(createAllowedUsers.value)
	const unselected = allSystemUsers.value.filter((u) => !selected.has(u.uid))
	const query = userSearchCreate.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(u) =>
			u.displayName.toLowerCase().includes(query)
			|| u.uid.toLowerCase().includes(query)
			|| (u.email && u.email.toLowerCase().includes(query)),
	)
})

const filteredAvailableCreateGroups = computed(() => {
	const selected = new Set(createAllowedGroups.value)
	const unselected = allSystemGroups.value.filter((g) => !selected.has(g.id))
	const query = groupSearchCreate.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(g) =>
			g.name.toLowerCase().includes(query)
			|| g.id.toLowerCase().includes(query),
	)
})

// Filtered lists for App Access restriction
const filteredAvailableAccessUsers = computed(() => {
	const selected = new Set(accessForbiddenUsers.value)
	const unselected = allSystemUsers.value.filter((u) => !selected.has(u.uid))
	const query = userSearchAccess.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(u) =>
			u.displayName.toLowerCase().includes(query)
			|| u.uid.toLowerCase().includes(query)
			|| (u.email && u.email.toLowerCase().includes(query)),
	)
})

const filteredAvailableAccessGroups = computed(() => {
	const selected = new Set(accessForbiddenGroups.value)
	const unselected = allSystemGroups.value.filter((g) => !selected.has(g.id))
	const query = groupSearchAccess.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(g) =>
			g.name.toLowerCase().includes(query)
			|| g.id.toLowerCase().includes(query),
	)
})

function addCreateUser(user: UserOption) {
	if (!createAllowedUsers.value.includes(user.uid)) {
		createAllowedUsers.value.push(user.uid)
		createAllowedUsersDetails.value.push({ ...user })
	}
}

function removeCreateUser(uid: string) {
	createAllowedUsers.value = createAllowedUsers.value.filter((id) => id !== uid)
	createAllowedUsersDetails.value = createAllowedUsersDetails.value.filter((u) => u.uid !== uid)
}

function addCreateGroup(group: GroupOption) {
	if (!createAllowedGroups.value.includes(group.id)) {
		createAllowedGroups.value.push(group.id)
		createAllowedGroupsDetails.value.push({ ...group })
	}
}

function removeCreateGroup(gid: string) {
	createAllowedGroups.value = createAllowedGroups.value.filter((id) => id !== gid)
	createAllowedGroupsDetails.value = createAllowedGroupsDetails.value.filter((g) => g.id !== gid)
}

function addAccessUser(user: UserOption) {
	if (!accessForbiddenUsers.value.includes(user.uid)) {
		accessForbiddenUsers.value.push(user.uid)
		accessForbiddenUsersDetails.value.push({ ...user })
	}
}

function removeAccessUser(uid: string) {
	accessForbiddenUsers.value = accessForbiddenUsers.value.filter((id) => id !== uid)
	accessForbiddenUsersDetails.value = accessForbiddenUsersDetails.value.filter((u) => u.uid !== uid)
}

function addAccessGroup(group: GroupOption) {
	if (!accessForbiddenGroups.value.includes(group.id)) {
		accessForbiddenGroups.value.push(group.id)
		accessForbiddenGroupsDetails.value.push({ ...group })
	}
}

function removeAccessGroup(gid: string) {
	accessForbiddenGroups.value = accessForbiddenGroups.value.filter((id) => id !== gid)
	accessForbiddenGroupsDetails.value = accessForbiddenGroupsDetails.value.filter((g) => g.id !== gid)
}

async function save() {
	saving.value = true
	try {
		const payload: AdminSettingsData = {
			create_restriction_enabled: createRestrictionEnabled.value,
			create_allowed_users: createAllowedUsers.value,
			create_allowed_groups: createAllowedGroups.value,
			access_restriction_enabled: accessRestrictionEnabled.value,
			access_forbidden_users: accessForbiddenUsers.value,
			access_forbidden_groups: accessForbiddenGroups.value,
		}
		const url = generateUrl('/apps/customusergroups/api/v1/admin/settings')
		await axios.post(url, payload)
		showSuccess('Настройки успешно сохранены')
		await fetchSettings()
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка сохранения настроек'
		showError(msg)
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.admin-settings-container {
	padding: 30px;
	display: flex;
	flex-direction: column;
	gap: 14px;
	max-width: 900px;
}

:deep(.settings-section),
.settings-section {
	margin: 0 !important;
}

.settings-content {
	display: flex;
	flex-direction: column;
	gap: 16px;
	margin-top: 12px;
}

.restriction-details {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
	border: 1px solid var(--color-border);
}

.info-callout {
	font-size: 13px;
	line-height: 1.4;
	color: var(--color-text-maxcontrast);
	background: var(--color-main-background);
	padding: 10px 14px;
	border-radius: var(--border-radius-element);
	border-left: 3px solid var(--color-primary-element);
}

.picker-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.picker-label {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.search-input-wrapper {
	margin-top: 4px;
}

.selected-items-list,
.available-items-list {
	display: flex;
	flex-direction: column;
	max-height: 180px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
}

.selected-item,
.available-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.selected-item:last-child,
.available-item:last-child {
	border-bottom: none;
}

.selected-item:hover,
.available-item:hover {
	background-color: var(--color-background-hover);
}

.item-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.item-primary {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.item-secondary {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.item-forbidden .item-primary {
	color: var(--color-error, #e9322d);
}

.cug-badge {
	font-size: 10px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	padding: 2px 6px;
	border-radius: 8px;
	margin-top: 2px;
	display: inline-block;
	width: fit-content;
}

.empty-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 4px 0;
}

.actions-bar {
	margin-top: 8px;
	display: flex;
	align-items: center;
	gap: 12px;
}
</style>

<style>
/* Position Toast Notifications in the top-right corner below #header */
div[class*="_toastContainer_"],
div[class*="toastContainer"],
.toast-container {
	top: calc(var(--header-height, 50px) + 12px) !important;
	right: var(--body-container-margin, 20px) !important;
	bottom: auto !important;
	left: auto !important;
	align-items: flex-end !important;
	z-index: 100001 !important;
}
</style>
