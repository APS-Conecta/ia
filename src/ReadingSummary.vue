<template>
	<div class="ia-form">
		<template v-if="document.status === 'read'">
			<div v-if="document.summary !== null" class="ia-chips">
				<NcChip text="Escrito por IA" variant="primary" :icon-path="mdiCreation" no-close />
				<span class="ia-hint">Leído el {{ when(document.readAt) }}</span>
			</div>
			<p>{{ document.summary ?? GONE }}</p>
			<p>{{ document.expires === null ? 'No encontró fecha de vigencia.' : `Vence el ${fullDate(document.expires)}.` }}</p>
			<p v-if="document.cargos.length > 0">
				Para: {{ document.cargos.join(', ') }}.
			</p>
		</template>
		<NcNoteCard v-else :type="document.status === 'unread' ? 'info' : 'warning'">
			{{ NO_SUMMARY[document.status] }}
		</NcNoteCard>
	</div>
</template>

<script>
import NcChip from '@nextcloud/vue/components/NcChip'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { fullDate, GONE, UNREADABLE } from './bulletins.js'
import { mdiCreation } from './icons.js'
import { when } from './reviews.js'

/** Why a document shows no summary, by its Reading's status; «unread» only in «Documentos». */
const NO_SUMMARY = { ...UNREADABLE, unread: 'Esta versión no tiene lectura todavía. «IA» la lee fuera de horario: con la próxima edición de un boletín que la tenga entre sus fuentes, o la noche siguiente si está en la carpeta de una acción automática.' }

/** A document's Reading, marked as written by AI: in an Edition's dialog and in «Documentos». */
export default {
	name: 'ReadingSummary',
	components: { NcChip, NcNoteCard },
	props: {
		/** A document with its Reading: status, summary, readAt, expires and Cargos by name. */
		document: { type: Object, required: true },
	},
	setup: () => ({ GONE, NO_SUMMARY, fullDate, mdiCreation, when }),
}
</script>
