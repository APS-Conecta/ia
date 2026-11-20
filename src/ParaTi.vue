<template>
	<div class="ia-page">
		<h2>Para ti</h2>

		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state === 'failed'" type="error">
			No se pudo cargar «Para ti». Recarga la página; si vuelve a ocurrir, avisa al administrador.
		</NcNoteCard>
		<NcEmptyContent v-else-if="paraTi.bulletins.length === 0"
			name="Todavía no recibes ningún boletín"
			description="Cuando un grupo editor te sume a los lectores de un boletín, aquí verás tus próximas reuniones, los documentos nuevos o cambiados para tu cargo y las últimas ediciones.">
			<template #icon>
				<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
			</template>
		</NcEmptyContent>
		<template v-else>
			<p class="ia-lead">
				Lo de tus boletines que te toca: tus próximas reuniones, los documentos nuevos o cambiados para tu cargo y los protocolos por vencer.
			</p>
			<PersonalList :meetings="paraTi.meetings"
				:documents="paraTi.documents"
				ahead
				@open="reading = $event" />

			<h3>Protocolos por vencer</h3>
			<p v-if="paraTi.expiring.length === 0" class="ia-hint">
				Ningún protocolo de tus boletines vence en los próximos 30 días.
			</p>
			<ul v-else>
				<NcListItem v-for="document in paraTi.expiring"
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

			<h3>Últimas ediciones</h3>
			<p v-if="paraTi.editions.length === 0" class="ia-hint">
				Tus boletines todavía no tienen ediciones.
			</p>
			<ul v-else>
				<NcListItem v-for="edition in paraTi.editions"
					:key="edition.id"
					:name="edition.bulletin.name"
					:href="`#boletines/${edition.bulletin.id}/${edition.id}`"
					@click="follow($event, edition)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
					</template>
					<template #subname>
						Edición del {{ longDate(edition.date) }}
					</template>
				</NcListItem>
			</ul>
		</template>
		<ReadingDialog v-if="reading" :document="reading" @close="reading = null" />
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import PersonalList from './PersonalList.vue'
import ReadingDialog from './ReadingDialog.vue'
import { ocsGet } from './api.js'
import { longDate } from './bulletins.js'
import { mdiCalendarOutline, mdiNewspaperVariantOutline } from './icons.js'

/**
 * «Para ti», everyone's start page: the Personal lists of the latest Edition of each Bulletin the
 * person reads, merged, the protocols about to expire in them, and those Editions.
 */
export default {
	name: 'ParaTi',
	components: { NcEmptyContent, NcIconSvgWrapper, NcListItem, NcLoadingIcon, NcNoteCard, PersonalList, ReadingDialog },
	setup: () => ({ longDate, mdiCalendarOutline, mdiNewspaperVariantOutline }),
	data: () => ({ state: 'loading', paraTi: null, reading: null }),
	async mounted() {
		try {
			this.paraTi = await ocsGet('para-ti')
			this.state = 'ready'
		} catch (error) {
			console.error('ia: «Para ti» did not load', error)
			this.state = 'failed'
		}
	},
	methods: {
		open(event, document) {
			event.preventDefault()
			this.reading = document
		},
		/** NcListItem opens a link in a new tab: a plain click opens the Edition here instead. */
		follow(event, edition) {
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return
			}
			event.preventDefault()
			window.location.hash = `#boletines/${edition.bulletin.id}/${edition.id}`
		},
	},
}
</script>
