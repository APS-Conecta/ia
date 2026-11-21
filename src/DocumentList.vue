<template>
	<div class="ia-page">
		<h2>Documentos</h2>
		<p class="ia-lead">
			De las fuentes de tus boletines y de las carpetas con acciones automáticas: solo los que puedes abrir.
		</p>
		<div class="ia-chips" role="group" aria-label="Filtrar documentos">
			<NcButton v-for="[key, label] in FILTERS"
				:key="key"
				:variant="filter === key ? 'primary' : 'secondary'"
				:aria-pressed="filter === key"
				@click="show(key)">
				{{ label }} · {{ counts[key] ?? '…' }}
			</NcButton>
		</div>
		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state === 'failed'" type="error">
			{{ failure }}
		</NcNoteCard>
		<NcEmptyContent v-else-if="documents.length === 0"
			name="Ningún documento"
			:description="filter === 'all' ? 'Tus boletines y acciones automáticas todavía no vigilan documentos que puedas abrir.' : 'Ningún documento cumple este filtro.'">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFileDocumentOutline" />
			</template>
		</NcEmptyContent>
		<div v-else class="ia-documents">
			<table>
				<thead>
					<tr>
						<th>Nombre</th>
						<th>Lectura</th>
						<th>Vigencia</th>
						<th>Le concierne a</th>
						<th>Revisión</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="document in documents"
						:key="document.fileId"
						:class="{ selected: document.fileId === selected }"
						tabindex="0"
						@click="$emit('open', document.fileId)"
						@keydown.enter="$emit('open', document.fileId)">
						<td>
							<strong>{{ document.name }}</strong>
							<div class="ia-hint">
								{{ document.folder }}
							</div>
						</td>
						<td>{{ READING[document.status] }}</td>
						<td>
							<NcChip v-if="document.status === 'read' && !document.expires" text="Sin fecha" variant="error" no-close />
							<span v-else>{{ document.expires ? fullDate(document.expires) : '—' }}</span>
						</td>
						<td>
							<div class="ia-chips">
								<NcChip v-for="cargo in document.cargos" :key="cargo" :text="cargo" no-close />
							</div>
						</td>
						<td>
							<NcChip v-if="document.review"
								:text="findingsText(document.review.findings)"
								:variant="document.review.findings > 0 ? 'warning' : 'success'"
								no-close />
							<span v-else>—</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<p class="ia-hint">
			La lectura de cada documento (resumen, vigencia y cargos) se hace una vez por versión, fuera de horario, y la usan todos los boletines.
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, refusal } from './api.js'
import { findingsText, fullDate, READING } from './documents.js'
import { mdiFileDocumentOutline } from './icons.js'

const FILTERS = [['all', 'Todos'], ['changed', 'Nuevos y cambiados'], ['expiring', 'Por vencer'], ['findings', 'Con hallazgos'], ['cargo', 'Para mi cargo']]

/** «Documentos», for Editors: the documents their groups watch, filtered; each opens in the sidebar. */
export default {
	name: 'DocumentList',
	components: { NcButton, NcChip, NcEmptyContent, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard },
	props: {
		/** The document open in the sidebar, or null. */
		selected: { type: Number, default: null },
	},
	emits: ['open'],
	setup: () => ({ FILTERS, READING, findingsText, fullDate, mdiFileDocumentOutline }),
	data: () => ({ state: 'loading', filter: 'all', documents: [], counts: {}, failure: '' }),
	mounted() {
		this.load()
	},
	methods: {
		show(filter) {
			this.filter = filter
			this.load()
		},
		async load() {
			try {
				const answer = await ocsGet(`documents?filter=${this.filter}`)
				this.documents = answer.documents
				this.counts = answer.counts
				this.state = 'ready'
			} catch (error) {
				console.error('ia: «Documentos» did not load', error)
				this.failure = refusal(error) ?? 'No se pudieron cargar los documentos. Recarga la página.'
				this.state = 'failed'
			}
		},
	},
}
</script>

<style scoped>
/* The table scrolls inside its box on a narrow screen, never the page. */
.ia-documents {
	overflow-x: auto;
}

.ia-documents table {
	width: 100%;
	border-collapse: collapse;
}

.ia-documents th,
.ia-documents td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.ia-documents tbody tr {
	cursor: pointer;
}

.ia-documents tbody tr:hover,
.ia-documents tbody tr:focus-visible,
.ia-documents tbody tr.selected {
	background-color: var(--color-background-hover);
}
</style>
