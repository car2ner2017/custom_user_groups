<template>
	<NcModal
		v-if="show"
		:name="''"
		size="large"
		@close="$emit('close')">
		<div class="history-modal-content">
			<h2 class="form-title">
				История запросов группы «{{ group.name }}»
			</h2>

			<div class="filters-bar">
				<div class="status-tabs">
					<button
						type="button"
						class="tab-button"
						:class="{ active: statusFilter === 'all' }"
						@click="statusFilter = 'all'">
						Все ({{ processedRequests.length }})
					</button>
					<button
						type="button"
						class="tab-button tab-approved"
						:class="{ active: statusFilter === 'approved' }"
						@click="statusFilter = 'approved'">
						Одобренные ({{ countApproved }})
					</button>
					<button
						type="button"
						class="tab-button tab-rejected"
						:class="{ active: statusFilter === 'rejected' }"
						@click="statusFilter = 'rejected'">
						Отклоненные ({{ countRejected }})
					</button>
				</div>

				<div class="search-input-wrapper">
					<NcTextField
						v-model="searchFilter"
						placeholder="Поиск по кандидату, автору или решению..."
						size="small" />
				</div>
			</div>

			<div v-if="loading" class="loading-state">
				<NcLoadingIcon :size="24" /> Загрузка истории запросов...
			</div>
			<div v-else-if="processedRequests.length === 0" class="empty-state">
				В этой группе еще не было рассмотренных запросов на добавление участников.
			</div>
			<div v-else-if="filteredRequests.length === 0" class="empty-state">
				Запросы не найдены по заданным критериям фильтрации.
			</div>
			<div v-else class="requests-history-list">
				<div
					v-for="req in filteredRequests"
					:key="req.id"
					class="history-card"
					:class="'status-' + req.status">
					<div class="card-header">
						<div class="candidate-info">
							<span class="candidate-name">{{ req.candidate_displayName }}</span>
							<span class="candidate-email">{{ req.candidate_email || ('@' + req.candidate_id) }}</span>
						</div>
						<div class="status-tag" :class="'tag-' + req.status">
							{{ req.status === 'approved' ? 'Одобрен' : 'Отклонен' }}
						</div>
					</div>

					<div class="card-details">
						<div class="detail-row">
							<span class="detail-label">Предложил:</span>
							<span class="detail-value">
								{{ req.requester_displayName }} ({{ '@' + req.requester_id }})
							</span>
							<span class="detail-date">{{ formatDate(req.created_at) }}</span>
						</div>

						<div class="detail-row decision-row">
							<span class="detail-label">
								{{ req.status === 'approved' ? 'Одобрил:' : 'Отклонил:' }}
							</span>
							<span class="detail-value font-semibold">
								{{ req.processed_by_displayName || req.processed_by || 'Администратор' }}
								<span v-if="req.processed_by_email || req.processed_by" class="detail-sub">
									({{ req.processed_by_email || ('@' + req.processed_by) }})
								</span>
							</span>
							<span class="detail-date">{{ formatDate(req.updated_at) }}</span>
						</div>
					</div>
				</div>
			</div>

			<div class="modal-actions">
				<NcButton
					type="secondary"
					@click="$emit('close')">
					Закрыть
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
import type { CustomGroup, MembershipRequest } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

defineEmits<{
	(e: 'close'): void
}>()

const requests = ref<MembershipRequest[]>([])
const loading = ref(false)
const statusFilter = ref<'all' | 'approved' | 'rejected'>('all')
const searchFilter = ref('')

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen && props.group) {
			statusFilter.value = 'all'
			searchFilter.value = ''
			fetchHistory()
		}
	},
	{ immediate: true },
)

async function fetchHistory() {
	loading.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/requests`)
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.requests)) {
			requests.value = response.data.requests
		}
	} catch (err) {
		console.error('Failed to load request history:', err)
	} finally {
		loading.value = false
	}
}

const processedRequests = computed(() => {
	// Only show resolved requests (exclude pending)
	return requests.value.filter((r) => r.status === 'approved' || r.status === 'rejected')
})

const countApproved = computed(() => {
	return processedRequests.value.filter((r) => r.status === 'approved').length
})

const countRejected = computed(() => {
	return processedRequests.value.filter((r) => r.status === 'rejected').length
})

const filteredRequests = computed(() => {
	let list = processedRequests.value
	if (statusFilter.value !== 'all') {
		list = list.filter((r) => r.status === statusFilter.value)
	}
	const query = searchFilter.value.trim().toLowerCase()
	if (query) {
		list = list.filter((r) => {
			const candMatch = r.candidate_displayName.toLowerCase().includes(query) || r.candidate_id.toLowerCase().includes(query)
			const reqMatch = r.requester_displayName.toLowerCase().includes(query) || r.requester_id.toLowerCase().includes(query)
			const procMatch = (r.processed_by_displayName && r.processed_by_displayName.toLowerCase().includes(query))
				|| (r.processed_by && r.processed_by.toLowerCase().includes(query))
			return candMatch || reqMatch || procMatch
		})
	}
	return list
})

function formatDate(dateStr?: string | null): string {
	if (!dateStr) return ''
	try {
		const d = new Date(dateStr)
		return d.toLocaleString('ru-RU', {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
		})
	} catch {
		return dateStr
	}
}
</script>

<style scoped>
.history-modal-content {
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

.status-tabs {
	display: flex;
	gap: 6px;
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

.requests-history-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
	max-height: 420px;
	overflow-y: auto;
	padding-right: 4px;
}

.history-card {
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

.history-card.status-approved {
	border: 1px solid var(--color-success);
	border-left: 4px solid var(--color-success);
}

.history-card.status-rejected {
	border: 1px solid var(--color-error);
	border-left: 4px solid var(--color-error);
}

.card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.candidate-info {
	display: flex;
	align-items: baseline;
	gap: 8px;
}

.candidate-name {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.candidate-email {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.status-tag {
	padding: 3px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
}

.tag-approved {
	color: inherit;
	border: 1px solid var(--color-success);
}

.tag-rejected {
	color: inherit;
	border: 1px solid var(--color-error);
}

.card-details {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 12px;
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

.detail-date {
	margin-left: auto;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
