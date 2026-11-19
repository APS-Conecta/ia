<template>
	<div class="ia-page">
		<h2>{{ review?.name ?? 'Revisión' }}</h2>

		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state === 'gone'" type="warning">
			Esta revisión ya no está: puede que ya no puedas abrir el archivo. <a href="#revisiones">Volver a «Revisiones»</a>
		</NcNoteCard>
		<NcNoteCard v-else-if="state === 'failed'" type="error">
			No se pudo cargar la revisión. Recarga la página; si vuelve a ocurrir, avisa al administrador.
		</NcNoteCard>
		<template v-else>
			<p class="ia-lead">
				Revisión pedida por ti · {{ when(review.requestedAt) }}
			</p>
			<div class="ia-chips">
				<NcButton :href="fileLink" variant="secondary">
					<template #icon>
						<NcIconSvgWrapper :path="mdiOpenInNew" />
					</template>
					Abrir el documento
				</NcButton>
				<NcButton v-if="!underWay(review)" variant="tertiary" @click="again">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					Revisar de nuevo
				</NcButton>
			</div>

			<div v-if="underWay(review)" class="ia-chips" role="status">
				<NcLoadingIcon :size="20" />
				<span>{{ review.state === 'waiting' ? 'En la fila del modelo.' : progress }}</span>
			</div>
			<NcNoteCard v-else-if="review.state === 'failed'" type="error" heading="La revisión falló">
				<p>El modelo no pudo terminarla. Pide «Revisar de nuevo» más tarde; si vuelve a fallar, avisa al administrador.</p>
				<p class="ia-hint">
					Detalle: {{ review.error }}
				</p>
			</NcNoteCard>
			<template v-else>
				<div class="ia-chips">
					<NcChip text="Escrito por IA" variant="primary" :icon-path="mdiCreation" no-close />
					<NcChip v-for="(count, kind) in counts"
						:key="kind"
						:text="`${count} ${count === 1 ? KINDS[kind].label.toLowerCase() : KINDS[kind].many}`"
						:variant="KINDS[kind].variant"
						no-close />
				</div>
				<NcNoteCard type="info">
					Solo tú ves esta revisión, mientras puedas abrir el archivo.
				</NcNoteCard>
				<NcEmptyContent v-if="review.findings.length === 0"
					name="La revisión no encontró qué corregir"
					description="No vio errores, faltas ni partes poco claras.">
					<template #icon>
						<NcIconSvgWrapper :path="mdiCheckCircleOutline" />
					</template>
				</NcEmptyContent>
				<section v-else>
					<div v-for="(finding, index) in review.findings" :key="index" class="ia-finding">
						<div class="ia-chips">
							<NcChip :text="KINDS[finding.kind].label" :variant="KINDS[finding.kind].variant" no-close />
							<span class="ia-hint">{{ finding.where }}</span>
						</div>
						<blockquote v-if="finding.quote">
							«{{ finding.quote }}»
						</blockquote>
						<p>{{ finding.text }}</p>
						<div class="ia-suggestion">
							<NcIconSvgWrapper :path="mdiCheckCircleOutline" :size="18" />
							<div><strong>Sugerencia:</strong> {{ finding.fix }}</div>
						</div>
					</div>
				</section>
				<p class="ia-hint">
					El documento no se modificó. Aplica los cambios tú en el editor.
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsPost, refusal } from './api.js'
import { mdiCheckCircleOutline, mdiCreation, mdiOpenInNew, mdiRefresh } from './icons.js'
import { KINDS, underWay, when } from './reviews.js'

/** Seconds between two looks at a Review still under way. */
const POLL = 5

/** One Review's report: its findings, each with where it is and a fix, marked as written by AI. */
export default {
	name: 'ReviewReport',
	components: { NcButton, NcChip, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard },
	props: {
		id: { type: Number, required: true },
	},
	setup: () => ({ KINDS, mdiCheckCircleOutline, mdiCreation, mdiOpenInNew, mdiRefresh, underWay, when }),
	data: () => ({ state: 'loading', review: null, timer: null }),
	computed: {
		fileLink() {
			return generateUrl(`/f/${this.review.fileId}`)
		},
		progress() {
			return this.review.steps > 1
				? `Leyendo el documento y escribiendo la revisión: paso ${this.review.step} de ${this.review.steps}…`
				: 'Leyendo el documento y escribiendo la revisión…'
		},
		counts() {
			const counts = {}
			for (const finding of this.review.findings ?? []) {
				counts[finding.kind] = (counts[finding.kind] ?? 0) + 1
			}
			return counts
		},
	},
	mounted() {
		this.load()
	},
	unmounted() {
		clearTimeout(this.timer)
	},
	methods: {
		async load() {
			try {
				this.review = (await ocsGet(`reviews/${this.id}`)).review
				this.state = 'ready'
			} catch (error) {
				this.state = error?.response?.status === 404 ? 'gone' : 'failed'
				if (this.state === 'failed') {
					console.error('ia: the Review did not load', error)
				}
				return
			}
			if (underWay(this.review)) {
				this.timer = setTimeout(this.load, POLL * 1000)
			}
		},
		async again() {
			try {
				this.review = (await ocsPost(`reviews/${this.id}/again`)).review
				this.load()
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo pedir la revisión. Inténtalo de nuevo.')
			}
		},
	},
}
</script>
