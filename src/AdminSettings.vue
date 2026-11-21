<template>
	<div>
		<AdminEstado />

		<NcSettingsSection name="Fuera de horario"
			description="Los boletines y las acciones automáticas corren solo en este horario, para no hacer esperar a quienes usan el Asistente de día. Si el servidor se apaga de noche, muévelo, por ejemplo a 13:00–14:00.">
			<NcLoadingIcon v-if="!settings" :size="32" />
			<div v-else class="hours">
				<NcDateTimePickerNative id="ia-off-start"
					:model-value="asDate(settings.offHours.start)"
					type="time"
					label="Desde"
					@update:model-value="settings.offHours.start = asTime($event)"
					@change="save" />
				<NcDateTimePickerNative id="ia-off-end"
					:model-value="asDate(settings.offHours.end)"
					type="time"
					label="Hasta"
					@update:model-value="settings.offHours.end = asTime($event)"
					@change="save" />
				<span class="zone">{{ zone }}</span>
			</div>
		</NcSettingsSection>

		<NcSettingsSection name="Grupos editores"
			description="Sus miembros crean boletines y acciones automáticas. Esta página se puede delegar a un grupo que no sea administrador.">
			<NcSelect v-if="settings"
				v-model="settings.editorGroups"
				input-label="Grupos editores"
				placeholder="Agregar un grupo…"
				label="name"
				:options="found"
				:loading="searching"
				:filterable="false"
				multiple
				@search="search"
				@update:model-value="save" />
		</NcSettingsSection>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import '@nextcloud/dialogs/style.css'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import AdminEstado from './AdminEstado.vue'
import { ocsGet, ocsPut, refusal } from './api.js'

/** The timezones of Chile, where Off hours read as «hora de Chile». */
const CHILE = ['America/Santiago', 'America/Punta_Arenas', 'Pacific/Easter']

/** HH:MM as the Date NcDateTimePickerNative reads, and back; an emptied field is ''. */
const asDate = (time) => (time ? new Date(`1970-01-01T${time}`) : null)
const asTime = (date) => date?.toTimeString().slice(0, 5) ?? ''

/** The «IA» admin page, for admins and the group it is delegated to: «Estado», Off hours and the Editor groups. */
export default {
	name: 'AdminSettings',
	components: { AdminEstado, NcDateTimePickerNative, NcLoadingIcon, NcSelect, NcSettingsSection },
	setup: () => ({ asDate, asTime }),
	data: () => ({ settings: null, found: [], searching: false }),
	computed: {
		zone() {
			return CHILE.includes(this.settings.timezone) ? 'hora de Chile' : `hora del servidor (${this.settings.timezone})`
		},
	},
	async mounted() {
		try {
			this.settings = await ocsGet('admin/settings')
		} catch (error) {
			console.error('ia: the admin settings did not load', error)
			showError('No se pudo cargar la página de «IA». Recarga la página.')
		}
		this.search('')
	},
	methods: {
		async search(text) {
			this.searching = true
			try {
				this.found = (await ocsGet(`admin/groups?search=${encodeURIComponent(text)}`)).groups
			} catch (error) {
				console.error('ia: the groups did not load', error)
			} finally {
				this.searching = false
			}
		},
		async save() {
			try {
				this.settings = await ocsPut('admin/settings', {
					editorGroups: this.settings.editorGroups.map((group) => group.id),
					offHours: this.settings.offHours,
				})
				showSuccess('Guardado')
			} catch (error) {
				showError(refusal(error) ?? 'No se pudo guardar. Inténtalo de nuevo.')
			}
		},
	},
}
</script>

<style scoped>
.hours {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-end;
}

.zone {
	color: var(--color-text-maxcontrast);
}
</style>
