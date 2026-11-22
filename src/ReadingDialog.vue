<template>
	<NcDialog :name="document.name" :buttons="[{ label: 'Cerrar' }]" @closing="$emit('close')">
		<ReadingSummary :document="document" />
		<div class="ia-chips">
			<NcButton :href="fileLink" variant="secondary">
				<template #icon>
					<NcIconSvgWrapper :path="mdiOpenInNew" />
				</template>
				Abrir el documento
			</NcButton>
		</div>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ReadingSummary from './ReadingSummary.vue'
import { mdiOpenInNew } from './icons.js'

/** A document's Reading, marked as written by AI, and the way to the file. */
export default {
	name: 'ReadingDialog',
	components: { NcButton, NcDialog, NcIconSvgWrapper, ReadingSummary },
	props: {
		/** An Edition's document, with its Reading. */
		document: { type: Object, required: true },
	},
	emits: ['close'],
	setup: () => ({ mdiOpenInNew }),
	computed: {
		fileLink() {
			return generateUrl(`/f/${this.document.fileId}`)
		},
	},
}
</script>
