<template>
	<NcContent app-name="ia">
		<!-- The navigation lists only what works; each later section adds its entry here. -->
		<NcAppNavigation aria-label="IA">
			<template #list>
				<NcAppNavigationItem name="Para ti" :active="true">
					<template #icon>
						<NcIconSvgWrapper :path="mdiStarOutline" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent page-heading="Para ti">
			<ParaTi />
		</NcAppContent>
	</NcContent>
</template>

<script>
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ParaTi from './ParaTi.vue'
import { mdiStarOutline } from './icons.js'

export default {
	name: 'App',
	components: { NcAppContent, NcAppNavigation, NcAppNavigationItem, NcContent, NcIconSvgWrapper, ParaTi },
	setup: () => ({ mdiStarOutline }),
}
</script>
