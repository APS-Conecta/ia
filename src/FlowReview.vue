<template>
	<div class="ia-flow-review">
		<NcTextField :model-value="rule.name"
			label="Nombre"
			placeholder="Por ejemplo: Protocolos nuevos de enfermería"
			:maxlength="200"
			required
			@update:model-value="change({ name: $event })" />
		<NcTextArea :model-value="rule.instructions"
			label="Instrucciones (opcional)"
			placeholder="Por ejemplo: comprueba que tenga fecha de vigencia y responsables."
			:maxlength="1000"
			:rows="3"
			resize="vertical"
			@update:model-value="change({ instructions: $event })" />
	</div>
</template>

<script>
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

/**
 * A Flow rule «Revisar con IA» in Flow's settings (flow.js): its name, which «Acciones automáticas»
 * and «Estado» show, and its instructions, both kept in the rule's operation string as JSON
 * (FlowRules::parse).
 */
export default {
	name: 'FlowReview',
	components: { NcTextArea, NcTextField },
	props: {
		modelValue: { type: String, default: '' },
	},
	emits: ['update:model-value'],
	computed: {
		rule() {
			try {
				const { name = '', instructions = '' } = JSON.parse(this.modelValue)
				return { name, instructions }
			} catch {
				return { name: '', instructions: this.modelValue }
			}
		},
	},
	methods: {
		change(field) {
			this.$emit('update:model-value', JSON.stringify({ ...this.rule, ...field }))
		},
	},
}
</script>

<style scoped>
.ia-flow-review {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
