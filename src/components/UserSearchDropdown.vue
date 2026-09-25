<template>
	<div class="sharing-search">
		<label v-if="label" :for="inputId" class="form-label">
			{{ label }}
		</label>
		<NcSelectUsers
			:id="inputId"
			v-model="selectedOption"
			class="sharing-search__input"
			:options="options"
			:loading="loading"
			:disabled="disabled"
			:placeholder="placeholder || t('Search Nextcloud users to add…')"
			:dropdown-should-open="dropdownShouldOpen"
			@search="onSearch"
			@update:model-value="onSelect" />
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import { ref, computed, nextTick } from 'vue'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { t } from '../utils/l10n'
import type { UserOption } from '../types'

const props = withDefaults(
	defineProps<{
		disabled?: boolean
		placeholder?: string
		excludeUids?: string[]
		label?: string
		inputId?: string
		apiEndpoint?: string
	}>(),
	{
		disabled: false,
		placeholder: '',
		excludeUids: () => [],
		label: '',
		inputId: 'user-search-dropdown',
		apiEndpoint: '/apps/user_groups_hzs/api/v1/users',
	},
)

const emit = defineEmits<{
	(e: 'select', user: UserOption): void
}>()

const selectedOption = ref<UserOption | null>(null)
const searchQuery = ref('')
const loading = ref(false)
const usersMap = ref<Map<string, UserOption>>(new Map())
let currentSearchReqId = 0
let searchTimer: ReturnType<typeof setTimeout> | null = null

function dropdownShouldOpen(vm: { noDrop?: boolean; search?: string; open?: boolean }): boolean {
	if (vm.noDrop) return false
	return Boolean(vm.search && vm.search.trim().length > 0 && vm.open)
}

const options = computed(() => {
	const query = searchQuery.value.trim().toLowerCase()
	if (!query) {
		return []
	}
	const excluded = new Set(props.excludeUids)
	const list: UserOption[] = []
	for (const [uid, user] of usersMap.value.entries()) {
		if (!excluded.has(uid)) {
			list.push({
				id: uid,
				uid,
				user: uid,
				displayName: user.displayName || uid,
				subname: user.subname || user.email || (user.displayName !== uid ? `@${uid}` : ''),
				email: user.email || '',
			})
		}
	}
	return list
})

function mergeUsers(users: UserOption[]) {
	const map = new Map(usersMap.value)
	for (const u of users) {
		const uid = u.uid || u.id || ''
		if (!uid) continue
		map.set(uid, {
			id: uid,
			uid,
			user: uid,
			displayName: u.displayName || uid,
			subname: u.subname || u.email || (u.displayName !== uid ? `@${uid}` : ''),
			email: u.email || '',
		})
	}
	usersMap.value = map
}

async function fetchUsers(search = '') {
async function fetchUsers(search: string) {
	const reqId = ++currentSearchReqId
	loading.value = true
	try {
		const url = generateUrl('/apps/user_groups_hzs/api/v1/users')
		const url = generateUrl(props.apiEndpoint)
		const response = await axios.get(url, {
			params: {
				search: search.trim(),
				limit: 100,
			},
		})
		if (reqId !== currentSearchReqId) return
		if (response.data && Array.isArray(response.data.users)) {
			mergeUsers(response.data.users)
		}
	} catch (err) {
		console.error('Error fetching users:', err)
	} finally {
		if (reqId === currentSearchReqId) {
			loading.value = false
		}
	}
}

function onSearch(query: string) {
	searchQuery.value = query
	if (searchTimer) {
		clearTimeout(searchTimer)
	}
	const trimmed = query.trim()
	if (!trimmed) {
		usersMap.value = new Map()
		return
	}
	searchTimer = setTimeout(() => {
		fetchUsers(query)
		fetchUsers(trimmed)
	}, 300)
}

function onSelect(option: UserOption | null) {
	if (!option) return
	emit('select', option)
	nextTick(() => {
		selectedOption.value = null
		searchQuery.value = ''
		usersMap.value = new Map()
	})
}

onMounted(() => {
	fetchUsers()
})
</script>

<style lang="scss" scoped>
.sharing-search {
	display: flex;
	flex-direction: column;
	margin-top: 8px;
	margin-bottom: 4px;

	.form-label {
		font-weight: 600;
		font-size: 14px;
		color: var(--color-main-text);
		margin-bottom: 4px;
	}

	&__input {
		width: 100%;
	}
}
</style>

