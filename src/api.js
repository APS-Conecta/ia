import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * The page reads «IA»'s own OCS API (ApiController). @nextcloud/axios sends the session's request
 * token, and the payload sits inside the OCS envelope.
 */
export async function ocsGet(path) {
	const { data } = await axios.get(generateOcsUrl(`/apps/ia/api/v1/${path}`))
	return data.ocs.data
}

export async function ocsPost(path, body = {}) {
	const { data } = await axios.post(generateOcsUrl(`/apps/ia/api/v1/${path}`), body)
	return data.ocs.data
}

export async function ocsPut(path, body) {
	const { data } = await axios.put(generateOcsUrl(`/apps/ia/api/v1/${path}`), body)
	return data.ocs.data
}

/** The Spanish reason a refused call answers with, or null. */
export function refusal(error) {
	return error?.response?.data?.ocs?.data?.message ?? null
}
