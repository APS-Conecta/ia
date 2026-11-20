// What a Review's state and findings read as, in the words of the mockup.

export const STATES = { waiting: 'En espera', working: 'En curso', done: 'Lista', failed: 'Falló' }

export const KINDS = {
	error: { label: 'Error', many: 'errores', variant: 'error' },
	missing: { label: 'Falta', many: 'faltas', variant: 'warning' },
	unclear: { label: 'Poco claro', many: 'poco claro', variant: 'primary' },
}

/** A Review still under way: waiting or working. */
export const underWay = (review) => review.state === 'waiting' || review.state === 'working'

/** A Review its page keeps asking about: under way, but not one waiting for Off hours. */
export const moving = (review) => review.state === 'working' || (review.state === 'waiting' && !review.offHours)

/** When a long document is reviewed: the Off hours window, «de 22:00 a 06:00». */
export const offHoursText = (review) => `de ${review.offHoursWindow.start} a ${review.offHoursWindow.end}`

/** Where a Review under way stands, in a sentence. */
export function progress(review) {
	const step = review.steps > 1 && review.step > 1 ? ` Va en el paso ${review.step} de ${review.steps}.` : ''
	if (review.state === 'waiting' && review.offHours) {
		return review.origin === 'manual'
			? `Es un documento largo: se revisa fuera de horario, ${offHoursText(review)}. Te avisaremos cuando esté lista.${step}`
			: `Revisión automática: se hace fuera de horario, ${offHoursText(review)}.${step}`
	}
	if (review.state === 'waiting') {
		return 'En la fila del modelo.'
	}
	return review.steps > 1
		? `Leyendo el documento y escribiendo la revisión: paso ${review.step} de ${review.steps}…`
		: 'Leyendo el documento y escribiendo la revisión…'
}

/** When a Review was asked for, or, for an automatic one, when its file last arrived or changed. */
export const askedText = (review) => review.origin === 'manual' ? `Pedida el ${when(review.requestedAt)}` : `Automática, del ${when(review.requestedAt)}`

export const when = (seconds) => new Date(seconds * 1000).toLocaleString('es-CL', { dateStyle: 'medium', timeStyle: 'short' })
