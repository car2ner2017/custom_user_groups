export interface UserOption {
	uid: string
	displayName: string
}

export interface CustomGroup {
	group_id: string
	name: string
	creator_id: string
	creator_displayName: string
	created_at: string
	member_ids: string[]
	members: UserOption[]
	member_count: number
	can_edit: boolean
	is_creator: boolean
}

export interface AppState {
	current_user_id: string | null
	is_admin: boolean
	groups: CustomGroup[]
}
