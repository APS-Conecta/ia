<template>
	<NcContent app-name="ia">
		<!-- The navigation lists only what works; each later section adds its entry here. -->
		<NcAppNavigation aria-label="IA">
			<template #list>
				<NcAppNavigationNew text="Revisar un documento" @click="pick">
					<template #icon>
						<NcIconSvgWrapper :path="mdiPlus" />
					</template>
				</NcAppNavigationNew>
				<NcAppNavigationItem name="Para ti" href="#" :active="view.name === 'para-ti'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiStarOutline" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Boletines"
					href="#boletines"
					:active="view.name === 'boletines'"
					:allow-collapse="bulletins.length > 0"
					:open="true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiNewspaperVariantOutline" />
					</template>
					<NcAppNavigationItem v-for="bulletin in bulletins"
						:key="bulletin.id"
						:name="bulletin.name"
						:href="`#boletines/${bulletin.id}`"
						:active="view.name === 'boletin' && view.id === bulletin.id" />
				</NcAppNavigationItem>
				<NcAppNavigationItem name="Revisiones" href="#revisiones" :active="view.name === 'revisiones' || view.name === 'revision'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentCheckOutline" />
					</template>
				</NcAppNavigationItem>
				<!-- Only Editors see this part; Documentos and Acciones automáticas join it (#9, #15). -->
				<NcAppNavigationCaption v-if="editorGroups.length > 0" name="Para editores" />
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="HEADINGS[view.name]">
			<ParaTi v-if="view.name === 'para-ti'" />
			<BulletinList v-else-if="view.name === 'boletines'"
				:bulletins="bulletins"
				:editor="editorGroups.length > 0"
				@create="sidebar = { id: null }" />
			<BulletinPage v-else-if="view.name === 'boletin'"
				:id="view.id"
				:key="view.id"
				:edition="view.edition"
				:version="version"
				@edit="sidebar = { id: $event }" />
			<ReviewList v-else-if="view.name === 'revisiones'" />
			<ReviewReport v-else :id="view.id" :key="view.id" />
		</NcAppContent>
		<BulletinSidebar v-if="sidebar"
			:id="sidebar.id"
			:key="sidebar.id ?? 'new'"
			:editor-groups="editorGroups"
			@close="sidebar = null"
			@saved="saved"
			@deleted="deleted" />
	</NcContent>
</template>

<script>
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import BulletinList from './BulletinList.vue'
import BulletinPage from './BulletinPage.vue'
import BulletinSidebar from './BulletinSidebar.vue'
import ParaTi from './ParaTi.vue'
import ReviewList from './ReviewList.vue'
import ReviewReport from './ReviewReport.vue'
import { ocsGet, ocsPost, refusal } from './api.js'
import { mdiFileDocumentCheckOutline, mdiNewspaperVariantOutline, mdiPlus, mdiStarOutline } from './icons.js'
import { offHoursText } from './reviews.js'

/** What «IA» reads (TextReader): PDF with text, DOCX, ODT, TXT and MD. */
const FORMATS = [
	'application/pdf',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.oasis.opendocument.text',
	'text/plain',
	'text/markdown',
]

/** Each place's page heading, for screen readers. */
const HEADINGS = { 'para-ti': 'Para ti', boletines: 'Boletines', boletin: 'Boletines', revisiones: 'Revisiones', revision: 'Revisiones' }

/** The page's place, from the URL's hash: a section, one Bulletin or one Review in it, or one of a Bulletin's Editions. */
function viewOf(hash) {
	const [section, id, edition] = hash.replace(/^#\/?/, '').split('/')
	const one = { boletines: 'boletin', revisiones: 'revision' }[section]
	if (one === undefined) {
		return { name: 'para-ti' }
	}
	if (!/^\d+$/.test(id ?? '')) {
		return { name: section }
	}
	return { name: one, id: Number(id), edition: one === 'boletin' && /^\d+$/.test(edition ?? '') ? Number(edition) : null }
}

export default {
	name: 'App',
	components: { BulletinList, BulletinPage, BulletinSidebar, NcAppContent, NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcAppNavigationNew, NcContent, NcIconSvgWrapper, ParaTi, ReviewList, ReviewReport },
	setup: () => ({ HEADINGS, mdiFileDocumentCheckOutline, mdiNewspaperVariantOutline, mdiPlus, mdiStarOutline }),
	data: () => ({ view: viewOf(window.location.hash), editorGroups: [], bulletins: [], sidebar: null, version: 0 }),
	async mounted() {
		window.addEventListener('hashchange', this.follow)
		try {
			this.editorGroups = (await ocsGet('me')).editorGroups
		} catch (error) {
			console.error('ia: who the caller is did not load', error)
		}
		this.loadBulletins()
	},
	unmounted() {
		window.removeEventListener('hashchange', this.follow)
	},
	methods: {
		follow() {
			this.view = viewOf(window.location.hash)
		},
		async loadBulletins() {
			try {
				this.bulletins = (await ocsGet('bulletins')).bulletins
			} catch (error) {
				console.error('ia: «Boletines» did not load', error)
			}
		},
		async saved(id) {
			this.sidebar = null
			this.version++
			await this.loadBulletins()
			window.location.hash = `#boletines/${id}`
		},
		async deleted() {
			this.sidebar = null
			await this.loadBulletins()
			window.location.hash = '#boletines'
		},
		async pick() {
			const picker = getFilePickerBuilder('Revisar un documento')
				.setMultiSelect(false)
				.setMimeTypeFilter(FORMATS)
				.addButton({ label: 'Revisar', variant: 'primary', callback: ([node]) => this.review(node.fileid) })
				.build()
			try {
				await picker.pick()
			} catch {
				// Closed without a choice.
			}
		},
		async review(fileId) {
			try {
				const { review, start } = await ocsPost('reviews', { fileId })
				window.location.hash = `#revisiones/${review.id}`
				showSuccess(start === 'offHours'
					? `Es un documento largo: se revisará fuera de horario, ${offHoursText(review)}. Te avisaremos cuando esté lista.`
					: 'Revisión pedida. Te avisaremos cuando esté lista.')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo pedir la revisión. Inténtalo de nuevo.')
			}
		},
	},
}
</script>

<style>
/* «IA»'s page parts, shared by its views, unscoped and prefixed «ia-»: Nextcloud's variables only.
   NcAppNavigation's toggle sits in the content's top corner, open or folded: the title starts
   beside it, never under it (Estadística's heading). */
.ia-page h2 {
	display: flex;
	align-items: center;
	min-height: var(--default-clickable-area);
	margin: var(--app-navigation-padding) 0 0;
	padding-inline: calc(var(--default-clickable-area) + var(--default-grid-baseline) * 3) 24px;
}

.ia-page > :not(h2) {
	margin-inline: calc(var(--default-grid-baseline) * 6);
}

.ia-page h3 {
	margin-block: calc(var(--default-grid-baseline) * 6) calc(var(--default-grid-baseline) * 2);
}

.ia-lead,
.ia-hint {
	color: var(--color-text-maxcontrast);
}

.ia-chips {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
}

.ia-finding {
	padding-block: calc(var(--default-grid-baseline) * 4);
	border-bottom: 1px solid var(--color-border);
}

.ia-finding blockquote {
	margin: calc(var(--default-grid-baseline) * 2) 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 3);
	border-inline-start: 3px solid var(--color-border-maxcontrast);
}

.ia-suggestion {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-start;
}
</style>
