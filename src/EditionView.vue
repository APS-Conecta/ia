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

		<NcDialog v-if="reading"
			:name="reading.name"
			:buttons="[{ label: 'Cerrar' }]"
			@closing="reading = null">
			<template v-if="reading.status === 'read'">
				<div v-if="reading.summary !== null" class="ia-chips">
					<NcChip text="Escrito por IA" variant="primary" :icon-path="mdiCreation" no-close />
					<span class="ia-hint">Leído el {{ when(reading.readAt) }}</span>
				</div>
				<p>{{ reading.summary ?? GONE }}</p>
				<p>{{ reading.expires === null ? 'No encontramos su fecha de vencimiento.' : `Vence el ${longDate(reading.expires)}.` }}</p>
				<p v-if="reading.cargos.length > 0">
					Para: {{ reading.cargos.join(', ') }}.
				</p>
			</template>
			<p v-else>
				{{ UNREADABLE[reading.status] }}
			</p>
			<div class="ia-chips">
				<NcButton :href="fileLink(reading)" variant="secondary">
					<template #icon>
						<NcIconSvgWrapper :path="mdiOpenInNew" />
					</template>
					Abrir el documento
				</NcButton>
			</div>
		</NcDialog>
	</section>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from './api.js'
import { longDate } from './bulletins.js'
import { mdiCalendarOutline, mdiCreation, mdiFileDocumentOutline, mdiOpenInNew } from './icons.js'
import { when } from './reviews.js'

/** Why a document has no summary, by its Reading's status. */
const UNREADABLE = {
	scan: 'Es un documento escaneado: «IA» todavía no lee escaneos.',
	failed: 'No se pudo leer: puede estar dañado o protegido con contraseña.',
}

/** A document whose Reading went with its version: it changed or was deleted since. */
const GONE = 'Su lectura ya no está: el documento cambió o se eliminó después de esta edición.'

/**
 * One Edition: the Resumen, marked as written by AI, and its sections; each document opens its
 * Reading, and from there the file.
 */
export default {
	name: 'EditionView',
	components: { NcButton, NcChip, NcDialog, NcIconSvgWrapper, NcListItem, NcLoadingIcon, NcNoteCard },
	props: {
		id: { type: Number, required: true },
	},
	setup: () => ({ GONE, longDate, mdiCalendarOutline, mdiCreation, mdiFileDocumentOutline, mdiOpenInNew, UNREADABLE, when }),
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
		fileLink(document) {
			return generateUrl(`/f/${document.fileId}`)
		},
	},
}
</script>
