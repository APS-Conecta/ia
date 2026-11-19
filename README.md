# PROTOTYPE, throwaway: the clickable mockup of «IA»

This branch is never merged. It holds the primary source for «IA»'s screens, as the owner approved them.

- **Question it answered:** what should «IA» look like inside Nextcloud?
- **Verdict (owner, 2026-11-19):** the hybrid layout. The menu is organised by section, everyone starts on
  «Para ti», and Editors also get «Documentos».
- **Built on:** Nextcloud 34 with `@nextcloud/vue` 9's layout (NcContent > NcAppNavigation > NcAppContent >
  NcAppSidebar), the APS Conecta theme, and Material Design Icons 7.4.47. All data is example data, and nothing is
  saved.

Open `ia-prototipo.html` in a browser. The bar at the bottom switches who you are («Ver como») and where you are
(«Ir a»). The spec is APS-Conecta/gestion-aio#162.
