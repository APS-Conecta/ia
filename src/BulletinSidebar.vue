<template>
	<NcAppSidebar v-model:active="tab"
		:name="draft.name"
		name-placeholder="Nombre del boletín"
		:name-editable="true"
		:subname="id === null ? 'Nuevo boletín' : 'Boletín de ' + groupName"
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
		<NcAppSidebarTab id="fuentes" name="Fuentes" :order="1">
			<template #icon>
				<NcIconSvgWrapper :path="mdiFolderOutline" />
			</template>
			<p class="ia-hint">
				Carpetas, documentos y calendarios que todo el grupo editor puede abrir.
			</p>
			<ul>
				<li v-for="source in draft.sources" :key="source.type + source.id" class="ia-chips">
					<NcIconSvgWrapper :path="source.type === 'calendar' ? mdiCalendarOutline : source.type === 'folder' ? mdiFolderOutline : mdiFileDocumentOutline" />
					<span>{{ source.name }}</span>
					<NcButton variant="tertiary" :aria-label="`Quitar ${source.name}`" @click="remove(source)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiClose" />
						</template>
					</NcButton>
				</li>
			</ul>
			<div class="ia-chips">
				<NcButton variant="secondary" @click="pickFiles">
					Agregar carpeta o documento
				</NcButton>
			</div>
			<NcSelect v-model="calendar"
				input-label="Agregar un calendario"
				placeholder="Elegir un calendario…"
				label="name"
				:options="calendars"
				@update:model-value="addCalendar" />
		</NcAppSidebarTab>

		<NcAppSidebarTab id="horario" name="Horario" :order="2">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCalendarOutline" />
			</template>
			<NcCheckboxRadioSwitch v-for="option in KINDS"
				:key="option.kind"
				:model-value="draft.schedule.kind"
				:value="option.kind"
				name="ia-kind"
				type="radio"
				@update:model-value="setKind">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
			<NcSelect v-if="draft.schedule.kind === 'weekly' || draft.schedule.kind === 'monthly'"
				v-model="draft.schedule.day"
				:input-label="draft.schedule.kind === 'weekly' ? 'Día de la semana' : 'Día del mes'"
				:options="days"
				:reduce="(option) => option.value"
				label="label" />
			<p class="ia-hint">
				Cada edición se escribe fuera de horario, la noche anterior.
			</p>
		</NcAppSidebarTab>

		<NcAppSidebarTab id="lectores" name="Lectores" :order="3">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAccountGroupOutline" />
			</template>
			<NcSelect v-if="id === null"
				v-model="draft.editorGroup"
				input-label="Grupo editor"
				:options="editorGroups"
				:reduce="(group) => group.id"
				label="name" />
			<NcSelect v-model="draft.readers"
				input-label="Grupos lectores"
				placeholder="Agregar un grupo…"
				label="name"
				:options="found"
				:filterable="false"
				multiple
				@search="search" />
			<p v-if="preview" class="ia-hint" role="status">
				Llega a {{ preview.readers }} {{ preview.readers === 1 ? 'persona' : 'personas' }}.
				<template v-if="preview.leftOut.length > 0">
					No lo recibirán, porque no pueden abrir una fuente: {{ preview.leftOut.map((person) => person.name).join(', ') }}.
				</template>
			</p>
		</NcAppSidebarTab>

	</NcAppSidebar>
	<NcDialog v-if="confirming"
		name="Eliminar el boletín"
		:message="`¿Eliminar «${draft.name}»? Sus lectores dejarán de recibirlo.`"
		:buttons="[{ label: 'Cancelar', callback: () => { confirming = false } }, { label: 'Eliminar', variant: 'error', callback: destroy }]"
		@closing="confirming = false" />
</template>

<script>
import { getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { ocsDelete, ocsGet, ocsPost, ocsPut, refusal } from './api.js'
import { DAYS, KINDS } from './bulletins.js'
import { mdiAccountGroupOutline, mdiCalendarOutline, mdiClose, mdiFileDocumentOutline, mdiFolderOutline } from './icons.js'

/**
 * A Bulletin's set-up, for its Editors: tabs Fuentes, Horario and Lectores. The Readers' count is
 * asked again whenever the Sources or the Reader groups change.
 */
export default {
	name: 'BulletinSidebar',
	components: { NcAppSidebar, NcAppSidebarTab, NcButton, NcCheckboxRadioSwitch, NcDialog, NcIconSvgWrapper, NcSelect },
	props: {
		/** The Bulletin, or null for a new one. */
		id: { type: Number, default: null },
		/** The caller's Editor groups, for a new Bulletin. */
		editorGroups: { type: Array, required: true },
	},
	emits: ['close', 'saved', 'deleted'],
	setup: () => ({ KINDS, mdiAccountGroupOutline, mdiCalendarOutline, mdiClose, mdiFileDocumentOutline, mdiFolderOutline }),
	data() {
		return {
			tab: 'fuentes',
			draft: { name: '', editorGroup: this.editorGroups[0]?.id ?? null, sources: [], readers: [], schedule: { kind: 'weekly', day: 1 } },
			groupName: '',
			calendars: [],
			calendar: null,
			found: [],
			preview: null,
			saving: false,
			confirming: false,
		}
	},
	computed: {
		days() {
			return this.draft.schedule.kind === 'weekly'
				? DAYS.map((day, index) => ({ value: index + 1, label: day }))
				: Array.from({ length: 28 }, (_, index) => ({ value: index + 1, label: String(index + 1) }))
		},
		reach() {
			return JSON.stringify([this.draft.sources.map((source) => source.type + source.id), this.draft.readers.map((group) => group.id)])
		},
	},
	watch: {
		reach() {
			this.estimate()
		},
	},
	async mounted() {
		if (this.id !== null) {
			const { bulletin } = await ocsGet(`bulletins/${this.id}`)
			this.draft = { name: bulletin.name, editorGroup: bulletin.editorGroup.id, sources: bulletin.sources, readers: bulletin.readers, schedule: { ...bulletin.schedule } }
			this.groupName = bulletin.editorGroup.name
		}
		this.calendars = (await ocsGet('calendars')).calendars
		this.search('')
	},
	methods: {
		async pickFiles() {
			const picker = getFilePickerBuilder('Agregar fuentes')
				.allowDirectories(true)
				.setMultiSelect(true)
				.addButton({
					label: 'Agregar',
					variant: 'primary',
					callback: (nodes) => nodes.forEach((node) => this.add({ type: node.type === 'folder' ? 'folder' : 'file', id: String(node.fileid), name: node.basename })),
				})
				.build()
			try {
				await picker.pick()
			} catch {
				// Closed without a choice.
			}
		},
		setKind(kind) {
			this.draft.schedule = { kind, day: kind === 'weekly' || kind === 'monthly' ? 1 : null }
		},
		addCalendar(calendar) {
			if (calendar) {
				this.add({ type: 'calendar', id: calendar.id, name: calendar.name })
			}
			this.calendar = null
		},
		add(source) {
			if (!this.draft.sources.some((known) => known.type === source.type && known.id === source.id)) {
				this.draft.sources.push(source)
			}
		},
		remove(source) {
			this.draft.sources = this.draft.sources.filter((known) => known !== source)
		},
		async search(text) {
			try {
				this.found = (await ocsGet(`groups?search=${encodeURIComponent(text)}`)).groups
			} catch (error) {
				console.error('ia: the groups did not load', error)
			}
		},
		async estimate() {
			if (this.draft.sources.length === 0 || this.draft.readers.length === 0) {
				this.preview = null
				return
			}
			try {
				this.preview = await ocsPost('bulletins/preview', this.request())
			} catch {
				this.preview = null
			}
		},
		request() {
			return {
				name: this.draft.name,
				editorGroup: this.draft.editorGroup,
				sources: this.draft.sources.map(({ type, id }) => ({ type, id })),
				readers: this.draft.readers.map((group) => group.id),
				schedule: this.draft.schedule,
			}
		},
		async save() {
			this.saving = true
			try {
				const { bulletin } = this.id === null
					? await ocsPost('bulletins', this.request())
					: await ocsPut(`bulletins/${this.id}`, this.request())
				showSuccess('Boletín guardado')
				this.$emit('saved', bulletin.id)
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo guardar el boletín. Inténtalo de nuevo.')
			} finally {
				this.saving = false
			}
		},
		async destroy() {
			try {
				await ocsDelete(`bulletins/${this.id}`)
				this.$emit('deleted')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo eliminar el boletín.')
			}
			this.confirming = false
		},
	},
}
</script>
