import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import { generateFilePath, generateUrl } from '@nextcloud/router'
import { ocsPost } from './api.js'
import { mdiFileDocumentCheckOutline } from './icons.js'
import { FORMATS, requestFailedText, requestedText } from './reviews.js'

// «Revisar con IA» in a file's menu in Files (APS-Conecta/ia#5): the same Review as «Revisar un
// documento», for one file «IA» reads that the person can open. Files loads this on every page, so
// the toasts load only when the action runs; chunks load as main.js says.
__webpack_public_path__ = generateFilePath('ia', '', 'js/')

registerFileAction({
	id: 'ia-review',
	displayName: () => 'Revisar con IA',
	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${mdiFileDocumentCheckOutline}"/></svg>`,
	enabled: ({ nodes }) => nodes.length === 1
		&& nodes[0].type === FileType.File
		&& FORMATS.includes(nodes[0].mime)
		&& (nodes[0].permissions & Permission.READ) !== 0,
	async exec({ nodes: [node] }) {
		const [{ showError, showSuccess }] = await Promise.all([import('@nextcloud/dialogs'), import('@nextcloud/dialogs/style.css')])
		try {
			const requested = await ocsPost('reviews', { fileId: node.fileid })
			const report = generateUrl('/apps/ia/') + `#revisiones/${requested.review.id}`
			showSuccess(`${requestedText(requested)} Haz clic aquí para verla.`, { onClick: () => window.location.assign(report) })
		} catch (error) {
			showError(requestFailedText(error))
		}
		// «IA»'s own toast says how it went.
		return null
	},
	order: 60,
})
