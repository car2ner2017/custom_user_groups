<template>
	<NcModal
		v-if="show"
		:name="''"
		size="normal"
		@close="$emit('close')">
		<div class="request-modal-content">
			<h2 class="form-title">
				{{ t('Request to add user to group') }}
			</h2>

			<p class="request-description">
				{{ t('You can suggest adding one or more Nextcloud users to group "{group}". Requests will be reviewed by the group creator and moderators.', { group: group.name }) }}
			</p>

			<!-- Selected Candidates Section -->
			<div v-if="selectedCandidates.length > 0" class="form-group">
				<label class="form-label">
					{{ t('Selected users ({count})', { count: selectedCandidates.length }) }}
				</label>
				<div class="selected-candidates-list">
					<div
						v-for="user in selectedCandidates"
						:key="user.uid"
						class="user-item">
						<div class="user-item-info">
							<span class="user-name">{{ user.displayName }}</span>
							<span class="user-email">{{ user.email || ('@' + user.uid) }}</span>
						</div>
						<NcButton
							type="error"
							size="small"
							:disabled="submitting"
							@click="removeCandidate(user.uid)">
							- {{ t('Remove') }}
						</NcButton>
					</div>
				</div>
			</div>
			<div v-else class="no-candidates-hint">
				{{ t('No candidates selected yet. Choose one or more users from the list below.') }}
			</div>

			<!-- Search filter for users -->
			<div class="form-group">
				<label class="form-label">
					{{ t('Search users') }}
				</label>
				<NcTextField
					v-model="searchQuery"
					:placeholder="t('Enter user name, email, or login...')"
					:disabled="submitting" />
			</div>

			<!-- Candidate Users List -->
			<div v-if="loadingUsers" class="loading-state">
				<NcLoadingIcon :size="20" /> {{ t('Loading users...') }}
			</div>
			<div v-else-if="filteredUsers.length > 0" class="available-users-list">
				<div
					v-for="user in filteredUsers"
					:key="user.uid"
					class="user-item"
					@click="addCandidate(user)">
					<div class="user-item-info">
						<span class="user-name">{{ user.displayName }}</span>
						<span class="user-email">{{ user.email || ('@' + user.uid) }}</span>
					</div>
					<NcButton
						type="tertiary"
						size="small"
						:disabled="submitting"
						@click.stop="addCandidate(user)">
						+ {{ t('Select') }}
					</NcButton>
				</div>
			</div>
			<div v-else class="empty-state">
				{{ searchQuery.trim() !== '' ? t('No users found for query') : t('All available users are already members of the group or selected') }}
			</div>

			<div class="modal-actions">
				<NcButton
					type="secondary"
					:disabled="submitting"
					@click="$emit('close')">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="submitting || selectedCandidates.length === 0"
					@click="submit">
					{{ submitting ? t('Sending requests...') : t('Send requests ({count})', { count: selectedCandidates.length }) }}
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
import { t } from '../utils/l10n'

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
const selectedCandidates = ref<UserOption[]>([])
const loadingUsers = ref(false)
const submitting = ref(false)

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen) {
			searchQuery.value = ''
			selectedCandidates.value = []
			fetchUsers()
		}
	},
	{ immediate: true },
)

const filteredUsers = computed(() => {
	if (!props.group) return []
	const existingUids = new Set(props.group.member_ids || [])
	const selectedUids = new Set(selectedCandidates.value.map((u) => u.uid))
	const unselected = availableUsers.value.filter((u) => !existingUids.has(u.uid) && !selectedUids.has(u.uid))

	const query = searchQuery.value.trim().toLowerCase()
	if (query === '') return unselected

	return unselected.filter((u) => {
		const nameMatch = u.displayName.toLowerCase().includes(query)
		const uidMatch = u.uid.toLowerCase().includes(query)
		const emailMatch = u.email ? u.email.toLowerCase().includes(query) : false
		return nameMatch || uidMatch || emailMatch
	})
})

async function fetchUsers() {
	loadingUsers.value = true
	try {
		const url = generateUrl('/apps/customusergroups/api/v1/users', { search: '', limit: 500 })
		const res = await axios.get(url)
		if (res.data && Array.isArray(res.data.users)) {
			availableUsers.value = res.data.users
		}
	} catch (err) {
		console.error('Failed to load users:', err)
	} finally {
		loadingUsers.value = false
	}
}

function addCandidate(user: UserOption) {
	if (!selectedCandidates.value.some((u) => u.uid === user.uid)) {
		selectedCandidates.value.push({ ...user })
	}
}

function removeCandidate(uid: string) {
	selectedCandidates.value = selectedCandidates.value.filter((u) => u.uid !== uid)
}

async function submit() {
	if (selectedCandidates.value.length === 0 || !props.group) return
	submitting.value = true

	let successCount = 0
	const errors: string[] = []

	for (const candidate of selectedCandidates.value) {
		try {
			const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/requests`)
			await axios.post(url, {
				candidateId: candidate.uid,
			})
			successCount++
		} catch (err: unknown) {
			const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
			const msg = axiosErr.response?.data?.error || axiosErr.message || t('Request error')
			errors.push(`${candidate.displayName}: ${msg}`)
		}
	}

	if (successCount > 0) {
		showSuccess(t('Requests sent successfully: {count}', { count: successCount }))
		emit('submitted')
	}

	if (errors.length > 0) {
		showError(`${t('Errors occurred while sending:')}\n${errors.join('\n')}`)
	}

	submitting.value = false

	if (successCount > 0) {
		emit('close')
	}
}
</script>

<style scoped>
.request-modal-content {
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

.selected-candidates-list {
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

.no-candidates-hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
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

.available-users-list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	max-height: 180px;
	overflow-y: auto;
}

.user-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	cursor: pointer;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
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

.user-name {
	font-size: 13px;
	font-weight: 500;
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
	padding-top: 14px;
	border-top: 1px solid var(--color-border);
}
</style>
