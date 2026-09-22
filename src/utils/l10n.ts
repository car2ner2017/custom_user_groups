import { t as ncTranslate } from '@nextcloud/l10n'

/**
 * Translate a string for the Custom User Groups app
 * @param text The English text to translate
 * @param vars Optional variables/placeholders to replace in the text
 */
export function t(text: string, vars?: Record<string, string | number | boolean | null | undefined>): string {
	return ncTranslate('customusergroups', text, vars as Record<string, string | number>)
}
