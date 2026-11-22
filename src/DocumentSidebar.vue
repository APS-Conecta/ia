<template>
	<NcAppSidebar v-model:active="tab"
		:name="document?.name ?? ''"
		:subname="document === null ? '' : `${document.folder} · modificado el ${when(document.modifiedAt)}`"
		:loading="document === null && !failure"
		@close="$emit('close')">
		<template #description>
			<NcNoteCard v-if="failure" type="error">
				{{ failure }}
			</NcNoteCard>
			<div v-else-if="document" class="ia-chips">
				<NcButton :href="fileLink" variant="secondary">
					<template #icon>
						<NcIconSvgWrapper :path="mdiOpenInNew" />
					</template>
					Abrir en Archivos
				</NcButton>
			</div>
		</template>
		<NcAppSidebarTab v-if="document" id="lectura" name="Lectura" :order="1">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCreation" />
			</template>
			<p class="ia-hint">
				Lo que «IA» sacó una vez de la versión actual. Se reutiliza hasta que el documento cambie.
			</p>
			<ReadingSummary :document="document" />
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="document" id="revision" name="Revisión" :order="2">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileDocumentCheckOutline" />
			</template>
			<template v-if="document.latestReview">
				<p class="ia-hint">
					{{ askedText(document.latestReview) }}
				</p>
				<p v-if="document.latestReview.findings.length === 0">
					La revisión no encontró qué corregir.
				</p>
				<div v-for="(finding, index) in document.latestReview.findings" :key="index" class="ia-finding">
					<div class="ia-chips">
						<NcChip :text="KINDS[finding.kind].label" :variant="KINDS[finding.kind].variant" no-close />
						<span class="ia-hint">{{ finding.where }}</span>
					</div>
					<p>{{ finding.text }}</p>
				</div>
				<NcButton :href="`#revisiones/${document.latestReview.id}`" variant="secondary">
					Ver la revisión completa
				</NcButton>
			</template>
			<template v-else>
				<NcEmptyContent name="Sin revisión" description="Nadie ha revisado todavía esta versión, o su revisión sigue en curso.">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentCheckOutline" />
					</template>
				</NcEmptyContent>
				<NcButton variant="primary" @click="$emit('review', document.fileId)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCreation" />
					</template>
					Revisar ahora
				</NcButton>
			</template>
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="document" id="aparece" name="Aparece en" :order="3">
			<template #icon>
				<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
			</template>
			<ul v-if="document.appearsIn.length > 0">
				<NcListItem v-for="{ bulletin, edition } in document.appearsIn"
					:key="edition.id"
					:name="bulletin.name"
					:details="longDate(edition.date)"
					:href="`#boletines/${bulletin.id}/${edition.id}`"
					one-line>
					<template #icon>
						<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
					</template>
				</NcListItem>
			</ul>
			<p v-else class="ia-hint">
				No aparece en ningún boletín que puedas ver.
			</p>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ReadingSummary from './ReadingSummary.vue'
import { ocsGet, refusal } from './api.js'
import { longDate } from './bulletins.js'
import { mdiCreation, mdiFileDocumentCheckOutline, mdiNewspaperVariantOutline, mdiOpenInNew } from './icons.js'
import { askedText, KINDS, when } from './reviews.js'

/** One watched document, for Editors: its Reading, its latest Review and the Bulletins it appears in. */
export default {
	name: 'DocumentSidebar',
	components: { NcAppSidebar, NcAppSidebarTab, NcButton, NcChip, NcEmptyContent, NcIconSvgWrapper, NcListItem, NcNoteCard, ReadingSummary },
	props: {
		/** The document's file id. */
		id: { type: Number, required: true },
	},
	emits: ['close', 'review'],
	setup: () => ({ KINDS, askedText, longDate, mdiCreation, mdiFileDocumentCheckOutline, mdiNewspaperVariantOutline, mdiOpenInNew, when }),
	data: () => ({ tab: 'lectura', document: null, failure: null }),
	computed: {
		fileLink() {
			return generateUrl(`/f/${this.id}`)
		},
	},
	async mounted() {
		try {
			this.document = (await ocsGet(`documents/${this.id}`)).document
		} catch (error) {
			console.error('ia: a document did not load', error)
			this.failure = refusal(error) ?? 'No se pudo cargar el documento. Recarga la página.'
		}
	},
}
</script>
