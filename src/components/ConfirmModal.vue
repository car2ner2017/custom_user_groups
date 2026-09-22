<template>
	<NcModal
		v-if="show"
		:name="title"
		size="small"
		@close="$emit('close')">
		<div class="confirm-content">
			<p class="confirm-message">
				{{ message }}
			</p>
			<div class="confirm-actions">
				<NcButton
					type="button"
					variant="secondary"
					:disabled="loading"
					@click="$emit('close')">
					{{ t('Cancel') }}
				</NcButton>
				<NcButton
					type="button"
					variant="error"
					:disabled="loading"
					@click="$emit('confirm')">
					{{ confirmText || t('Delete') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup lang="ts">
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import { t } from '../utils/l10n'

defineProps<{
	show: boolean
	title: string
	message: string
	confirmText?: string
	loading?: boolean
}>()

defineEmits<{
	(e: 'close'): void
	(e: 'confirm'): void
}>()
</script>

<style scoped>
.confirm-content {
	padding: 16px 20px;
}

.confirm-message {
	font-size: 14px;
	line-height: 1.5;
	color: var(--color-main-text);
	margin-bottom: 20px;
}

.confirm-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}
</style>
