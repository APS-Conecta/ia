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

/** Why a document has no summary, by its Reading's status. */
export const UNREADABLE = {
	scan: 'Es un documento escaneado: «IA» todavía no lee escaneos.',
	failed: 'No se pudo leer: puede estar dañado o protegido con contraseña.',
}

/** A document whose Reading went with its version: it changed or was deleted since. */
export const GONE = 'Su lectura ya no está: el documento cambió o se eliminó después de esta edición.'

/** «lunes, 23 de noviembre, de 10:00 a 11:00», from a meeting's start and end (seconds). */
export function meetingText({ start, end }) {
	const day = new Date(start * 1000).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
	const time = (seconds) => new Date(seconds * 1000).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' })
	return end === null ? `${day}, a las ${time(start)}` : `${day}, de ${time(start)} a ${time(end)}`
}

/** «viernes, 20 de noviembre», from a date (Y-m-d). */
export function longDate(date) {
	return new Date(`${date}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'long', day: 'numeric', month: 'long' })
}

/** When the next Edition is due, in a sentence. */
export function dueText(bulletin) {
	if (bulletin.due === null) {
		return 'La primera edición se escribe fuera de horario la próxima noche; después, solo la noche después de que cambie una de sus fuentes.'
	}
	return `La primera edición sale el ${longDate(bulletin.due)}, escrita fuera de horario la noche anterior.`
}
