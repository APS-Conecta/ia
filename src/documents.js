// What a watched document's state reads as in «Documentos».

import { UNREADABLE } from './bulletins.js'

/** Its Reading's status, as the table says it. */
export const READING = { read: 'Leído', unread: 'Sin leer', scan: 'Escaneado, sin texto', failed: 'No se pudo leer' }

/** Why the sidebar shows no summary, by the Reading's status. */
export const NO_SUMMARY = { ...UNREADABLE, unread: 'Esta versión no tiene lectura todavía: «IA» lee los documentos de las fuentes de un boletín cuando escribe su próxima edición.' }

/** «2 hallazgos», «1 hallazgo», «Sin hallazgos». */
export const findingsText = (count) => (count === 0 ? 'Sin hallazgos' : `${count} ${count === 1 ? 'hallazgo' : 'hallazgos'}`)

/** «12 de diciembre de 2026», from a date (Y-m-d). */
export function fullDate(date) {
	return new Date(`${date}T12:00:00`).toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' })
}
