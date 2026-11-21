<template>
	<NcTextArea :model-value="modelValue"
		label="Qué revisar además (opcional)"
		placeholder="Por ejemplo: revisa que nombre el cargo responsable y su fecha de vigencia."
		:rows="3"
		resize="vertical"
		@update:model-value="$emit('update:model-value', $event)" />
</template>

<script>
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

/** The instructions of a Flow rule «Revisar con IA», in Flow's settings (flow.js). */
export default {
	name: 'FlowReview',
	components: { NcTextArea },
	props: {
		modelValue: { type: String, default: '' },
	},
	emits: ['update:model-value'],
}
</script>
