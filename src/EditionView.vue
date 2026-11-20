<template>
	<section class="ia-edition">
		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state !== 'ready'" type="warning">
			{{ state === 'gone' ? 'Esta edición ya no está, o ya no puedes leerla: puede que ya no puedas abrir uno de sus documentos.' : 'No se pudo cargar la edición. Recarga la página.' }}
		</NcNoteCard>
		<template v-else>
			<h3>Edición del {{ longDate(edition.date) }}</h3>
			<template v-if="paragraphs.length > 0">
				<div class="ia-chips">
					<NcChip text="Escrito por IA" variant="primary" :icon-path="mdiCreation" no-close />
				</div>
				<p v-for="(paragraph, index) in paragraphs" :key="index">
					{{ paragraph }}
				</p>
			</template>
			<p v-else class="ia-hint">
				Las fuentes de este boletín no tienen documentos que «IA» pueda leer, así que esta edición no tiene resumen.
			</p>

			<h3>Para ti en esta edición</h3>
			<PersonalLists :meetings="edition.personal.meetings" :documents="edition.personal.documents" @open="reading = $event" />

			<h3>Documentos nuevos y cambiados</h3>
			<p v-if="edition.changed.length === 0" class="ia-hint">
				Ningún documento es nuevo ni cambió desde la edición anterior.
			</p>
			<ul v-else>
				<NcListItem v-for="document in edition.changed"
					:key="document.fileId"
					:name="document.name"
					href="#"
					@click="open($event, document)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
					</template>
					<template #subname>
						{{ document.status === 'read' ? (document.summary ?? GONE) : UNREADABLE[document.status] }}
					</template>
				</NcListItem>
			</ul>

			<h3>Protocolos por vencer</h3>
			<p v-if="edition.expiring.length === 0" class="ia-hint">
				Ningún protocolo vence en los próximos 30 días.
			</p>
			<ul v-else>
				<NcListItem v-for="document in edition.expiring"
					:key="document.fileId"
					:name="document.name"
					href="#"
					@click="open($event, document)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCalendarOutline" />
					</template>
					<template #subname>
						Vence el {{ longDate(document.expires) }}
					</template>
				</NcListItem>
			</ul>
			<template v-if="edition.undated.length > 0">
				<NcNoteCard type="warning">
					En estos documentos no encontramos una fecha de vencimiento: revisa si la tienen.
				</NcNoteCard>
				<ul>
					<NcListItem v-for="document in edition.undated"
						:key="document.fileId"
						:name="document.name"
						href="#"
						@click="open($event, document)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
						</template>
						<template #subname>
							Sin fecha de vencimiento
						</template>
					</NcListItem>
				</ul>
			</template>

			<template v-if="edition.unreadable.length > 0">
				<h3>Fuentes que no se pudieron leer</h3>
				<ul>
					<NcListItem v-for="document in edition.unreadable"
						:key="document.fileId"
						:name="document.name"
						href="#"
						@click="open($event, document)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
						</template>
						<template #subname>
							{{ UNREADABLE[document.status] }}
						</template>
					</NcListItem>
				</ul>
			</template>
		</template>

		<ReadingDialog v-if="reading" :document="reading" @close="reading = null" />
	</section>
</template>

<script>
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from './api.js'
import PersonalLists from './PersonalLists.vue'
import ReadingDialog from './ReadingDialog.vue'
import { GONE, longDate, UNREADABLE } from './bulletins.js'
import { mdiCalendarOutline, mdiCreation, mdiFileDocumentOutline } from './icons.js'

/**
 * One Edition: the Resumen, marked as written by AI, and its sections; each document opens its
 * Reading, and from there the file.
 */
export default {
	name: 'EditionView',
	components: { NcChip, NcIconSvgWrapper, NcListItem, NcLoadingIcon, NcNoteCard, PersonalLists, ReadingDialog },
	props: {
		id: { type: Number, required: true },
	},
	setup: () => ({ GONE, longDate, mdiCalendarOutline, mdiCreation, mdiFileDocumentOutline, UNREADABLE }),
	data: () => ({ state: 'loading', edition: null, reading: null }),
	computed: {
		paragraphs() {
			return this.edition.resumen.split(/\n\s*\n/).map((paragraph) => paragraph.trim()).filter(Boolean)
		},
	},
	async mounted() {
		try {
			this.edition = (await ocsGet(`editions/${this.id}`)).edition
			this.state = 'ready'
		} catch (error) {
			this.state = error?.response?.status === 404 ? 'gone' : 'failed'
			if (this.state === 'failed') {
				console.error('ia: the Edition did not load', error)
			}
		}
	},
	methods: {
		open(event, document) {
			event.preventDefault()
			this.reading = document
		},
	},
}
</script>
