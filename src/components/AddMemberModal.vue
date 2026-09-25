<template>
	<NcModal
		v-if="show"
		:name="''"
		size="normal"
		@close="$emit('close')">
		<form class="add-member-form" @submit.prevent="submitForm">
			<h2 class="form-title">
				{{ t('Add member') }}
			</h2>

			<p class="form-description">
				{{ t('Select users to add to group "{group}".', { group: group.name }) }}
			</p>

			<!-- Selected Users to Add Section -->
			<div class="form-group">
				<label class="form-label">
					{{ t('Members to add ({count})', { count: selectedUsers.length }) }}
				</label>

				<!-- Selected users list with scroll and max height -->
				<div v-if="selectedUsers.length > 0" class="selected-users-list">
					<div
						v-for="user in selectedUsers"
						:key="user.uid"
						class="user-item">
						<div class="user-item-info">
							<span class="user-displayname">{{ user.displayName }}</span>
							<span class="user-email-uid">{{ user.email || ('@' + user.uid) }}</span>
						</div>
						<NcButton
							type="button"
							variant="error"
							size="small"
							:disabled="saving"
							@click="removeSelected(user.uid)">
							- {{ t('Remove') }}
						</NcButton>
					</div>
				</div>
				<div v-else class="no-members-hint">
					{{ t('No members selected yet. Search and select users below.') }}
				</div>

				<!-- Search users dropdown in Nextcloud Files Sharing style -->
				<UserSearchDropdown
					input-id="add-member-search"
					:disabled="saving"
					:exclude-uids="excludedUids"
					:placeholder="t('Search Nextcloud users to add…')"
					@select="addSelected" />
			</div>

			<!-- Modal Actions -->
			<div class="modal-actions">
				<NcButton
					type="button"
					variant="secondary"
					:disabled="saving"
					@click="$emit('close')">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton
					type="submit"
					variant="primary"
					:disabled="saving || selectedUsers.length === 0">
					{{ saving ? t('Saving...') : t('Save') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import UserSearchDropdown from './UserSearchDropdown.vue'
import type { CustomGroup, UserOption } from '../types'
import { t } from '../utils/l10n'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'saved'): void
}>()

const selectedUsers = ref<UserOption[]>([])
const saving = ref(false)

const excludedUids = computed(() => {
	const currentGroupUids = props.group?.members && props.group.members.length > 0
		? props.group.members.map((m) => m.uid || m.id || '')
		: (props.group?.member_ids || [])
	const selected = selectedUsers.value.map((u) => u.uid)
	return Array.from(new Set([...currentGroupUids, ...selected]))
})

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen) {
			selectedUsers.value = []
		}
	},
	{ immediate: true },
)

function addSelected(user: UserOption) {
	if (!selectedUsers.value.some((u) => u.uid === user.uid)) {
		selectedUsers.value.push({ ...user })
	}
}

function removeSelected(uid: string) {
	selectedUsers.value = selectedUsers.value.filter((u) => u.uid !== uid)
}

async function submitForm() {
	if (selectedUsers.value.length === 0 || !props.group) return
	saving.value = true

	try {
		const existingIds = props.group.member_ids || []
		const newIds = selectedUsers.value.map((u) => u.uid)
		const allMemberIds = Array.from(new Set([...existingIds, ...newIds]))

		const url = generateUrl(`/apps/user_groups_hzs/api/v1/groups/${props.group.group_id}`)
		await axios.put(url, {
			name: props.group.name,
			memberIds: allMemberIds,
		})

		showSuccess(t('Members successfully added to group'))
		emit('saved')
		emit('close')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || t('Error adding members')
		showError(msg)
	} finally {
		saving.value = false
	}
}
</script>

<style scoped>
.add-member-form {
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

.form-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-label {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.selected-users-list {
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

.no-members-hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.user-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	cursor: pointer;
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

.user-displayname {
	font-weight: 600;
	font-size: 14px;
	color: var(--color-main-text);
}

.user-email-uid {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
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
