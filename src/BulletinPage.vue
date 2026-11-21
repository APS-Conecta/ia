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
			<div class="ia-chips">
				<NcButton v-if="bulletin.role === 'editor'" variant="secondary" @click="$emit('edit', bulletin.id)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPencilOutline" />
					</template>
					Editar
				</NcButton>
				<NcSelect v-if="editions.length > 1"
					:model-value="shown"
					input-label="Edición"
					:options="editions"
					:get-option-label="(option) => longDate(option.date) + (option.late ? ' (atrasada)' : '') + (option.withdrawn ? ' (retirada)' : '')"
					:clearable="false"
					@update:model-value="show" />
			</div>
			<NcNoteCard v-if="bulletin.writing" type="info">
				<template v-if="bulletin.writing.steps === 0">
					La edición del {{ longDate(bulletin.writing.date) }}{{ bulletin.writing.late ? ', atrasada,' : '' }} espera su turno fuera de horario.
				</template>
				<template v-else>
					Se está escribiendo la edición del {{ longDate(bulletin.writing.date) }}{{ bulletin.writing.late ? ', atrasada' : '' }}: paso {{ bulletin.writing.step }} de {{ bulletin.writing.steps }}.
					Sigue fuera de horario.
				</template>
			</NcNoteCard>
			<NcEmptyContent v-if="!shown" name="Todavía no hay ediciones" :description="dueText(bulletin)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
				</template>
			</NcEmptyContent>
			<EditionView v-else
				:id="shown.id"
				:key="shown.id"
				:editor="bulletin.role === 'editor'"
				@changed="load" />
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import EditionView from './EditionView.vue'
import { ocsGet } from './api.js'
import { dueText, longDate, scheduleText } from './bulletins.js'
import { mdiNewspaperVariantOutline, mdiPencilOutline } from './icons.js'

/**
 * One Bulletin's page: its Edition, the latest or the one in the URL, with the others to pick from;
 * until the first, when it is due. «Editar» for its Editors.
 */
export default {
	name: 'BulletinPage',
	components: { EditionView, NcButton, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard, NcSelect },
	props: {
		id: { type: Number, required: true },
		/** The Edition the URL names, or null for the latest. */
		edition: { type: Number, default: null },
		/** Bumped by the app after the sidebar saves, so the page shows the saved Bulletin. */
		version: { type: Number, default: 0 },
	},
	emits: ['edit'],
	setup: () => ({ dueText, longDate, mdiNewspaperVariantOutline, mdiPencilOutline, scheduleText }),
	data: () => ({ state: 'loading', bulletin: null, editions: [] }),
	computed: {
		/** The Edition the URL names, even one no longer listed (a link to a withdrawn one says so), or the latest. */
		shown() {
			if (this.edition === null) {
				return this.editions[0]
			}
			return this.editions.find((edition) => edition.id === this.edition) ?? { id: this.edition }
		},
	},
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
				const [{ bulletin }, { editions }] = await Promise.all([ocsGet(`bulletins/${this.id}`), ocsGet(`bulletins/${this.id}/editions`)])
				this.bulletin = bulletin
				this.editions = editions
				this.state = 'ready'
			} catch (error) {
				this.state = [403, 404].includes(error?.response?.status) ? 'gone' : 'failed'
			}
		},
		show(edition) {
			window.location.hash = `#boletines/${this.id}/${edition.id}`
		},
	},
}
</script>
