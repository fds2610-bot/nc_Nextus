import { getCanonicalLocale, t } from '@nextcloud/l10n'

export const typeLabel = (type) => ({
	mandatory: t('nc_nexus', 'Mandatory / regulatory'),
	strategic: t('nc_nexus', 'Strategic'),
	efficiency: t('nc_nexus', 'Efficiency'),
	lifecycle: t('nc_nexus', 'Maintenance / lifecycle'),
	innovation: t('nc_nexus', 'Innovation'),
})[type] || type

export const statusLabel = (status) => ({
	draft: t('nc_nexus', 'Draft'),
	submitted: t('nc_nexus', 'Submitted'),
})[status] || status

export const fieldLabel = (field) => ({
	title: t('nc_nexus', 'Title'),
	demandType: t('nc_nexus', 'Type'),
	description: t('nc_nexus', 'Description'),
	problemStatement: t('nc_nexus', 'Problem statement'),
	expectedBenefit: t('nc_nexus', 'Expected benefit'),
	status: t('nc_nexus', 'Status'),
})[field] || field

export const actionLabel = (action) => ({
	create: t('nc_nexus', 'Created'),
	update: t('nc_nexus', 'Changed'),
	status_change: t('nc_nexus', 'Status changed'),
	delete: t('nc_nexus', 'Deleted'),
})[action] || action

export const formatDate = (ts) => ts ? new Date(ts * 1000).toLocaleString(getCanonicalLocale(), { dateStyle: 'medium', timeStyle: 'short' }) : ''

// Feste Farbe je Antragstyp (Navigation, Liste); Text steht immer daneben.
export const typeColor = (type) => ({
	mandatory: 'E9322D',
	strategic: '0082C9',
	efficiency: '46BA61',
	lifecycle: 'ECA700',
	innovation: '9B59B6',
})[type] || '888888'

export const formatDay = (ts) => ts ? new Date(ts * 1000).toLocaleDateString(getCanonicalLocale(), { dateStyle: 'medium' }) : ''

export const channelLabel = (channel) => ({
	form: t('nc_nexus', 'Form'),
	ticket: t('nc_nexus', 'Ticket system'),
	mail: t('nc_nexus', 'E-mail'),
	api: t('nc_nexus', 'Interface'),
})[channel] || channel
