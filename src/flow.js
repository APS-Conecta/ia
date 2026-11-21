import { generateFilePath } from '@nextcloud/router'
import { defineCustomElement } from 'vue'
import FlowReview from './FlowReview.vue'

// «Revisar con IA» in Nextcloud Flow (APS-Conecta/ia#11): Flow's settings show the operation with
// this element, which edits the rule's instructions (the rule's operation string). Chunks load as
// main.js says.
__webpack_public_path__ = generateFilePath('ia', '', 'js/')

const ELEMENT = 'oca-ia-flow-review'
window.customElements.get(ELEMENT) ?? window.customElements.define(ELEMENT, defineCustomElement(FlowReview, { shadowRoot: false }))
window.OCA.WorkflowEngine.registerOperator({
	id: 'OCA\\Ia\\Flow\\ReviewOperation',
	operation: '',
	element: ELEMENT,
})
