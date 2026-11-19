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
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ParaTi from './ParaTi.vue'
import ReviewList from './ReviewList.vue'
import ReviewReport from './ReviewReport.vue'
import { ocsPost, refusal } from './api.js'
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
	components: { NcAppContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationNew, NcContent, NcIconSvgWrapper, ParaTi, ReviewList, ReviewReport },
	setup: () => ({ mdiFileDocumentCheckOutline, mdiPlus, mdiStarOutline }),
	data: () => ({ view: viewOf(window.location.hash) }),
	mounted() {
		window.addEventListener('hashchange', this.follow)
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
				const { review } = await ocsPost('reviews', { fileId })
				window.location.hash = `#revisiones/${review.id}`
				showSuccess('Revisión pedida. Te avisaremos cuando esté lista.')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo pedir la revisión. Inténtalo de nuevo.')
			}
		},
	},
}
</script>
