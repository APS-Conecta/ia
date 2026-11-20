// What a Bulletin's schedule and due date read as.

export const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

/** The last day a schedule's day can be, by its kind: a week's Sunday, a month's 28th (every month has it). */
export const LAST_DAY = { weekly: 7, monthly: 28 }

export const KINDS = [
	{ kind: 'daily', label: 'Cada día' },
	{ kind: 'weekly', label: 'Cada semana' },
	{ kind: 'monthly', label: 'Cada mes' },
	{ kind: 'sources', label: 'Cuando cambien sus fuentes' },
]

/** «Cada lunes», «Cada mes, el día 15», …, from {kind, day}. */
export function scheduleText({ kind, day }) {
	return {
		daily: 'Cada día',
		weekly: `Cada ${DAYS[day - 1]}`,
		monthly: `Cada mes, el día ${day}`,
		sources: 'Cuando cambien sus fuentes',
	}[kind]
}

/** «viernes, 20 de noviembre», from a date (Y-m-d). */
export function longDate(date) {
	return new Date(`${date}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
}

/** When the next Edition is due, in a sentence. */
export function dueText(bulletin) {
	if (bulletin.due === null) {
		return 'La próxima edición se escribe la noche después de que cambie una de sus fuentes.'
	}
	return `La primera edición sale el ${longDate(bulletin.due)}, escrita fuera de horario la noche anterior.`
}
