<template>
	<div class="ia-page">
		<h2>Revisiones</h2>
		<p class="ia-lead">
			La IA revisa un documento y te dice qué corregir. El documento no se modifica.
		</p>

		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state === 'failed'" type="error">
			No se pudo cargar «Revisiones». Recarga la página; si vuelve a ocurrir, avisa al administrador.
		</NcNoteCard>
		<NcEmptyContent v-else-if="reviews.length === 0"
			name="Todavía no pides ninguna revisión"
			description="Con «Revisar un documento» eliges un archivo PDF, DOCX, ODT, TXT o MD de tus Archivos, y la IA te dice qué corregir.">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileDocumentCheckOutline" />
			</template>
		</NcEmptyContent>
		<section v-else>
			<h3>Revisiones</h3>
			<p class="ia-hint">
				Las que pides las ves solo tú; las automáticas, todos los que pueden abrir el archivo.
			</p>
			<ul>
				<NcListItem v-for="review in reviews"
					:key="review.id"
					:name="review.name"
					:href="`#revisiones/${review.id}`"
					:details="STATES[review.state]"
					@click="open($event, review.id)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
					</template>
					<template #subname>
						{{ underWay(review) ? progress(review) : askedText(review) }}
					</template>
				</NcListItem>
			</ul>
		</section>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from './api.js'
import { mdiFileDocumentCheckOutline, mdiFileDocumentOutline } from './icons.js'
import { askedText, moving, progress, STATES, underWay } from './reviews.js'

/** Seconds between two looks at Reviews still under way. */
const POLL = 10

/** «Revisiones»: the person's Reviews with their state, newest first. */
export default {
	name: 'ReviewList',
	components: { NcEmptyContent, NcIconSvgWrapper, NcListItem, NcLoadingIcon, NcNoteCard },
	setup: () => ({ askedText, mdiFileDocumentCheckOutline, mdiFileDocumentOutline, progress, STATES, underWay }),
	data: () => ({ state: 'loading', reviews: [], timer: null }),
	mounted() {
		this.load()
	},
	unmounted() {
		clearTimeout(this.timer)
	},
	methods: {
		/** NcListItem opens a link in a new tab: a plain click opens the report here instead. */
		open(event, id) {
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return
			}
			event.preventDefault()
			window.location.hash = `#revisiones/${id}`
		},
		async load() {
			try {
				this.reviews = (await ocsGet('reviews')).reviews
				this.state = 'ready'
			} catch (error) {
				console.error('ia: «Revisiones» did not load', error)
				this.state = 'failed'
				return
			}
			if (this.reviews.some(moving)) {
				this.timer = setTimeout(this.load, POLL * 1000)
			}
		},
	},
}
</script>
