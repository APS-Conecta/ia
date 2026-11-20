<template>
	<NcDialog :name="document.name" :buttons="[{ label: 'Cerrar' }]" @closing="$emit('close')">
		<template v-if="document.status === 'read'">
			<div v-if="document.summary !== null" class="ia-chips">
				<NcChip text="Escrito por IA" variant="primary" :icon-path="mdiCreation" no-close />
				<span class="ia-hint">Leído el {{ when(document.readAt) }}</span>
			</div>
			<p>{{ document.summary ?? GONE }}</p>
			<p>{{ document.expires === null ? 'No encontramos su fecha de vencimiento.' : `Vence el ${longDate(document.expires)}.` }}</p>
			<p v-if="document.cargos.length > 0">
				Para: {{ document.cargos.join(', ') }}.
			</p>
		</template>
		<p v-else>
			{{ UNREADABLE[document.status] }}
		</p>
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
import NcChip from '@nextcloud/vue/components/NcChip'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { GONE, longDate, UNREADABLE } from './bulletins.js'
import { mdiCreation, mdiOpenInNew } from './icons.js'
import { when } from './reviews.js'

/** A document's Reading, marked as written by AI, and the way to the file. */
export default {
	name: 'ReadingDialog',
	components: { NcButton, NcChip, NcDialog, NcIconSvgWrapper },
	props: {
		/** An Edition's document, with its Reading. */
		document: { type: Object, required: true },
	},
	emits: ['close'],
	setup: () => ({ GONE, longDate, mdiCreation, mdiOpenInNew, UNREADABLE, when }),
	computed: {
		fileLink() {
			return generateUrl(`/f/${this.document.fileId}`)
		},
	},
}
</script>
