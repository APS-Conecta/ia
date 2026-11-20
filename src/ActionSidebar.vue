<template>
	<NcAppSidebar :name="draft.name"
		name-placeholder="Nombre de la acción"
		:name-editable="true"
		:subname="id === null ? 'Nueva acción automática' : 'Acción automática de ' + groupName"
		@update:name="draft.name = $event"
		@close="$emit('close')">
		<template #description>
			<div class="ia-chips">
				<NcButton variant="primary" :disabled="saving" @click="save">
					Guardar
				</NcButton>
				<NcButton v-if="id !== null" variant="error" @click="confirming = true">
					Eliminar
				</NcButton>
			</div>
		</template>
		<div class="ia-form">
			<NcSelect v-if="id === null"
				v-model="draft.editorGroup"
				input-label="Grupo editor"
				:options="editorGroups"
				:reduce="(group) => group.id"
				label="name" />
			<div class="ia-chips">
				<NcIconSvgWrapper :path="mdiFolderOutline" />
				<span>{{ draft.folder?.name ?? 'Ninguna carpeta elegida' }}</span>
				<NcButton variant="secondary" @click="pickFolder">
					{{ draft.folder ? 'Cambiar carpeta' : 'Elegir carpeta' }}
				</NcButton>
			</div>
			<p class="ia-hint">
				Una carpeta que todo el grupo editor puede abrir. Se revisa cada archivo que llegue o cambie en ella o en sus subcarpetas.
			</p>
			<NcTextArea v-model="draft.instructions"
				label="Instrucciones (opcional)"
				placeholder="Por ejemplo: comprueba que tenga fecha de vigencia y responsables."
				:maxlength="1000"
				resize="vertical" />
		</div>
	</NcAppSidebar>
	<NcDialog v-if="confirming"
		name="Eliminar la acción automática"
		:message="`¿Eliminar «${draft.name}»? Los archivos que lleguen a la carpeta ya no se revisarán; las revisiones hechas se quedan.`"
		:buttons="[{ label: 'Cancelar' }, { label: 'Eliminar', variant: 'error', callback: destroy }]"
		@closing="confirming = false" />
</template>

<script>
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { ocsDelete, ocsGet, ocsPost, ocsPut, refusal } from './api.js'
import { mdiFolderOutline } from './icons.js'

/** An Automatic action's single form, for its Editors: name, folder and optional instructions. */
export default {
	name: 'ActionSidebar',
	components: { NcAppSidebar, NcButton, NcDialog, NcIconSvgWrapper, NcSelect, NcTextArea },
	props: {
		/** The action, or null for a new one. */
		id: { type: Number, default: null },
		/** The caller's Editor groups, for a new action. */
		editorGroups: { type: Array, required: true },
	},
	emits: ['close', 'saved', 'deleted'],
	setup: () => ({ mdiFolderOutline }),
	data() {
		return {
			draft: { name: '', editorGroup: this.editorGroups[0]?.id ?? null, folder: null, instructions: '' },
			groupName: '',
			saving: false,
			confirming: false,
		}
	},
	async mounted() {
		if (this.id === null) {
			return
		}
		try {
			const action = (await ocsGet('actions')).actions.find((known) => known.id === this.id)
			if (!action) {
				throw new Error('not among the caller\'s actions')
			}
			this.draft = { name: action.name, editorGroup: action.editorGroup.id, folder: action.folder, instructions: action.instructions }
			this.groupName = action.editorGroup.name
		} catch (error) {
			showError(refusal(error) ?? 'No se pudo cargar la acción automática.')
			this.$emit('close')
		}
	},
	methods: {
		async pickFolder() {
			const picker = getFilePickerBuilder('Elegir la carpeta')
				.allowDirectories(true)
				.setMimeTypeFilter(['httpd/unix-directory'])
				.addButton({
					label: 'Elegir',
					variant: 'primary',
					callback: ([node]) => {
						this.draft.folder = { id: String(node.fileid), name: node.basename }
					},
				})
				.build()
			try {
				await picker.pick()
			} catch {
				// Closed without a choice.
			}
		},
		async save() {
			if (!this.draft.folder) {
				showError('Elige una carpeta.')
				return
			}
			this.saving = true
			const request = { name: this.draft.name, folder: this.draft.folder.id, instructions: this.draft.instructions }
			try {
				await (this.id === null
					? ocsPost('actions', { ...request, editorGroup: this.draft.editorGroup })
					: ocsPut(`actions/${this.id}`, request))
				showSuccess('Acción automática guardada')
				this.$emit('saved')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo guardar la acción automática. Inténtalo de nuevo.')
			} finally {
				this.saving = false
			}
		},
		async destroy() {
			try {
				await ocsDelete(`actions/${this.id}`)
				this.$emit('deleted')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo eliminar la acción automática.')
			}
			this.confirming = false
		},
	},
}
</script>
