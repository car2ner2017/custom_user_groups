export interface UserOption {
	id?: string
	uid: string
	displayName: string
	email?: string
}

export interface GroupOption {
	id: string
	name: string
	is_cug: boolean
}

export interface Delegation {
	id: number
	group_id: string
	user_id: string
	displayName: string
	email: string
	level: 'manage' | 'moderate'
	created_at: string
}

export interface MembershipRequest {
	id: number
	group_id: string
	candidate_id: string
	candidate_displayName: string
	candidate_email: string
	requester_id: string
	requester_displayName: string
	status: 'pending' | 'approved' | 'rejected'
	created_at: string
	updated_at: string
	processed_by?: string | null
	processed_by_displayName?: string | null
	processed_by_email?: string | null
}

export interface GroupPermissions {
	can_edit_name: boolean
	can_edit_members: boolean
	can_delete: boolean
	can_delegate: boolean
	can_moderate_requests: boolean
	can_request_member: boolean
	delegation_level: 'manage' | 'moderate' | null
}

export interface CustomGroup {
	group_id: string
	name: string
	creator_id: string
	creator_displayName: string
	creator_email?: string
	created_at: string
	member_ids: string[]
	members: UserOption[]
	member_count: number
	delegations: Delegation[]
	delegates_manage: Delegation[]
	delegates_moderate: Delegation[]
	pending_requests_count: number
	can_edit: boolean
	is_creator: boolean
	permissions: GroupPermissions
}

export interface AppState {
	current_user_id: string | null
	is_admin: boolean
	can_create_groups?: boolean
	groups: CustomGroup[]
}

export interface AdminSettingsData {
	create_restriction_enabled: boolean
	create_allowed_users: string[]
	create_allowed_groups: string[]
	access_restriction_enabled: boolean
	access_forbidden_users: string[]
	access_forbidden_groups: string[]
}

export interface AdminSettingsResponse {
	settings: AdminSettingsData
	create_allowed_users_details: UserOption[]
	create_allowed_groups_details: GroupOption[]
	access_forbidden_users_details: UserOption[]
	access_forbidden_groups_details: GroupOption[]
}
