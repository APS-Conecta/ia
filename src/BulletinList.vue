<template>
	<div class="ia-page">
		<h2>Boletines</h2>
		<p class="ia-lead">
			Un boletín resume, para sus lectores, lo que cambió en las fuentes que elige su grupo editor.
		</p>
		<div v-if="editor" class="ia-chips">
			<NcButton variant="primary" @click="$emit('create')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				Nuevo boletín
			</NcButton>
		</div>
		<NcEmptyContent v-if="bulletins.length === 0"
			name="Todavía no hay boletines para ti"
			:description="editor ? 'Crea uno para tu grupo editor con «Nuevo boletín».' : 'Cuando un grupo editor te sume a los lectores de un boletín, aparecerá aquí.'">
			<template #icon>
				<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
			</template>
		</NcEmptyContent>
		<ul v-else>
			<NcListItem v-for="bulletin in bulletins"
				:key="bulletin.id"
				:name="bulletin.name"
				:href="`#boletines/${bulletin.id}`"
				:details="bulletin.role === 'editor' ? 'Lo editas' : 'Lo lees'"
				@click="open($event, bulletin.id)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
				</template>
			</NcListItem>
		</ul>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import { mdiNewspaperVariantOutline, mdiPlus } from './icons.js'

/** «Boletines»: the Bulletins the person edits or reads, and «Nuevo boletín» for Editors. */
export default {
	name: 'BulletinList',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, NcListItem },
	props: {
		bulletins: { type: Array, required: true },
		editor: { type: Boolean, required: true },
	},
	emits: ['create'],
	setup: () => ({ mdiNewspaperVariantOutline, mdiPlus }),
	methods: {
		/** NcListItem opens a link in a new tab: a plain click opens the Bulletin here instead. */
		open(event, id) {
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return
			}
			event.preventDefault()
			window.location.hash = `#boletines/${id}`
		},
	},
}
</script>
