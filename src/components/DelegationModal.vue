<template>
	<NcModal
		v-if="show"
		name="Делегирование прав управления группой"
		size="normal"
		@close="$emit('close')">
		<div class="delegation-modal-content">
			<p class="delegation-description">
				Вы можете делегировать права управления или модерации группы <strong>только действующим участникам</strong> этой группы.
			</p>

			<!-- Current Delegations Section -->
			<div class="section-title">
				<h3>Назначенные делегаты ({{ delegations.length }})</h3>
			</div>

			<div v-if="loadingDelegations" class="loading-state">
				<NcLoadingIcon :size="24" /> Загрузка делегатов...
			</div>
			<div v-else-if="delegations.length === 0" class="empty-state">
				В группе пока нет назначенных делегатов.
			</div>
			<div v-else class="delegates-list">
				<div
					v-for="del in delegations"
					:key="del.user_id"
					class="delegate-card">
					<div class="delegate-info">
						<span class="delegate-name">{{ del.displayName }}</span>
						<span class="delegate-email">{{ del.email || ('@' + del.user_id) }}</span>
					</div>

					<div class="delegate-actions">
						<!-- Level Switcher -->
						<select
							class="level-select"
							:value="del.level"
							:disabled="updatingUid === del.user_id"
							@change="onLevelChange(del.user_id, ($event.target as HTMLSelectElement).value)">
							<option value="manage">
								Управление
							</option>
							<option value="moderate">
								Модерация
							</option>
						</select>

						<NcButton
							type="tertiary-no-background"
							size="small"
							title="Отозвать права"
							:disabled="updatingUid === del.user_id"
							@click="revoke(del.user_id)">
							Отозвать
						</NcButton>
					</div>
				</div>
			</div>

			<div class="divider" />

			<!-- Add New Delegation Section -->
			<div class="section-title">
				<h3>Назначить делегата</h3>
			</div>

			<div v-if="assignableMembers.length === 0" class="no-assignable-hint">
				Все участники группы уже назначены делегатами или в группе нет других участников.
			</div>
			<form v-else class="add-delegation-form" @submit.prevent="submitDelegation">
				<div class="form-group">
					<label for="delegate-member-select" class="form-label">
						Выберите участника группы <span class="required">*</span>
					</label>
					<select
						id="delegate-member-select"
						v-model="selectedMemberUid"
						class="member-select"
						:disabled="submitting"
						required>
						<option value="" disabled>
							-- Выберите участника --
						</option>
						<option
							v-for="member in assignableMembers"
							:key="member.uid"
							:value="member.uid">
							{{ member.displayName }} ({{ member.email || member.uid }})
						</option>
					</select>
				</div>

				<div class="form-group">
					<span class="form-label">Уровень прав <span class="required">*</span></span>
					<div class="level-options">
						<label class="level-radio-label">
							<input
								v-model="selectedLevel"
								type="radio"
								value="manage"
								:disabled="submitting">
							<div class="level-text">
								<strong>Управление (Уровень 1)</strong>
								<span class="level-desc">
									Добавление и исключение участников, изменение названия, удаление группы, модерация заявок.
								</span>
							</div>
						</label>
						<label class="level-radio-label">
							<input
								v-model="selectedLevel"
								type="radio"
								value="moderate"
								:disabled="submitting">
							<div class="level-text">
								<strong>Модерация (Уровень 2)</strong>
								<span class="level-desc">
									Добавление и исключение участников, модерация заявок. Без права переименования и удаления группы.
								</span>
							</div>
						</label>
					</div>
				</div>

				<div class="form-actions">
					<NcButton
						type="primary"
						native-type="submit"
						:disabled="submitting || !selectedMemberUid">
						{{ submitting ? 'Назначение...' : 'Назначить права' }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import type { CustomGroup, Delegation } from '../types'

const props = defineProps<{
	show: boolean
	group: CustomGroup
}>()

const emit = defineEmits<{
	(e: 'close'): void
	(e: 'updated'): void
}>()

const delegations = ref<Delegation[]>([])
const loadingDelegations = ref(false)
const selectedMemberUid = ref('')
const selectedLevel = ref<'manage' | 'moderate'>('manage')
const submitting = ref(false)
const updatingUid = ref<string | null>(null)

watch(
	() => props.show,
	(isOpen) => {
		if (isOpen && props.group) {
			selectedMemberUid.value = ''
			selectedLevel.value = 'manage'
			fetchDelegations()
		}
	},
	{ immediate: true },
)

const assignableMembers = computed(() => {
	if (!props.group) return []
	const delegatedUids = new Set(delegations.value.map((d) => d.user_id))
	// Exclude creator and users who are already delegates
	return props.group.members.filter(
		(m) => m.uid !== props.group.creator_id && !delegatedUids.has(m.uid),
	)
})

async function fetchDelegations() {
	loadingDelegations.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.get(url)
		if (response.data && Array.isArray(response.data.delegations)) {
			delegations.value = response.data.delegations
		}
	} catch (err: unknown) {
		console.error('Failed to load delegations:', err)
	} finally {
		loadingDelegations.value = false
	}
}

async function onLevelChange(userId: string, newLevel: string) {
	if (newLevel !== 'manage' && newLevel !== 'moderate') return
	updatingUid.value = userId
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.post(url, {
			userId,
			level: newLevel,
		})
		const idx = delegations.value.findIndex((d) => d.user_id === userId)
		if (idx !== -1) {
			delegations.value[idx] = response.data
		}
		showSuccess('Уровень прав обновлен')
		emit('updated')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка обновления уровня'
		showError(msg)
		fetchDelegations()
	} finally {
		updatingUid.value = null
	}
}

async function revoke(userId: string) {
	updatingUid.value = userId
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations/${userId}`)
		await axios.delete(url)
		delegations.value = delegations.value.filter((d) => d.user_id !== userId)
		showSuccess('Делегирование прав успешно отозвано')
		emit('updated')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка при отзыве прав'
		showError(msg)
	} finally {
		updatingUid.value = null
	}
}

async function submitDelegation() {
	if (!selectedMemberUid.value) return
	submitting.value = true
	try {
		const url = generateUrl(`/apps/customusergroups/api/v1/groups/${props.group.group_id}/delegations`)
		const response = await axios.post(url, {
			userId: selectedMemberUid.value,
			level: selectedLevel.value,
		})
		delegations.value.push(response.data)
		selectedMemberUid.value = ''
		showSuccess('Права успешно делегированы')
		emit('updated')
	} catch (err: unknown) {
		const axiosErr = err as { response?: { data?: { error?: string } }; message?: string }
		const msg = axiosErr.response?.data?.error || axiosErr.message || 'Ошибка при назначении прав'
		showError(msg)
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
.delegation-modal-content {
	padding: 16px 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.delegation-description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	line-height: 1.4;
}

.section-title h3 {
	font-size: 15px;
	font-weight: 600;
	margin: 0;
	color: var(--color-main-text);
}

.loading-state,
.empty-state,
.no-assignable-hint {
	padding: 12px;
	text-align: center;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-element);
}

.delegates-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-height: 200px;
	overflow-y: auto;
}

.delegate-card {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
}

.delegate-info {
	display: flex;
	flex-direction: column;
}

.delegate-name {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.delegate-email {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.delegate-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

.level-select,
.member-select {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.member-select {
	width: 100%;
	padding: 8px;
}

.divider {
	height: 1px;
	background-color: var(--color-border);
	margin: 4px 0;
}

.add-delegation-form {
	display: flex;
	flex-direction: column;
	gap: 14px;
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

.required {
	color: var(--color-error);
}

.level-options {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.level-radio-label {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	cursor: pointer;
}

.level-radio-label:hover {
	background-color: var(--color-background-hover);
}

.level-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.level-text strong {
	font-size: 13px;
	color: var(--color-main-text);
}

.level-desc {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	line-height: 1.3;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
}
</style>

