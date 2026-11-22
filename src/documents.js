// What a watched document's state reads as in «Documentos».

/** Its Reading's status, as the table says it. */
export const READING = { read: 'Leído', unread: 'Sin leer', scan: 'Escaneado, sin texto', failed: 'No se pudo leer' }

/** «2 hallazgos», «1 hallazgo», «Sin hallazgos». */
export const findingsText = (count) => (count === 0 ? 'Sin hallazgos' : `${count} ${count === 1 ? 'hallazgo' : 'hallazgos'}`)
