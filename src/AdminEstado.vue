<template>
	<div>
		<NcSettingsSection name="Modelo"
			:description="`«IA» pide todo a través de la IA de Nextcloud y su conector estándar (Administración › Inteligencia artificial). Si el modelo no responde durante ${ALERT_MINUTES} minutos seguidos, los administradores reciben un aviso y la página de información general muestra una advertencia. El respaldo nocturno no cuenta como caída.`">
			<NcLoadingIcon v-if="!estado" :size="32" />
			<template v-else>
				<NcNoteCard v-if="!estado.model.provider" type="error">
					Ningún proveedor responde core:text2text, así que «IA» no puede pedir nada al modelo. Revisa el conector.
				</NcNoteCard>
				<NcNoteCard v-else-if="estado.model.alerted" type="warning">
					El modelo no responde desde {{ when(estado.model.since) }}. «IA» retoma su trabajo cuando vuelva.
				</NcNoteCard>
				<p class="model">
					<strong>{{ MODEL[estado.model.state] }}</strong>
					<span class="muted">{{ modelDetail }}</span>
				</p>
			</template>
		</NcSettingsSection>

		<NcSettingsSection name="Trabajo pendiente"
			description="Lo que espera al modelo o está en curso. Los boletines y las acciones automáticas corren fuera de horario.">
			<dl v-if="estado" class="work">
				<dt>Revisiones pedidas a mano</dt>
				<dd>{{ estado.waiting.manual }}</dd>
				<dt>Revisiones automáticas</dt>
				<dd>{{ estado.waiting.action }}</dd>
				<dt>Revisiones de reglas de Flujo</dt>
				<dd>{{ estado.waiting.flow }}</dd>
				<dt>Ediciones de boletines</dt>
				<dd>{{ estado.waiting.edition }}</dd>
				<dt>Último trabajo terminado</dt>
				<dd>{{ estado.lastDone ? `${runName(estado.lastDone)} · ${when(estado.lastDone.at)}` : 'Ninguno todavía' }}</dd>
			</dl>
		</NcSettingsSection>

		<NcSettingsSection name="Fallas"
			description="Trabajos que no se pudieron terminar, con su último error. «Reintentar» los pone de nuevo en la fila, desde el último paso terminado; los de fuera de horario esperan a ese horario.">
			<p v-if="estado && estado.failed.length === 0" class="muted">
				Ninguna.
			</p>
			<ul v-else-if="estado" class="failures">
				<li v-for="run in estado.failed" :key="run.id">
					<div class="failure">
						<strong>{{ runName(run) }}</strong>
						<span>{{ run.error }}</span>
						<span class="muted">{{ when(run.at) }}</span>
					</div>
					<NcButton :disabled="retrying === run.id" @click="retry(run)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiRefresh" />
						</template>
						Reintentar
					</NcButton>
				</li>
			</ul>
		</NcSettingsSection>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import { ocsGet, ocsPost, refusal } from './api.js'
import { longDate } from './bulletins.js'
import { mdiRefresh } from './icons.js'
import { when } from './reviews.js'

/** The model-down alert, in minutes (Health::ALERT). */
const ALERT_MINUTES = 30
const MODEL = { up: 'Responde', down: 'No responde', unknown: 'Sin comprobar todavía' }

/** How «Estado» names a Run: a manual Review stays its requester's. */
function runName({ kind, name, date }) {
	if (kind === 'edition') {
		return name === null ? 'Edición de un boletín eliminado' : `${name} · edición del ${longDate(date)}`
	}
	if (kind === 'flow') {
		return name === null ? 'Revisión de una regla de Flujo eliminada' : `Regla de Flujo · ${name}`
	}
	if (kind === 'action') {
		return name === null ? 'Revisión automática de una acción eliminada' : `Revisión automática · ${name}`
	}
	return 'Revisión pedida a mano'
}

/** «Estado», at the top of the «IA» admin page: the Model, the work in line and the failures. */
export default {
	name: 'AdminEstado',
	components: { NcButton, NcIconSvgWrapper, NcLoadingIcon, NcNoteCard, NcSettingsSection },
	setup: () => ({ ALERT_MINUTES, MODEL, mdiRefresh, runName, when }),
	data: () => ({ estado: null, retrying: null }),
	computed: {
		modelDetail() {
			const { state, since, answered } = this.estado.model
			if (state === 'down') {
				return `· desde ${when(since)}`
			}
			return answered ? `· respondió por última vez ${when(answered)}` : '· «IA» lo comprueba en su próxima vuelta, cada 5 minutos'
		},
	},
	async mounted() {
		try {
			this.estado = await ocsGet('admin/estado')
		} catch (error) {
			console.error('ia: «Estado» did not load', error)
			showError('No se pudo cargar «Estado». Recarga la página.')
		}
	},
	methods: {
		async retry(run) {
			this.retrying = run.id
			try {
				this.estado = await ocsPost(`admin/runs/${run.id}/retry`)
				showSuccess('De nuevo en la fila')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo reintentar. Inténtalo de nuevo.')
			} finally {
				this.retrying = null
			}
		},
	},
}
</script>

<style scoped>
.model {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline);
}

.muted {
	color: var(--color-text-maxcontrast);
}

.work {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 4);
}

.work dd {
	margin: 0;
}

.failures li {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
	justify-content: space-between;
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.failure {
	display: flex;
	flex-direction: column;
	min-width: 0;
}
</style>
