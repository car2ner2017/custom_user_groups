<template>
	<NcModal
		v-if="show"
		:name="''"
		size="large"
		@close="$emit('close')">
		<div class="shares-modal-content">
			<h2 class="form-title">
				{{ t('Shared resources of group "{group}"', { group: group.name }) }}
			</h2>

			<div class="filters-bar">
				<div class="type-tabs">
					<button
						type="button"
						class="tab-button"
						:class="{ active: typeFilter === 'all' }"
						@click="typeFilter = 'all'">
						{{ t('All ({count})', { count: shares.length }) }}
					</button>
					<button
						type="button"
						class="tab-button"
						:class="{ active: typeFilter === 'folder' }"
						@click="typeFilter = 'folder'">
						{{ t('Folders ({count})', { count: countFolders }) }}
					</button>
					<button
						type="button"
						class="tab-button"
						:class="{ active: typeFilter === 'file' }"
						@click="typeFilter = 'file'">
						{{ t('Files ({count})', { count: countFiles }) }}
					</button>
				</div>

				<div class="search-input-wrapper">
					<NcTextField
						v-model="searchFilter"
						:placeholder="t('Search by name, path, or owner...')"
						size="small" />
				</div>
			</div>

			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="24" /> {{ t('Loading group shared resources...') }}
			</div>
			<div v-else-if="shares.length === 0" class="empty-state">
				{{ t('No shared resources have been provided for this group yet.') }}
			</div>
			<div v-else-if="filteredShares.length === 0" class="empty-state">
				{{ t('No resources found matching search criteria.') }}
			</div>
			<div v-else class="shares-list">
				<div
					v-for="share in filteredShares"
					:key="share.id"
					class="share-card"
					:class="'type-' + share.item_type">
					<div class="card-header">
						<div class="resource-title-wrapper">
							<span class="resource-name" :title="share.name">{{ share.name }}</span>
							<span class="type-tag" :class="'tag-' + share.item_type">
								{{ share.item_type === 'folder' ? t('Folder') : t('File') }}
							</span>
						</div>

						<NcButton
							type="error"
							size="small"
							@click="askRevokeShare(share)">
							{{ t('Revoke access') }}
						</NcButton>
					</div>

					<div v-if="share.path" class="resource-path" :title="share.path">
						{{ t('Path: {path}', { path: share.path }) }}
					</div>

					<div class="card-details">
						<div class="detail-row">
							<span class="detail-label">{{ t('Initiator:') }}</span>
							<span class="detail-value font-semibold">
								{{ share.initiator_displayName || share.owner_displayName }}
								<span class="detail-sub">({{ share.initiator_email || share.owner_email || ('@' + (share.uid_initiator || share.uid_owner)) }})</span>
							</span>
						</div>

						<div
							v-if="share.uid_owner && share.uid_initiator && share.uid_owner !== share.uid_initiator"
							class="detail-row">
							<span class="detail-label">{{ t('File owner:') }}</span>
							<span class="detail-value">
								{{ share.owner_displayName }}
								<span class="detail-sub">({{ share.owner_email || ('@' + share.uid_owner) }})</span>
							</span>
						</div>

						<div class="detail-row">
							<span class="detail-label">{{ t('Shared on:') }}</span>
							<span class="detail-value">
								{{ formatDate(share.created_at) }}
							</span>
						</div>
					</div>
				</div>
			</div>

			<div class="modal-actions">
				<NcButton
					type="secondary"
					@click="$emit('close')">
					{{ t('Close') }}
				</NcButton>
			</div>

			<!-- Confirmation Dialog for Revoking Share -->
			<ConfirmModal
				:show="showConfirmModal"
				:title="t('Revoke shared access')"
				:message="confirmMessage"
				:confirm-text="t('Revoke access')"
				:loading="revoking"
				@close="showConfirmModal = false"
				@confirm="confirmRevokeShare" />
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
import ConfirmModal from './ConfirmModal.vue'
import type { CustomGroup, GroupShare } from '../types'
import { t } from '../utils/l10n'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'unshared'): void
}>()

const shares = ref<GroupShare[]>([])
const loading = ref(false)
const typeFilter = ref<'all' | 'folder' | 'file'>('all')
const searchFilter = ref('')

const shareToRevoke = ref<GroupShare | null>(null)
const showConfirmModal = ref(false)
const revoking = ref(false)

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen && props.group) {
			typeFilter.value = 'all'
			searchFilter.value = ''
			fetchShares()
		}
	},
	{ immediate: true },
)

async function fetchShares() {
	loading.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/shares`)
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.shares)) {
			shares.value = response.data.shares
		}
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || t('Failed to load shared resources')
		showError(msg)
	} finally {
		loading.value = false
	}
}

const countFolders = computed(() => {
	return shares.value.filter((s) => s.item_type === 'folder').length
})

const countFiles = computed(() => {
	return shares.value.filter((s) => s.item_type === 'file').length
})

const filteredShares = computed(() => {
	let list = shares.value
	if (typeFilter.value === 'folder') {
		list = list.filter((s) => s.item_type === 'folder')
	} else if (typeFilter.value === 'file') {
		list = list.filter((s) => s.item_type === 'file')
	}

	const query = searchFilter.value.trim().toLowerCase()
	if (query) {
		list = list.filter((s) => {
			const nameMatch = s.name.toLowerCase().includes(query)
			const pathMatch = s.path ? s.path.toLowerCase().includes(query) : false
			const ownerMatch = (s.owner_displayName && s.owner_displayName.toLowerCase().includes(query))
				|| (s.owner_email && s.owner_email.toLowerCase().includes(query))
				|| (s.uid_owner && s.uid_owner.toLowerCase().includes(query))
			const initiatorMatch = (s.initiator_displayName && s.initiator_displayName.toLowerCase().includes(query))
				|| (s.initiator_email && s.initiator_email.toLowerCase().includes(query))
				|| (s.uid_initiator && s.uid_initiator.toLowerCase().includes(query))
			return nameMatch || pathMatch || ownerMatch || initiatorMatch
		})
	}
	return list
})

const confirmMessage = computed(() => {
	if (!shareToRevoke.value) return ''
	const itemType = shareToRevoke.value.item_type === 'folder' ? t('folder') : t('file')
	return t('Are you sure you want to revoke access of group "{group}" to {type} "{name}"? Group members will lose access to this resource.', {
		group: props.group.name,
		type: itemType,
		name: shareToRevoke.value.name,
	})
})

function askRevokeShare(share: GroupShare) {
	shareToRevoke.value = share
	showConfirmModal.value = true
}

async function confirmRevokeShare() {
	if (!shareToRevoke.value) return
	revoking.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/shares/${shareToRevoke.value.id}`)
		await axios.delete(url)
		showSuccess(t('Shared resource access revoked'))
		shares.value = shares.value.filter((s) => s.id !== shareToRevoke.value?.id)
		showConfirmModal.value = false
		shareToRevoke.value = null
		emit('unshared')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || t('Error revoking access')
		showError(msg)
	} finally {
		revoking.value = false
	}
}

function formatDate(dateStr?: string | null | number): string {
	if (!dateStr) return ''
	try {
		const d = typeof dateStr === 'number' ? new Date(dateStr * 1000) : new Date(dateStr)
		return d.toLocaleString(undefined, {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
		})
	} catch {
		return String(dateStr)
	}
}
</script>

<style scoped>
.shares-modal-content {
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

.filters-bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.type-tabs {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.tab-button {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	padding: 6px 12px;
	font-size: 13px;
	color: var(--color-main-text);
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.tab-button:hover {
	background-color: var(--color-border);
}

.tab-button.active {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 600;
}

.search-input-wrapper {
	flex: 1;
	max-width: 320px;
}

.loading-state,
.empty-state {
	padding: 24px;
	text-align: center;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.shares-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
	max-height: 440px;
	overflow-y: auto;
	padding-right: 4px;
}

.share-card {
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.share-card.type-folder {
	border-left: 4px solid var(--color-primary-element);
}

.share-card.type-file {
	border-left: 4px solid var(--color-text-maxcontrast);
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
}

.resource-title-wrapper {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1;
	overflow: hidden;
}

.resource-name {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.type-tag {
	padding: 2px 7px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	flex-shrink: 0;
}

.tag-folder {
	background-color: var(--color-primary-element-light);
	color: inherit;
}

.tag-file {
	background-color: var(--color-background-hover);
	color: inherit;
}

.resource-path {
	font-size: 12px;
	font-family: monospace;
	word-break: break-all;
	padding: 3px 6px;
	border-radius: var(--border-radius-element);
}

.card-details {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 12px;
	padding-top: 4px;
}

.detail-row {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
}

.detail-label {
	color: var(--color-text-maxcontrast);
}

.detail-value {
	color: var(--color-main-text);
}

.font-semibold {
	font-weight: 600;
}

.detail-sub {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
