<template>
	<section>
		<NcNoteCard type="info">
			Estas listas vienen de tu calendario y de tu cargo, no del modelo.
		</NcNoteCard>
		<h3>{{ ahead ? 'Tus próximas reuniones' : 'Tus reuniones' }}</h3>
		<p v-if="meetings.length === 0" class="ia-hint">
			{{ ahead ? 'No tienes reuniones próximas en tus calendarios.' : 'No tienes reuniones en tus calendarios para este período.' }}
		</p>
		<ul v-else>
			<NcListItem v-for="meeting in meetings"
				:key="meeting.id"
				:name="meeting.title || 'Reunión sin título'"
				:details="meeting.calendar">
				<template #icon>
					<NcIconSvgWrapper :path="mdiCalendarOutline" />
				</template>
				<template #subname>
					{{ meetingText(meeting) }}
				</template>
			</NcListItem>
		</ul>
		<h3>{{ ahead ? 'Documentos nuevos o cambiados para tu cargo' : 'Documentos para tu cargo' }}</h3>
		<p v-if="documents.length === 0" class="ia-hint">
			{{ ahead ? 'Ningún documento nuevo o cambiado nombra tu cargo.' : 'Ningún documento nombra tu cargo.' }}
		</p>
		<ul v-else>
			<NcListItem v-for="document in documents"
				:key="document.fileId"
				:name="document.name"
				href="#"
				@click="open($event, document)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
				</template>
			</NcListItem>
		</ul>
	</section>
</template>

<script>
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { meetingText } from './bulletins.js'
import { mdiCalendarOutline, mdiFileDocumentOutline } from './icons.js'

/**
 * A person's Personal list: their meetings and the documents for their Cargo, from data, not the
 * Model; a document's Reading, written by AI, opens in its dialog.
 */
export default {
	name: 'PersonalList',
	components: { NcIconSvgWrapper, NcListItem, NcNoteCard },
	props: {
		meetings: { type: Array, required: true },
		documents: { type: Array, required: true },
		/** «Para ti»'s view: only what is ahead, the meetings not yet ended and the new or changed documents. */
		ahead: { type: Boolean, default: false },
	},
	emits: ['open'],
	setup: () => ({ mdiCalendarOutline, mdiFileDocumentOutline, meetingText }),
	methods: {
		open(event, document) {
			event.preventDefault()
			this.$emit('open', document)
		},
	},
}
</script>
