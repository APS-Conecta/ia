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
				<!-- Only Editors see this part. -->
				<template v-if="editorGroups.length > 0">
					<NcAppNavigationCaption name="Para editores" />
					<NcAppNavigationItem name="Documentos" href="#documentos" :active="view.name === 'documentos'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem name="Acciones automáticas" href="#acciones" :active="view.name === 'acciones'">
						<template #icon>
							<NcIconSvgWrapper :path="mdiRobotOutline" />
						</template>
					</NcAppNavigationItem>
				</template>
			</template>
			<template #footer>
				<!-- For admins and the group the admin page is delegated to. -->
				<ul v-if="admin">
					<NcAppNavigationItem name="Estado" :href="estadoUrl">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPulse" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="HEADINGS[view.name]">
			<ParaTi v-if="view.name === 'para-ti'" />
			<BulletinList v-else-if="view.name === 'boletines'"
				:bulletins="bulletins"
				:editor="editorGroups.length > 0"
				@create="sidebar = { kind: 'bulletin', id: null }" />
			<BulletinPage v-else-if="view.name === 'boletin'"
				:id="view.id"
				:key="view.id"
				:edition="view.edition"
				:version="version"
				@edit="sidebar = { kind: 'bulletin', id: $event }" />
			<ActionList v-else-if="view.name === 'acciones'"
				:version="version"
				@create="sidebar = { kind: 'action', id: null }"
				@edit="sidebar = { kind: 'action', id: $event }" />
			<DocumentList v-else-if="view.name === 'documentos'"
				:selected="sidebar?.kind === 'document' ? sidebar.id : null"
				@open="sidebar = { kind: 'document', id: $event }" />
			<ReviewList v-else-if="view.name === 'revisiones'" />
			<ReviewReport v-else :id="view.id" :key="view.id" />
		</NcAppContent>
		<BulletinSidebar v-if="sidebar?.kind === 'bulletin'"
			:id="sidebar.id"
			:key="sidebar.id ?? 'new'"
			:editor-groups="editorGroups"
			@close="sidebar = null"
			@saved="saved"
			@deleted="deleted" />
		<ActionSidebar v-else-if="sidebar?.kind === 'action'"
			:id="sidebar.id"
			:key="'action' + (sidebar.id ?? 'new')"
			:editor-groups="editorGroups"
			@close="sidebar = null"
			@saved="actionsChanged"
			@deleted="actionsChanged" />
		<DocumentSidebar v-else-if="sidebar?.kind === 'document'"
			:id="sidebar.id"
			:key="'document' + sidebar.id"
			@close="sidebar = null"
			@review="review" />
	</NcContent>
</template>

<script>
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import { FileType } from '@nextcloud/files'
import '@nextcloud/dialogs/style.css'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ActionList from './ActionList.vue'
import ActionSidebar from './ActionSidebar.vue'
import BulletinList from './BulletinList.vue'
import BulletinPage from './BulletinPage.vue'
import BulletinSidebar from './BulletinSidebar.vue'
import DocumentList from './DocumentList.vue'
import DocumentSidebar from './DocumentSidebar.vue'
import ParaTi from './ParaTi.vue'
import ReviewList from './ReviewList.vue'
import ReviewReport from './ReviewReport.vue'
import { ocsGet, ocsPost } from './api.js'
import { mdiFileDocumentCheckOutline, mdiFileDocumentOutline, mdiNewspaperVariantOutline, mdiPlus, mdiPulse, mdiRobotOutline, mdiStarOutline } from './icons.js'
import { reads, requestFailedText, requestedText } from './reviews.js'

/** Each place's page heading, for screen readers. */
const HEADINGS = { 'para-ti': 'Para ti', boletines: 'Boletines', boletin: 'Boletines', revisiones: 'Revisiones', revision: 'Revisiones', documentos: 'Documentos', acciones: 'Acciones automáticas' }

/** The page's place, from the URL's hash: a section, one Bulletin or one Review in it, or one of a Bulletin's Editions. */
function viewOf(hash) {
	const [section, id, edition] = hash.replace(/^#\/?/, '').split('/')
	if (section === 'acciones' || section === 'documentos') {
		return { name: section }
	}
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
	components: { ActionList, ActionSidebar, BulletinList, BulletinPage, BulletinSidebar, DocumentList, DocumentSidebar, NcAppContent, NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcAppNavigationNew, NcContent, NcIconSvgWrapper, ParaTi, ReviewList, ReviewReport },
	setup: () => ({ HEADINGS, mdiFileDocumentCheckOutline, mdiFileDocumentOutline, mdiNewspaperVariantOutline, mdiPlus, mdiPulse, mdiRobotOutline, mdiStarOutline, estadoUrl: generateUrl('/settings/admin/ia') }),
	data: () => ({ view: viewOf(window.location.hash), editorGroups: [], admin: false, bulletins: [], sidebar: null, version: 0 }),
	async mounted() {
		window.addEventListener('hashchange', this.follow)
		try {
			const me = await ocsGet('me')
			this.editorGroups = me.editorGroups
			this.admin = me.admin
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
			// A document's sidebar belongs to «Documentos»: its links lead elsewhere.
			if (this.sidebar?.kind === 'document') {
				this.sidebar = null
			}
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
		actionsChanged() {
			this.sidebar = null
			this.version++
		},
		async deleted() {
			this.sidebar = null
			await this.loadBulletins()
			window.location.hash = '#boletines'
		},
		async pick() {
			const picker = getFilePickerBuilder('Revisar un documento')
				.setMultiSelect(false)
				// Folders to find a file in, and the files «IA» reads.
				.setFilter((node) => node.type === FileType.Folder || reads(node))
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
				const requested = await ocsPost('reviews', { fileId })
				window.location.hash = `#revisiones/${requested.review.id}`
				showSuccess(requestedText(requested))
			} catch (error) {
				showError(requestFailedText(error))
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

.ia-form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
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
