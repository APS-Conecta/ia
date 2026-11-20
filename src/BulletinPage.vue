<template>
	<div class="ia-page">
		<h2>{{ bulletin?.name ?? 'Boletín' }}</h2>
		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state !== 'ready'" type="warning">
			{{ state === 'gone' ? 'Este boletín ya no está.' : 'No se pudo cargar el boletín. Recarga la página.' }}
			<a href="#boletines">Volver a «Boletines»</a>
		</NcNoteCard>
		<template v-else>
			<p class="ia-lead">
				{{ scheduleText(bulletin.schedule) }} · de {{ bulletin.editorGroup.name }}
			</p>
			<div v-if="bulletin.role === 'editor'" class="ia-chips">
				<NcButton variant="secondary" @click="$emit('edit', bulletin.id)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPencilOutline" />
					</template>
					Editar
				</NcButton>
			</div>
			<NcEmptyContent name="Todavía no hay ediciones" :description="dueText(bulletin)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
				</template>
			</NcEmptyContent>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from './api.js'
import { dueText, scheduleText } from './bulletins.js'
import { mdiNewspaperVariantOutline, mdiPencilOutline } from './icons.js'

/** One Bulletin's page: until its first Edition, when that is due; «Editar» for its Editors. */
export default {
	name: 'BulletinPage',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard },
	props: {
		id: { type: Number, required: true },
		/** Bumped by the app after the sidebar saves, so the page shows the saved Bulletin. */
		version: { type: Number, default: 0 },
	},
	emits: ['edit'],
	setup: () => ({ dueText, mdiNewspaperVariantOutline, mdiPencilOutline, scheduleText }),
	data: () => ({ state: 'loading', bulletin: null }),
	watch: {
		version() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		async load() {
			try {
				this.bulletin = (await ocsGet(`bulletins/${this.id}`)).bulletin
				this.state = 'ready'
			} catch (error) {
				this.state = [403, 404].includes(error?.response?.status) ? 'gone' : 'failed'
			}
		},
	},
}
</script>
