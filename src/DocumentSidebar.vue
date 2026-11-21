<template>
	<NcAppSidebar v-model:active="tab"
		:name="document?.name ?? ''"
		:subname="document === null ? '' : `${document.folder} · modificado el ${when(document.modifiedAt)}`"
		:loading="document === null && !failed"
		@close="$emit('close')">
		<template #description>
			<NcNoteCard v-if="failed" type="error">
				No encontramos este documento entre los que vigilan tus grupos editores, o ya no puedes abrirlo.
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
			<template v-if="document.status === 'read'">
				<div class="ia-chips">
					<NcChip text="Escrito por IA" variant="primary" :icon-path="mdiCreation" no-close />
					<span class="ia-hint">Leído el {{ when(document.readAt) }}</span>
				</div>
				<h3>Resumen</h3>
				<p>{{ document.summary }}</p>
				<h3>Vigencia</h3>
				<p>{{ document.expires === null ? 'No encontró fecha de vigencia.' : `Vence el ${fullDate(document.expires)}.` }}</p>
				<h3>Le concierne a</h3>
				<div v-if="document.cargos.length > 0" class="ia-chips">
					<NcChip v-for="cargo in document.cargos" :key="cargo" :text="cargo" no-close />
				</div>
				<p v-else>
					Ningún cargo de la lista.
				</p>
			</template>
			<NcNoteCard v-else :type="document.status === 'unread' ? 'info' : 'warning'">
				{{ NO_SUMMARY[document.status] }}
			</NcNoteCard>
		</NcAppSidebarTab>
		<NcAppSidebarTab v-if="document" id="revision" name="Revisión" :order="2">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileDocumentCheckOutline" />
			</template>
			<template v-if="document.review">
				<p>{{ findingsText(document.review.findings) }} en su última revisión.</p>
				<NcButton :href="`#revisiones/${document.review.id}`" variant="secondary">
					Ver la revisión completa
				</NcButton>
			</template>
			<template v-else>
				<NcEmptyContent name="Sin revisión" description="Nadie ha revisado todavía este documento, o su revisión sigue en curso.">
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
import { ocsGet } from './api.js'
import { longDate } from './bulletins.js'
import { findingsText, fullDate, NO_SUMMARY } from './documents.js'
import { mdiCreation, mdiFileDocumentCheckOutline, mdiNewspaperVariantOutline, mdiOpenInNew } from './icons.js'
import { when } from './reviews.js'

/** One watched document, for Editors: its Reading, its latest Review and the Bulletins it appears in. */
export default {
	name: 'DocumentSidebar',
	components: { NcAppSidebar, NcAppSidebarTab, NcButton, NcChip, NcEmptyContent, NcIconSvgWrapper, NcListItem, NcNoteCard },
	props: {
		/** The document's file id. */
		id: { type: Number, required: true },
	},
	emits: ['close', 'review'],
	setup: () => ({ NO_SUMMARY, findingsText, fullDate, longDate, mdiCreation, mdiFileDocumentCheckOutline, mdiNewspaperVariantOutline, mdiOpenInNew, when }),
	data: () => ({ tab: 'lectura', document: null, failed: false }),
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
			this.failed = true
		}
	},
}
</script>
