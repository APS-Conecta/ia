// What a Bulletin's schedule and due date read as.

export const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo']

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

/** When the next Edition is due, in a sentence. */
export function dueText(bulletin) {
	if (bulletin.due === null) {
		return 'La próxima edición se escribe la noche después de que cambie una de sus fuentes.'
	}
	const date = new Date(`${bulletin.due}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
	return `La primera edición sale el ${date}, escrita fuera de horario la noche anterior.`
}
