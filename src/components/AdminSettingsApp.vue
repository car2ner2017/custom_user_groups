<template>
	<div class="admin-settings-container">
		<!-- Section 1: Group Creation Restriction -->
		<NcSettingsSection
			title="Ограничение создания пользовательских групп"
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
						<div class="search-picker-input">
							<NcTextField
								v-model="userSearchCreate"
								placeholder="Поиск пользователей Nextcloud для добавления..."
								size="small" />
						</div>

						<div v-if="userSearchCreate.trim() && searchUsersCreateList.length > 0" class="search-dropdown-list">
							<div
								v-for="user in searchUsersCreateList"
								:key="user.uid"
								class="dropdown-item"
								@click="addCreateUser(user)">
								<span class="user-display">{{ user.displayName }}</span>
								<span class="user-sub">({{ user.email || user.uid }})</span>
								<NcButton type="tertiary" size="small">
									+ Добавить
								</NcButton>
							</div>
						</div>

						<div v-if="createAllowedUsersDetails.length > 0" class="selected-chips-grid">
							<div
								v-for="u in createAllowedUsersDetails"
								:key="u.uid"
								class="chip user-chip">
								<span>{{ u.displayName }} ({{ u.email || u.uid }})</span>
								<button type="button" class="chip-remove" @click="removeCreateUser(u.uid)">
									✕
								</button>
							</div>
						</div>
						<div v-else class="empty-hint">
							Пользователи пока не добавлены
						</div>
					</div>

					<!-- Allowed Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">Разрешенные группы ({{ createAllowedGroups.length }})</label>
						<div class="search-picker-input">
							<NcTextField
								v-model="groupSearchCreate"
								placeholder="Поиск групп (локальных или пользовательских)..."
								size="small" />
						</div>

						<div v-if="groupSearchCreate.trim() && searchGroupsCreateList.length > 0" class="search-dropdown-list">
							<div
								v-for="grp in searchGroupsCreateList"
								:key="grp.id"
								class="dropdown-item"
								@click="addCreateGroup(grp)">
								<span class="group-display">{{ grp.name }}</span>
								<span v-if="grp.is_cug" class="cug-badge">Пользовательская</span>
								<NcButton type="tertiary" size="small">
									+ Добавить
								</NcButton>
							</div>
						</div>

						<div v-if="createAllowedGroupsDetails.length > 0" class="selected-chips-grid">
							<div
								v-for="g in createAllowedGroupsDetails"
								:key="g.id"
								class="chip group-chip">
								<span>{{ g.name }}</span>
								<span v-if="g.is_cug" class="cug-badge-small">CUG</span>
								<button type="button" class="chip-remove" @click="removeCreateGroup(g.id)">
									✕
								</button>
							</div>
						</div>
						<div v-else class="empty-hint">
							Группы пока не добавлены
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>

		<!-- Section 2: App Access Restriction -->
		<NcSettingsSection
			title="Ограничение доступа к приложению"
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
						<div class="search-picker-input">
							<NcTextField
								v-model="userSearchAccess"
								placeholder="Поиск пользователей для ограничения доступа..."
								size="small" />
						</div>

						<div v-if="userSearchAccess.trim() && searchUsersAccessList.length > 0" class="search-dropdown-list">
							<div
								v-for="user in searchUsersAccessList"
								:key="user.uid"
								class="dropdown-item"
								@click="addAccessUser(user)">
								<span class="user-display">{{ user.displayName }}</span>
								<span class="user-sub">({{ user.email || user.uid }})</span>
								<NcButton type="tertiary" size="small">
									+ Запретить
								</NcButton>
							</div>
						</div>

						<div v-if="accessForbiddenUsersDetails.length > 0" class="selected-chips-grid">
							<div
								v-for="u in accessForbiddenUsersDetails"
								:key="u.uid"
								class="chip user-chip chip-forbidden">
								<span>{{ u.displayName }} ({{ u.email || u.uid }})</span>
								<button type="button" class="chip-remove" @click="removeAccessUser(u.uid)">
									✕
								</button>
							</div>
						</div>
						<div v-else class="empty-hint">
							Пользователи пока не добавлены
						</div>
					</div>

					<!-- Forbidden Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">Запрещенные группы ({{ accessForbiddenGroups.length }})</label>
						<div class="search-picker-input">
							<NcTextField
								v-model="groupSearchAccess"
								placeholder="Поиск групп для ограничения доступа..."
								size="small" />
						</div>

						<div v-if="groupSearchAccess.trim() && searchGroupsAccessList.length > 0" class="search-dropdown-list">
							<div
								v-for="grp in searchGroupsAccessList"
								:key="grp.id"
								class="dropdown-item"
								@click="addAccessGroup(grp)">
								<span class="group-display">{{ grp.name }}</span>
								<span v-if="grp.is_cug" class="cug-badge">Пользовательская</span>
								<NcButton type="tertiary" size="small">
									+ Запретить
								</NcButton>
							</div>
						</div>

						<div v-if="accessForbiddenGroupsDetails.length > 0" class="selected-chips-grid">
							<div
								v-for="g in accessForbiddenGroupsDetails"
								:key="g.id"
								class="chip group-chip chip-forbidden">
								<span>{{ g.name }}</span>
								<span v-if="g.is_cug" class="cug-badge-small">CUG</span>
								<button type="button" class="chip-remove" @click="removeAccessGroup(g.id)">
									✕
								</button>
							</div>
						</div>
						<div v-else class="empty-hint">
							Группы пока не добавлены
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
				{{ saving ? 'Сохранение...' : 'Сохранить настройки' }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
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

const allSystemGroups = ref<GroupOption[]>([])
const saving = ref(false)

// Searches
const userSearchCreate = ref('')
const usersFoundCreate = ref<UserOption[]>([])
const groupSearchCreate = ref('')

const userSearchAccess = ref('')
const usersFoundAccess = ref<UserOption[]>([])
const groupSearchAccess = ref('')

let timerUserCreate: ReturnType<typeof setTimeout> | null = null
let timerUserAccess: ReturnType<typeof setTimeout> | null = null

onMounted(async () => {
	await Promise.all([
		fetchSettings(),
		fetchAllGroups(),
	])
})

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
		}
	} catch (err) {
		console.error('Failed to load settings:', err)
	}
}

async function fetchAllGroups() {
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/admin/groups-list')
		const res = await axios.get<{ groups: GroupOption[] }>(url)
		if (res.data && Array.isArray(res.data.groups)) {
			allSystemGroups.value = res.data.groups
		}
	} catch (err) {
		console.error('Failed to load system groups:', err)
	}
}

// Watch user search for creation
watch(userSearchCreate, (query) => {
	if (timerUserCreate) clearTimeout(timerUserCreate)
	timerUserCreate = setTimeout(async () => {
		const q = query.trim()
		if (!q) {
			usersFoundCreate.value = []
			return
		}
		try {
			const url = generateUrl('/apps/customusergroups/api/v1/admin/users-search', { search: q })
			const res = await axios.get<{ users: UserOption[] }>(url)
			usersFoundCreate.value = res.data.users || []
		} catch (err) {
			console.error('Failed to search users:', err)
		}
	}, 300)
})

const searchUsersCreateList = computed(() => {
	const selected = new Set(createAllowedUsers.value)
	return usersFoundCreate.value.filter((u) => !selected.has(u.uid))
})

const searchGroupsCreateList = computed(() => {
	const q = groupSearchCreate.value.trim().toLowerCase()
	if (!q) return []
	const selected = new Set(createAllowedGroups.value)
	return allSystemGroups.value.filter(
		(g) => !selected.has(g.id) && (g.name.toLowerCase().includes(q) || g.id.toLowerCase().includes(q)),
	)
})

function addCreateUser(user: UserOption) {
	if (!createAllowedUsers.value.includes(user.uid)) {
		createAllowedUsers.value.push(user.uid)
		createAllowedUsersDetails.value.push(user)
	}
	userSearchCreate.value = ''
	usersFoundCreate.value = []
}

function removeCreateUser(uid: string) {
	createAllowedUsers.value = createAllowedUsers.value.filter((id) => id !== uid)
	createAllowedUsersDetails.value = createAllowedUsersDetails.value.filter((u) => u.uid !== uid)
}

function addCreateGroup(group: GroupOption) {
	if (!createAllowedGroups.value.includes(group.id)) {
		createAllowedGroups.value.push(group.id)
		createAllowedGroupsDetails.value.push(group)
	}
	groupSearchCreate.value = ''
}

function removeCreateGroup(gid: string) {
	createAllowedGroups.value = createAllowedGroups.value.filter((id) => id !== gid)
	createAllowedGroupsDetails.value = createAllowedGroupsDetails.value.filter((g) => g.id !== gid)
}

// Watch user search for access restriction
watch(userSearchAccess, (query) => {
	if (timerUserAccess) clearTimeout(timerUserAccess)
	timerUserAccess = setTimeout(async () => {
		const q = query.trim()
		if (!q) {
			usersFoundAccess.value = []
			return
		}
		try {
			const url = generateUrl('/apps/customusergroups/api/v1/admin/users-search', { search: q })
			const res = await axios.get<{ users: UserOption[] }>(url)
			usersFoundAccess.value = res.data.users || []
		} catch (err) {
			console.error('Failed to search users:', err)
		}
	}, 300)
})

const searchUsersAccessList = computed(() => {
	const selected = new Set(accessForbiddenUsers.value)
	return usersFoundAccess.value.filter((u) => !selected.has(u.uid))
})

const searchGroupsAccessList = computed(() => {
	const q = groupSearchAccess.value.trim().toLowerCase()
	if (!q) return []
	const selected = new Set(accessForbiddenGroups.value)
	return allSystemGroups.value.filter(
		(g) => !selected.has(g.id) && (g.name.toLowerCase().includes(q) || g.id.toLowerCase().includes(q)),
	)
})

function addAccessUser(user: UserOption) {
	if (!accessForbiddenUsers.value.includes(user.uid)) {
		accessForbiddenUsers.value.push(user.uid)
		accessForbiddenUsersDetails.value.push(user)
	}
	userSearchAccess.value = ''
	usersFoundAccess.value = []
}

function removeAccessUser(uid: string) {
	accessForbiddenUsers.value = accessForbiddenUsers.value.filter((id) => id !== uid)
	accessForbiddenUsersDetails.value = accessForbiddenUsersDetails.value.filter((u) => u.uid !== uid)
}

function addAccessGroup(group: GroupOption) {
	if (!accessForbiddenGroups.value.includes(group.id)) {
		accessForbiddenGroups.value.push(group.id)
		accessForbiddenGroupsDetails.value.push(group)
	}
	groupSearchAccess.value = ''
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
	padding: 16px 0;
	display: flex;
	flex-direction: column;
	gap: 24px;
	max-width: 900px;
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
	gap: 16px;
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
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.search-picker-input {
	position: relative;
}

.search-dropdown-list {
	max-height: 180px;
	overflow-y: auto;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	display: flex;
	flex-direction: column;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.dropdown-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	cursor: pointer;
	transition: background-color 0.15s ease;
	border-bottom: 1px solid var(--color-border);
}

.dropdown-item:last-child {
	border-bottom: none;
}

.dropdown-item:hover {
	background-color: var(--color-background-hover);
}

.user-display,
.group-display {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.user-sub {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: 6px;
	margin-right: auto;
}

.cug-badge {
	font-size: 10px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	padding: 2px 6px;
	border-radius: 8px;
	margin-left: 8px;
	margin-right: auto;
}

.cug-badge-small {
	font-size: 9px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	padding: 1px 4px;
	border-radius: 6px;
}

.selected-chips-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 4px;
}

.chip {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border-radius: 16px;
	font-size: 12px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
}

.chip-forbidden {
	border-color: var(--color-error);
	color: var(--color-error);
}

.chip-remove {
	background: none;
	border: none;
	cursor: pointer;
	padding: 0 2px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	line-height: 1;
}

.chip-remove:hover {
	color: var(--color-error);
}

.empty-hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.actions-bar {
	margin-top: 8px;
	display: flex;
	align-items: center;
	gap: 12px;
}
</style>
