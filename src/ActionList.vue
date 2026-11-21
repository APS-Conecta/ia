<template>
	<div class="ia-page">
		<h2>Acciones automáticas</h2>
		<p class="ia-lead">
			Una acción automática revisa, fuera de horario, cada archivo que se agrega a una carpeta o cambia en ella, y avisa a quien lo agregó o cambió.
		</p>
		<div class="ia-chips">
			<NcButton variant="primary" @click="$emit('create')">
				<template #icon>
					<NcIconSvgWrapper :path="mdiPlus" />
				</template>
				Nueva acción automática
			</NcButton>
		</div>
		<NcLoadingIcon v-if="state === 'loading'" :size="44" />
		<NcNoteCard v-else-if="state === 'failed'" type="error">
			No se pudieron cargar las acciones automáticas. Recarga la página.
		</NcNoteCard>
		<NcEmptyContent v-else-if="actions.length === 0"
			name="Todavía no hay acciones automáticas"
			description="Crea una con «Nueva acción automática»: eliges una carpeta que todo tu grupo editor puede abrir y, si quieres, qué debe revisar además.">
			<template #icon>
				<NcIconSvgWrapper :path="mdiRobotOutline" />
			</template>
		</NcEmptyContent>
		<ul v-else>
			<NcListItem v-for="action in actions"
				:key="action.id"
				:name="action.name"
				href="#acciones"
				:details="action.editorGroup.name"
				@click="edit($event, action.id)">
				<template #icon>
					<NcIconSvgWrapper :path="mdiFolderOutline" />
				</template>
				<template #subname>
					{{ action.folder.name }}{{ action.instructions ? ` · ${action.instructions}` : '' }}
				</template>
			</NcListItem>
		</ul>
		<template v-if="state === 'ready' && flowRules.length > 0">
			<h3>Reglas de Flujo</h3>
			<p class="ia-hint">
				Las crea un administrador en Flujo, con condiciones propias, y revisan igual, fuera de horario. Aquí solo se ven.
			</p>
			<ul>
				<NcListItem v-for="rule in flowRules"
					:key="rule.id"
					:name="rule.name || 'Regla sin nombre'"
					:active="false">
					<template #icon>
						<NcIconSvgWrapper :path="mdiLightningBoltOutline" />
					</template>
					<template #subname>
						{{ rule.instructions || 'Sin instrucciones adicionales' }}
					</template>
				</NcListItem>
			</ul>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet } from './api.js'
import { mdiFolderOutline, mdiLightningBoltOutline, mdiPlus, mdiRobotOutline } from './icons.js'

/**
 * «Acciones automáticas»: the Automatic actions of the Editor's groups, each opening in the sidebar;
 * and the admin's Flow rules «Revisar con IA», read-only.
 */
export default {
	name: 'ActionList',
	components: { NcButton, NcEmptyContent, NcIconSvgWrapper, NcListItem, NcLoadingIcon, NcNoteCard },
	props: {
		/** Bumped by the app after the sidebar saves or deletes, so the list shows it. */
		version: { type: Number, default: 0 },
	},
	emits: ['create', 'edit'],
	setup: () => ({ mdiFolderOutline, mdiLightningBoltOutline, mdiPlus, mdiRobotOutline }),
	data: () => ({ state: 'loading', actions: [], flowRules: [] }),
	watch: {
		version() {
			this.load()
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		/** NcListItem opens a link in a new tab: a plain click opens the action in the sidebar instead. */
		edit(event, id) {
			event.preventDefault()
			this.$emit('edit', id)
		},
		async load() {
			try {
				const listed = await ocsGet('actions')
				this.actions = listed.actions
				this.flowRules = listed.flowRules
				this.state = 'ready'
			} catch (error) {
				console.error('ia: «Acciones automáticas» did not load', error)
				this.state = 'failed'
			}
		},
	},
}
</script>
