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
				<NcAppNavigationItem name="Revisiones" href="#revisiones" :active="view.name !== 'para-ti'">
					<template #icon>
						<NcIconSvgWrapper :path="mdiFileDocumentCheckOutline" />
					</template>
				</NcAppNavigationItem>
				<!-- Only Editors see this part; Documentos and Acciones automáticas join it (#9, #15). -->
				<NcAppNavigationCaption v-if="editorGroups.length > 0" name="Para editores" />
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="view.name === 'para-ti' ? 'Para ti' : 'Revisiones'">
			<ParaTi v-if="view.name === 'para-ti'" />
			<ReviewList v-else-if="view.name === 'revisiones'" />
			<ReviewReport v-else :id="view.id" :key="view.id" />
		</NcAppContent>
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
import ParaTi from './ParaTi.vue'
import ReviewList from './ReviewList.vue'
import ReviewReport from './ReviewReport.vue'
import { ocsGet, ocsPost, refusal } from './api.js'
import { mdiFileDocumentCheckOutline, mdiPlus, mdiStarOutline } from './icons.js'

/** The page's place, from the URL's hash: «Para ti», «Revisiones» or one Review's report. */
function viewOf(hash) {
	const [section, id] = hash.replace(/^#\/?/, '').split('/')
	if (section !== 'revisiones') {
		return { name: 'para-ti' }
	}
	return /^\d+$/.test(id ?? '') ? { name: 'revision', id: Number(id) } : { name: 'revisiones' }
}

export default {
	name: 'App',
	components: { NcAppContent, NcAppNavigation, NcAppNavigationCaption, NcAppNavigationItem, NcAppNavigationNew, NcContent, NcIconSvgWrapper, ParaTi, ReviewList, ReviewReport },
	setup: () => ({ mdiFileDocumentCheckOutline, mdiPlus, mdiStarOutline }),
	data: () => ({ view: viewOf(window.location.hash), editorGroups: [] }),
	async mounted() {
		window.addEventListener('hashchange', this.follow)
		try {
			this.editorGroups = (await ocsGet('me')).editorGroups
		} catch (error) {
			console.error('ia: who the caller is did not load', error)
		}
	},
	unmounted() {
		window.removeEventListener('hashchange', this.follow)
	},
	methods: {
		follow() {
			this.view = viewOf(window.location.hash)
		},
		async pick() {
			const picker = getFilePickerBuilder('Revisar un documento')
				.setMultiSelect(false)
				.setMimeTypeFilter(['text/plain', 'text/markdown'])
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
					? 'Es un documento largo: se revisará fuera de horario. Te avisaremos cuando esté lista.'
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
