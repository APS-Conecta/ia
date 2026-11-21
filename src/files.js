import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import { generateFilePath, generateUrl } from '@nextcloud/router'
import { ocsPost } from './api.js'
import { mdiCreation } from './icons.js'
import { requestFailedText, requestedText } from './reviews.js'

// «Revisar con IA» in a file's menu in Files (APS-Conecta/ia#5): the same Review as «Revisar un
// documento», for one file «IA» reads that the person can open. Files loads this on every page, so
// the toasts load only when the action runs; chunks load as main.js says.
__webpack_public_path__ = generateFilePath('ia', '', 'js/')

/** The extensions «IA» reads, as TextReader::READS has them: its type alone isn't enough (.conf is text/plain). */
const READS = ['pdf', 'docx', 'odt', 'txt', 'md']

registerFileAction({
	id: 'ia-review',
	displayName: () => 'Revisar con IA',
	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${mdiCreation}"/></svg>`,
	enabled: ({ nodes: [node, ...others], view }) => others.length === 0
		&& view.id !== 'trashbin'
		&& node?.type === FileType.File
		&& (node.permissions & Permission.READ) !== 0
		&& READS.includes(node.extension?.slice(1).toLowerCase()),
	async exec({ nodes: [node] }) {
		const [{ showError, showMessage, ToastType }] = await Promise.all([import('@nextcloud/dialogs'), import('@nextcloud/dialogs/style.css')])
		try {
			const requested = await ocsPost('reviews', { fileId: node.fileid })
			const reviewUrl = generateUrl('/apps/ia/') + `#revisiones/${requested.review.id}`
			const message = document.createElement('span')
			const link = document.createElement('a')
			link.href = reviewUrl
			link.textContent = 'Ver en «IA»'
			message.append(`${requestedText(requested)} `, link)
			showMessage(message, { type: ToastType.SUCCESS })
		} catch (error) {
			showError(requestFailedText(error))
		}
		// «IA»'s own toast says how it went.
		return null
	},
	order: 60,
})
