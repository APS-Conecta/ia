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

/** Where a Review under way stands, in a sentence. */
export function progress(review) {
	const step = review.steps > 1 && review.step > 1 ? ` Va en el paso ${review.step} de ${review.steps}.` : ''
	if (review.state === 'waiting' && review.offHours) {
		return `Es un documento largo: se revisa fuera de horario. Te avisaremos cuando esté lista.${step}`
	}
	if (review.state === 'waiting') {
		return 'En la fila del modelo.'
	}
	return review.steps > 1
		? `Leyendo el documento y escribiendo la revisión: paso ${review.step} de ${review.steps}…`
		: 'Leyendo el documento y escribiendo la revisión…'
}

export const when = (seconds) => new Date(seconds * 1000).toLocaleString('es-CL', { dateStyle: 'medium', timeStyle: 'short' })
