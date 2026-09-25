<template>
	<div class="admin-settings-container">
		<!-- Section 1: Group Creation Restriction -->
		<NcSettingsSection
			:name="t('HZS User Groups settings')"
			:description="t('Configure who is allowed to create new custom user groups in the system.')">
			<div class="settings-content">
				<NcCheckboxRadioSwitch
					v-model="createRestrictionEnabled"
					type="switch">
					{{ t('Restrict group creation (allow only selected users and groups)') }}
				</NcCheckboxRadioSwitch>

				<div v-if="createRestrictionEnabled" class="restriction-details">
					<div class="info-callout">
						<strong>{{ t('Note:') }}</strong> {{ t('Nextcloud administrators are always allowed to create custom groups regardless of restrictions.') }}
					</div>

					<!-- Allowed Users Selection -->
					<div class="picker-group">
						<label class="picker-label">{{ t('Allowed users') }} ({{ createAllowedUsers.length }})</label>

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
									- {{ t('Remove') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ t('No users added yet') }}
						</div>

						<!-- Search users for creation -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="userSearchCreate"
								:placeholder="t('Search Nextcloud users to add…')"
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
									+ {{ t('Add') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ userSearchCreate.trim() ? t('No users found') : t('All available users have been added') }}
						</div>
					</div>

					<!-- Allowed Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">{{ t('Allowed groups') }} ({{ createAllowedGroups.length }})</label>

						<!-- Selected groups list -->
						<div v-if="createAllowedGroupsDetails.length > 0" class="selected-items-list">
							<div
								v-for="grp in createAllowedGroupsDetails"
								:key="grp.id"
								class="selected-item">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">{{ t('Custom group') }}</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeCreateGroup(grp.id)">
									- {{ t('Remove') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ t('No groups added yet') }}
						</div>

						<!-- Search groups for creation -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="groupSearchCreate"
								:placeholder="t('Search Nextcloud groups to add…')"
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
									<span v-if="grp.is_cug" class="cug-badge">{{ t('Custom') }}</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addCreateGroup(grp)">
									+ {{ t('Add') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ groupSearchCreate.trim() ? t('No groups found') : t('All available groups have been added') }}
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>

		<!-- Section 2: App Access Restriction -->
		<NcSettingsSection
			:name="t('App access restriction')"
			:description="t('Configure access rules for users and groups to the HZS User Groups web interface.')">
			<div class="settings-content">
				<NcCheckboxRadioSwitch
					v-model="accessRestrictionEnabled"
					type="switch">
					{{ t('Restrict app access') }}
				</NcCheckboxRadioSwitch>

				<div v-if="accessRestrictionEnabled" class="restriction-details">
					<div class="info-callout">
						<strong>{{ t('Note:') }}</strong> {{ t('Access rules are evaluated in order: if a user is explicitly allowed directly or via an allowed group, they are granted access (even if in a forbidden group). Administrators always have access.') }}
					</div>

					<!-- Allowed Users Selection -->
					<div class="picker-group">
						<label class="picker-label">{{ t('Allowed users') }} ({{ accessAllowedUsers.length }})</label>

						<!-- Selected allowed users list -->
						<div v-if="accessAllowedUsersDetails.length > 0" class="selected-items-list">
							<div
								v-for="user in accessAllowedUsersDetails"
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
									@click.stop="removeAccessAllowedUser(user.uid)">
									- {{ t('Remove') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ t('No users added yet') }}
						</div>

						<!-- Search users for access allow -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="userSearchAccessAllowed"
								:placeholder="t('Search users to allow access…')"
								:disabled="saving" />
						</div>

						<!-- Available users to allow -->
						<div v-if="filteredAvailableAccessAllowedUsers.length > 0" class="available-items-list">
							<div
								v-for="user in filteredAvailableAccessAllowedUsers"
								:key="user.uid"
								class="available-item"
								@click="addAccessAllowedUser(user)">
								<div class="item-info">
									<span class="item-primary">{{ user.displayName }}</span>
									<span class="item-secondary">{{ user.email || ('@' + user.uid) }}</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addAccessAllowedUser(user)">
									+ {{ t('Allow') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ userSearchAccessAllowed.trim() ? t('No users found') : t('All available users have been added') }}
						</div>
					</div>

					<!-- Allowed Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">{{ t('Allowed groups') }} ({{ accessAllowedGroups.length }})</label>

						<!-- Selected allowed groups list -->
						<div v-if="accessAllowedGroupsDetails.length > 0" class="selected-items-list">
							<div
								v-for="grp in accessAllowedGroupsDetails"
								:key="grp.id"
								class="selected-item">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">{{ t('Custom group') }}</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeAccessAllowedGroup(grp.id)">
									- {{ t('Remove') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ t('No groups added yet') }}
						</div>

						<!-- Search groups for access allow -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="groupSearchAccessAllowed"
								:placeholder="t('Search groups to allow access…')"
								:disabled="saving" />
						</div>

						<!-- Available groups to allow -->
						<div v-if="filteredAvailableAccessAllowedGroups.length > 0" class="available-items-list">
							<div
								v-for="grp in filteredAvailableAccessAllowedGroups"
								:key="grp.id"
								class="available-item"
								@click="addAccessAllowedGroup(grp)">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">{{ t('Custom') }}</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addAccessAllowedGroup(grp)">
									+ {{ t('Allow') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ groupSearchAccessAllowed.trim() ? t('No groups found') : t('All available groups have been added') }}
						</div>
					</div>

					<!-- Forbidden Users Selection -->
					<div class="picker-group">
						<label class="picker-label">{{ t('Forbidden users') }} ({{ accessForbiddenUsers.length }})</label>

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
									@click.stop="removeAccessForbiddenUser(user.uid)">
									- {{ t('Remove') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ t('No users added yet') }}
						</div>

						<!-- Search users for access restriction -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="userSearchAccessForbidden"
								:placeholder="t('Search users to forbid access…')"
								:disabled="saving" />
						</div>

						<!-- Available users to forbid -->
						<div v-if="filteredAvailableAccessForbiddenUsers.length > 0" class="available-items-list">
							<div
								v-for="user in filteredAvailableAccessForbiddenUsers"
								:key="user.uid"
								class="available-item"
								@click="addAccessForbiddenUser(user)">
								<div class="item-info">
									<span class="item-primary">{{ user.displayName }}</span>
									<span class="item-secondary">{{ user.email || ('@' + user.uid) }}</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addAccessForbiddenUser(user)">
									+ {{ t('Forbid') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ userSearchAccessForbidden.trim() ? t('No users found') : t('All available users have been added') }}
						</div>
					</div>

					<!-- Forbidden Groups Selection -->
					<div class="picker-group">
						<label class="picker-label">{{ t('Forbidden groups') }} ({{ accessForbiddenGroups.length }})</label>

						<!-- Selected forbidden groups list -->
						<div v-if="accessForbiddenGroupsDetails.length > 0" class="selected-items-list">
							<div
								v-for="grp in accessForbiddenGroupsDetails"
								:key="grp.id"
								class="selected-item item-forbidden">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">{{ t('Custom group') }}</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="error"
									size="small"
									:disabled="saving"
									@click.stop="removeAccessForbiddenGroup(grp.id)">
									- {{ t('Remove') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ t('No groups added yet') }}
						</div>

						<!-- Search groups for access restriction -->
						<div class="search-input-wrapper">
							<NcTextField
								v-model="groupSearchAccessForbidden"
								:placeholder="t('Search groups to forbid access…')"
								:disabled="saving" />
						</div>

						<!-- Available groups to forbid -->
						<div v-if="filteredAvailableAccessForbiddenGroups.length > 0" class="available-items-list">
							<div
								v-for="grp in filteredAvailableAccessForbiddenGroups"
								:key="grp.id"
								class="available-item"
								@click="addAccessForbiddenGroup(grp)">
								<div class="item-info">
									<span class="item-primary">{{ grp.name }}</span>
									<span v-if="grp.is_cug" class="cug-badge">{{ t('Custom') }}</span>
									<span v-else class="item-secondary">({{ grp.id }})</span>
								</div>
								<NcButton
									type="tertiary"
									size="small"
									:disabled="saving"
									@click.stop="addAccessForbiddenGroup(grp)">
									+ {{ t('Forbid') }}
								</NcButton>
							</div>
						</div>
						<div v-else class="empty-hint">
							{{ groupSearchAccessForbidden.trim() ? t('No groups found') : t('All available groups have been added') }}
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
				{{ saving ? t('Saving settings…') : t('Save settings') }}
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
import { t } from '../utils/l10n'
import type { AdminSettingsData, AdminSettingsResponse, GroupOption, UserOption } from '../types'

const defaultSettings: AdminSettingsData = {
	create_restriction_enabled: false,
	create_allowed_users: [],
	create_allowed_groups: [],
	access_restriction_enabled: false,
	access_allowed_users: [],
	access_allowed_groups: [],
	access_forbidden_users: [],
	access_forbidden_groups: [],
}

const initialRaw = loadState<AdminSettingsResponse | AdminSettingsData>('user_groups_hzs', 'customUserGroupsSettings', defaultSettings)
const initialData: AdminSettingsData = (initialRaw && 'settings' in initialRaw) ? initialRaw.settings : (initialRaw as AdminSettingsData || defaultSettings)
const initialDetails = (initialRaw && 'settings' in initialRaw) ? initialRaw : null

const createRestrictionEnabled = ref(initialData.create_restriction_enabled ?? false)
const createAllowedUsers = ref<string[]>(initialData.create_allowed_users || [])
const createAllowedGroups = ref<string[]>(initialData.create_allowed_groups || [])
const createAllowedUsersDetails = ref<UserOption[]>(initialDetails?.create_allowed_users_details || [])
const createAllowedGroupsDetails = ref<GroupOption[]>(initialDetails?.create_allowed_groups_details || [])

const accessRestrictionEnabled = ref(initialData.access_restriction_enabled ?? false)
const accessAllowedUsers = ref<string[]>(initialData.access_allowed_users || [])
const accessAllowedGroups = ref<string[]>(initialData.access_allowed_groups || [])
const accessAllowedUsersDetails = ref<UserOption[]>(initialRaw?.access_allowed_users_details || [])
const accessAllowedGroupsDetails = ref<GroupOption[]>(initialRaw?.access_allowed_groups_details || [])

const accessForbiddenUsers = ref<string[]>(initialData.access_forbidden_users || [])
const accessForbiddenGroups = ref<string[]>(initialData.access_forbidden_groups || [])
const accessForbiddenUsersDetails = ref<UserOption[]>(initialRaw?.access_forbidden_users_details || [])
const accessForbiddenGroupsDetails = ref<GroupOption[]>(initialRaw?.access_forbidden_groups_details || [])

const allSystemUsers = ref<UserOption[]>([])
const allSystemGroups = ref<GroupOption[]>([])
const saving = ref(false)

// Search fields
const userSearchCreate = ref('')
const groupSearchCreate = ref('')
const userSearchAccessAllowed = ref('')
const groupSearchAccessAllowed = ref('')
const userSearchAccessForbidden = ref('')
const groupSearchAccessForbidden = ref('')

// Pre-merge initial details
if (createAllowedUsersDetails.value.length > 0) mergeUsers(createAllowedUsersDetails.value)
if (accessAllowedUsersDetails.value.length > 0) mergeUsers(accessAllowedUsersDetails.value)
if (accessForbiddenUsersDetails.value.length > 0) mergeUsers(accessForbiddenUsersDetails.value)
if (createAllowedGroupsDetails.value.length > 0) mergeGroups(createAllowedGroupsDetails.value)
if (accessAllowedGroupsDetails.value.length > 0) mergeGroups(accessAllowedGroupsDetails.value)
if (accessForbiddenGroupsDetails.value.length > 0) mergeGroups(accessForbiddenGroupsDetails.value)

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

function ensureUserDetails(uids: string[], details: UserOption[]): UserOption[] {
	const detailMap = new Map((details || []).map((d) => [d.uid, d]))
	const systemMap = new Map(allSystemUsers.value.map((u) => [u.uid, u]))
	return uids.map((uid) => detailMap.get(uid) || systemMap.get(uid) || { uid, displayName: uid })
}

function ensureGroupDetails(gids: string[], details: GroupOption[]): GroupOption[] {
	const detailMap = new Map((details || []).map((d) => [d.id, d]))
	const systemMap = new Map(allSystemGroups.value.map((g) => [g.id, g]))
	return gids.map((gid) => detailMap.get(gid) || systemMap.get(gid) || { id: gid, name: gid, is_cug: gid.startsWith('cug_') })
}

function applySettingsResponse(data: AdminSettingsResponse) {
	if (!data || !data.settings) return
	const s = data.settings
	createRestrictionEnabled.value = s.create_restriction_enabled ?? false
	createAllowedUsers.value = s.create_allowed_users || []
	createAllowedGroups.value = s.create_allowed_groups || []
	createAllowedUsersDetails.value = ensureUserDetails(createAllowedUsers.value, data.create_allowed_users_details || [])
	createAllowedGroupsDetails.value = ensureGroupDetails(createAllowedGroups.value, data.create_allowed_groups_details || [])

	accessRestrictionEnabled.value = s.access_restriction_enabled ?? false
	accessAllowedUsers.value = s.access_allowed_users || []
	accessAllowedGroups.value = s.access_allowed_groups || []
	accessAllowedUsersDetails.value = ensureUserDetails(accessAllowedUsers.value, data.access_allowed_users_details || [])
	accessAllowedGroupsDetails.value = ensureGroupDetails(accessAllowedGroups.value, data.access_allowed_groups_details || [])

	accessForbiddenUsers.value = s.access_forbidden_users || []
	accessForbiddenGroups.value = s.access_forbidden_groups || []
	accessForbiddenUsersDetails.value = ensureUserDetails(accessForbiddenUsers.value, data.access_forbidden_users_details || [])
	accessForbiddenGroupsDetails.value = ensureGroupDetails(accessForbiddenGroups.value, data.access_forbidden_groups_details || [])

	mergeUsers(createAllowedUsersDetails.value)
	mergeUsers(accessAllowedUsersDetails.value)
	mergeUsers(accessForbiddenUsersDetails.value)
	mergeGroups(createAllowedGroupsDetails.value)
	mergeGroups(accessAllowedGroupsDetails.value)
	mergeGroups(accessForbiddenGroupsDetails.value)
}

async function fetchSettings() {
	try {
		const url = generateUrl('/apps/user_groups_hzs/api/v1/admin/settings')
		const res = await axios.get<AdminSettingsResponse>(url, {
			params: { _nocache: Date.now() },
			headers: { 'Cache-Control': 'no-cache', Pragma: 'no-cache' },
		})
		if (res.data) {
			applySettingsResponse(res.data)
		}
	} catch (err) {
		console.error('Failed to load settings:', err)
	}
}

async function fetchAllUsers() {
	try {
		const url = generateUrl('/apps/user_groups_hzs/api/v1/admin/users-search')
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
		const url = generateUrl('/apps/user_groups_hzs/api/v1/admin/groups-list')
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

// Filtered lists for App Access restriction - Allowed
const filteredAvailableAccessAllowedUsers = computed(() => {
	const selected = new Set(accessAllowedUsers.value)
	const unselected = allSystemUsers.value.filter((u) => !selected.has(u.uid))
	const query = userSearchAccessAllowed.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(u) =>
			u.displayName.toLowerCase().includes(query)
			|| u.uid.toLowerCase().includes(query)
			|| (u.email && u.email.toLowerCase().includes(query)),
	)
})

const filteredAvailableAccessAllowedGroups = computed(() => {
	const selected = new Set(accessAllowedGroups.value)
	const unselected = allSystemGroups.value.filter((g) => !selected.has(g.id))
	const query = groupSearchAccessAllowed.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(g) =>
			g.name.toLowerCase().includes(query)
			|| g.id.toLowerCase().includes(query),
	)
})

// Filtered lists for App Access restriction - Forbidden
const filteredAvailableAccessForbiddenUsers = computed(() => {
	const selected = new Set(accessForbiddenUsers.value)
	const unselected = allSystemUsers.value.filter((u) => !selected.has(u.uid))
	const query = userSearchAccessForbidden.value.trim().toLowerCase()
	if (!query) return unselected.slice(0, 50)
	return unselected.filter(
		(u) =>
			u.displayName.toLowerCase().includes(query)
			|| u.uid.toLowerCase().includes(query)
			|| (u.email && u.email.toLowerCase().includes(query)),
	)
})

const filteredAvailableAccessForbiddenGroups = computed(() => {
	const selected = new Set(accessForbiddenGroups.value)
	const unselected = allSystemGroups.value.filter((g) => !selected.has(g.id))
	const query = groupSearchAccessForbidden.value.trim().toLowerCase()
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

function addAccessAllowedUser(user: UserOption) {
	if (!accessAllowedUsers.value.includes(user.uid)) {
		accessAllowedUsers.value.push(user.uid)
		accessAllowedUsersDetails.value.push({ ...user })
	}
}

function removeAccessAllowedUser(uid: string) {
	accessAllowedUsers.value = accessAllowedUsers.value.filter((id) => id !== uid)
	accessAllowedUsersDetails.value = accessAllowedUsersDetails.value.filter((u) => u.uid !== uid)
}

function addAccessAllowedGroup(group: GroupOption) {
	if (!accessAllowedGroups.value.includes(group.id)) {
		accessAllowedGroups.value.push(group.id)
		accessAllowedGroupsDetails.value.push({ ...group })
	}
}

function removeAccessAllowedGroup(gid: string) {
	accessAllowedGroups.value = accessAllowedGroups.value.filter((id) => id !== gid)
	accessAllowedGroupsDetails.value = accessAllowedGroupsDetails.value.filter((g) => g.id !== gid)
}

function addAccessForbiddenUser(user: UserOption) {
	if (!accessForbiddenUsers.value.includes(user.uid)) {
		accessForbiddenUsers.value.push(user.uid)
		accessForbiddenUsersDetails.value.push({ ...user })
	}
}

function removeAccessForbiddenUser(uid: string) {
	accessForbiddenUsers.value = accessForbiddenUsers.value.filter((id) => id !== uid)
	accessForbiddenUsersDetails.value = accessForbiddenUsersDetails.value.filter((u) => u.uid !== uid)
}

function addAccessForbiddenGroup(group: GroupOption) {
	if (!accessForbiddenGroups.value.includes(group.id)) {
		accessForbiddenGroups.value.push(group.id)
		accessForbiddenGroupsDetails.value.push({ ...group })
	}
}

function removeAccessForbiddenGroup(gid: string) {
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
			access_allowed_users: accessAllowedUsers.value,
			access_allowed_groups: accessAllowedGroups.value,
			access_forbidden_users: accessForbiddenUsers.value,
			access_forbidden_groups: accessForbiddenGroups.value,
		}
		const url = generateUrl('/apps/user_groups_hzs/api/v1/admin/settings')
		const res = await axios.post<AdminSettingsResponse>(url, payload)
		showSuccess(t('Settings saved successfully'))
		if (res.data && res.data.settings) {
			applySettingsResponse(res.data)
		} else {
			await fetchSettings()
		}
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || t('Error saving settings')
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
	font-size: 14px;
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
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.item-secondary {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
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

/* Button overrides */
.button-vue.button-vue--error:active:not(:disabled),
.button-vue[data-v-47ce59a3].button-vue--error:active:not(:disabled),
.button-vue--size-small.button-vue--error:active:not(:disabled) {
	transform: none !important;
	background-color: var(--color-error) !important;
}

.button-vue.button-vue--tertiary,
.button-vue[data-v-47ce59a3].button-vue--tertiary,
.button-vue--size-small.button-vue--tertiary {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.button-vue.button-vue--tertiary:hover:not(:disabled),
.button-vue[data-v-47ce59a3].button-vue--tertiary:hover:not(:disabled),
.button-vue--size-small.button-vue--tertiary:hover:not(:disabled) {
	background-color: var(--color-primary-element-light-hover);
}
</style>
