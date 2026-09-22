<template>
	<NcModal
		v-if="show"
		:name="''"
		size="large"
		@close="$emit('close')">
		<div class="activity-modal-content">
			<h2 class="form-title">
				{{ t('Activity log for group "{name}"', { name: group.name }) }}
			</h2>

			<div class="filters-bar">
				<div class="category-tabs">
					<button
						type="button"
						class="tab-button"
						:class="{ active: categoryFilter === 'all' }"
						@click="categoryFilter = 'all'">
						{{ t('All') }} ({{ activities.length }})
					</button>
					<button
						type="button"
						class="tab-button"
						:class="{ active: categoryFilter === 'members' }"
						@click="categoryFilter = 'members'">
						{{ t('Members') }} ({{ countMembers }})
					</button>
					<button
						type="button"
						class="tab-button"
						:class="{ active: categoryFilter === 'delegations' }"
						@click="categoryFilter = 'delegations'">
						{{ t('Delegation') }} ({{ countDelegations }})
					</button>
					<button
						type="button"
						class="tab-button"
						:class="{ active: categoryFilter === 'settings' }"
						@click="categoryFilter = 'settings'">
						{{ t('Settings') }} ({{ countSettings }})
					</button>
				</div>

				<div class="search-input-wrapper">
					<NcTextField
						v-model="searchFilter"
						:placeholder="t('Search by action, member or initiator…')"
						size="small" />
				</div>
			</div>

			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="24" /> {{ t('Loading activity log…') }}
			</div>
			<div v-else-if="activities.length === 0" class="empty-state">
				{{ t('No activities recorded for this group yet.') }}
			</div>
			<div v-else-if="filteredActivities.length === 0" class="empty-state">
				{{ t('No activities found matching the filter criteria.') }}
			</div>
			<div v-else class="activities-list">
				<div
					v-for="act in filteredActivities"
					:key="act.id"
					class="activity-card"
					:class="'type-' + act.action_type">
					<div class="card-header">
						<div class="action-type-tag" :class="'tag-' + act.action_type">
							{{ getActionLabel(act.action_type) }}
						</div>
						<span class="card-date">{{ formatDate(act.created_at) }}</span>
					</div>

					<div class="card-description">
						{{ act.description }}
					</div>

					<div class="card-details">
						<div class="detail-row">
							<span class="detail-label">{{ t('Initiator:') }}</span>
							<span class="detail-value font-semibold">
								{{ act.actor_displayName }}
								<span class="detail-sub">({{ act.actor_email || ('@' + act.actor_id) }})</span>
							</span>
						</div>

						<div v-if="act.target_displayName || act.target_id" class="detail-row">
							<span class="detail-label">{{ t('User:') }}</span>
							<span class="detail-value">
								{{ act.target_displayName || act.target_id }}
								<span v-if="act.target_email || act.target_id" class="detail-sub">
									({{ act.target_email || ('@' + act.target_id) }})
								</span>
							</span>
						</div>

						<!-- Specific details for name change -->
						<div v-if="act.action_type === 'name_change' && act.details?.old_name && act.details?.new_name" class="detail-row">
							<span class="detail-label">{{ t('Change:') }}</span>
							<span class="detail-value">
								«{{ act.details.old_name }}» - «{{ act.details.new_name }}»
							</span>
						</div>

						<!-- Specific details for delegation -->
						<div v-if="act.details?.level" class="detail-row">
							<span class="detail-label">{{ t('Permission level:') }}</span>
							<span class="detail-value font-semibold">
								{{ act.details.level === 'manage' ? t('Manage') : t('Moderate') }}
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
import { t } from '../utils/l10n'
import type { CustomGroup, GroupActivity } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

defineEmits<{
	(e: 'close'): void
}>()

const activities = ref<GroupActivity[]>([])
const loading = ref(false)
const categoryFilter = ref<'all' | 'members' | 'delegations' | 'settings'>('all')
const searchFilter = ref('')

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen && props.group) {
			categoryFilter.value = 'all'
			searchFilter.value = ''
			fetchActivities()
		}
	},
	{ immediate: true },
)

async function fetchActivities() {
	loading.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/activities`)
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.activities)) {
			activities.value = response.data.activities
		}
	} catch (err) {
		console.error('Failed to load group activities:', err)
	} finally {
		loading.value = false
	}
}

const countMembers = computed(() => {
	return activities.value.filter((a) => a.action_type === 'member_add' || a.action_type === 'member_remove').length
})

const countDelegations = computed(() => {
	return activities.value.filter((a) => a.action_type === 'delegation_assign' || a.action_type === 'delegation_revoke').length
})

const countSettings = computed(() => {
	return activities.value.filter((a) => a.action_type === 'name_change' || a.action_type === 'owner_transfer' || a.action_type === 'share_unshare').length
})

const filteredActivities = computed(() => {
	let list = activities.value
	if (categoryFilter.value === 'members') {
		list = list.filter((a) => a.action_type === 'member_add' || a.action_type === 'member_remove')
	} else if (categoryFilter.value === 'delegations') {
		list = list.filter((a) => a.action_type === 'delegation_assign' || a.action_type === 'delegation_revoke')
	} else if (categoryFilter.value === 'settings') {
		list = list.filter((a) => a.action_type === 'name_change' || a.action_type === 'owner_transfer' || a.action_type === 'share_unshare')
	}

	const query = searchFilter.value.trim().toLowerCase()
	if (query) {
		list = list.filter((a) => {
			const descMatch = a.description.toLowerCase().includes(query)
			const actorMatch = a.actor_displayName.toLowerCase().includes(query) || a.actor_id.toLowerCase().includes(query)
			const targetMatch = (a.target_displayName && a.target_displayName.toLowerCase().includes(query))
				|| (a.target_id && a.target_id.toLowerCase().includes(query))
			return descMatch || actorMatch || targetMatch
		})
	}
	return list
})

function getActionLabel(type: string): string {
	switch (type) {
	case 'member_add':
		return t('Member added')
	case 'member_remove':
		return t('Member removed')
	case 'delegation_assign':
		return t('Permission granted')
	case 'delegation_revoke':
		return t('Permission revoked')
	case 'name_change':
		return t('Group renamed')
	case 'owner_transfer':
		return t('Ownership transferred')
	case 'share_unshare':
		return t('Access revoked')
	default:
		return t('Action')
	}
}

function formatDate(dateStr?: string | null): string {
	if (!dateStr) return ''
	try {
		const d = new Date(dateStr)
		return d.toLocaleString(undefined, {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
		})
	} catch {
		return dateStr
	}
}
</script>

<style scoped>
.activity-modal-content {
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

.category-tabs {
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

.activities-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
	max-height: 440px;
	overflow-y: auto;
	padding-right: 4px;
}

.activity-card {
	--color-success: #008C0B;
	--color-error: #DA0000;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.activity-card.type-member_add {
	border-left: 4px solid var(--color-border-success, var(--color-success));
}

.activity-card.type-member_remove {
	border-left: 4px solid var(--color-border-error, var(--color-error));
}

.activity-card.type-delegation_assign,
.activity-card.type-delegation_revoke {
	border-left: 4px solid var(--color-warning-element, #e29300);
}

.activity-card.type-name_change,
.activity-card.type-owner_transfer {
	border-left: 4px solid var(--color-primary-element);
}

.activity-card.type-share_unshare {
	border-left: 4px solid var(--color-border-error, var(--color-error));
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.action-type-tag {
	padding: 3px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	color: inherit;
}

.tag-member_add {
	background-color: rgba(70, 186, 97, 0.15);
}

.tag-member_remove {
	background-color: rgba(224, 76, 56, 0.15);
}

.tag-delegation_assign,
.tag-delegation_revoke {
	background-color: var(--color-warning-element-light, #fff2d6);
}

.tag-name_change,
.tag-owner_transfer {
	background-color: var(--color-primary-element-light);
}

.tag-share_unshare {
	background-color: rgba(224, 76, 56, 0.15);
}

.card-date {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

.card-description {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
	line-height: 1.4;
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
