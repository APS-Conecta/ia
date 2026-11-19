// What a Review's state and findings read as, in the words of the mockup.

export const STATES = { waiting: 'En espera', working: 'En curso', done: 'Lista', failed: 'Falló' }

export const KINDS = {
	error: { label: 'Error', many: 'errores', variant: 'error' },
	missing: { label: 'Falta', many: 'faltas', variant: 'warning' },
	unclear: { label: 'Poco claro', many: 'poco claro', variant: 'primary' },
}

/** A Review still under way, which its page keeps asking about. */
export const underWay = (review) => review.state === 'waiting' || review.state === 'working'

export const when = (seconds) => new Date(seconds * 1000).toLocaleString('es-CL', { dateStyle: 'medium', timeStyle: 'short' })
