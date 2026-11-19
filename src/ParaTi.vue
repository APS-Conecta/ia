<template>
	<div class="ia-page">
		<h2>Para ti</h2>

		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state === 'failed'" type="error">
			No se pudo cargar «Para ti». Recarga la página; si vuelve a ocurrir, avisa al administrador.
		</NcNoteCard>
		<NcEmptyContent v-else-if="bulletins.length === 0"
			name="Todavía no recibes ningún boletín"
			description="Cuando un grupo editor te sume a los lectores de un boletín, aquí verás tus reuniones, los documentos para tu cargo y las últimas ediciones.">
			<template #icon>
				<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from './api.js'
import { mdiNewspaperVariantOutline } from './icons.js'

/** «Para ti», everyone's start page: the Personal lists of the Bulletins the person reads, merged. */
export default {
	name: 'ParaTi',
	components: { NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard },
	setup: () => ({ mdiNewspaperVariantOutline }),
	data: () => ({ state: 'loading', bulletins: [] }),
	async mounted() {
		try {
			this.bulletins = (await ocsGet('para-ti')).bulletins
			this.state = 'ready'
		} catch (error) {
			console.error('ia: «Para ti» did not load', error)
			this.state = 'failed'
		}
	},
}
</script>

