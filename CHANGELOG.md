# Changelog

All notable changes to `APS-Conecta/ia` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- «IA» in the suite menu, opening on «Para ti».
- Reviews of short TXT and MD documents (#2): «Revisar un documento» picks a file, and the report in «Revisiones»
  lists its errors, gaps and unclear parts, each with where it is and a fix, marked «Escrito por IA». The requester
  is notified when it is ready, sees it only while they can open the file, and can review the current version
  again. The document is never changed.
- The «IA» admin page (#3): the admin picks the Editor groups and Off hours (22:00–06:00 by default, in the server's
  timezone), and can delegate the page to a group under Administration privileges. «IA» tells each person which
  Editor groups they belong to, and «Para editores» shows in the navigation only to Editors.
- The run engine (#6): a Review of a document over about 10 pages waits for Off hours, shows its progress (step i of
  n) and notifies the requester when ready. A tick every 5 minutes asks at most one Off-hours step at a time, stops
  between steps when Off hours end, retries a failed or lost task, cancels one stuck past the request timeout plus 5
  minutes, and fails a Run with its last error after 3 attempts. A second request for the same file is the same
  Review. Its state lives in the database, so a restart or a stopped Model resumes from the last finished step.
- PDF, DOCX and ODT (#4): a PDF with text is read through smalot/pdfparser 2.13.1, vendored under a prefix so it never
  meets Assistant's copy; DOCX and ODT through PHP's zip and XML readers. Each finding names its page (PDF) or section
  (DOCX, ODT, MD), and a file's own page count decides whether it waits for Off hours. A scan, a password-protected or
  a damaged file is turned down plainly, and the Model is asked nothing. «Revisar un documento» offers only PDF, DOCX,
  ODT, TXT and MD files, by their extension (#36).
- Bulletin set-up (#7): an Editor group's members create, change and delete its Bulletins in a sidebar (tabs Fuentes,
  Horario, Lectores): Sources are folders, documents and calendars the whole Editor group can open (one it can't is
  refused, naming who), Readers are Nextcloud groups, and the schedule is daily, weekly or monthly on a day, or «cuando
  cambien sus fuentes». A preview counts the Readers and names those left out; «Boletines» lists the Bulletins each
  person edits or reads, and a Bulletin's page says when its first Edition is due.
- The first Edition (#10): on its date, in the Off hours before it, a Bulletin's Run reads its Sources as a member of
  the Editor group who can open them all. The Model takes a Reading of each document once per version (a summary, an
  expiry date kept only if the text says it, and Cargos only from the establishment's list) and writes the Resumen
  from the Readings, in context-sized rounds. The Edition is published whole, marked «Escrito por IA», with
  «Documentos nuevos y cambiados» since the previous one, «Protocolos por vencer» within 30 days (and those with no
  date found) and «Fuentes que no se pudieron leer»; a Reading's summary shows, marked, in its dialog (#27). Readers
  are notified and see it on the Bulletin's page only while they can open every file it read that is still there;
  each document opens its Reading and the file. Deleting a file deletes its Reading.
- Automatic actions (#9): an Editor group puts a rule on a folder the whole group can open, with optional instructions,
  in «Acciones automáticas» under «Para editores». Every file added to it, changed in it, moved or copied into it is
  reviewed in Off hours, once, on its latest version, with those instructions, as whoever added or changed it (failing
  that, a member of the Editor group who can open it); they are notified. Anyone who can open the file reads its
  automatic report, which «Revisiones» lists with their own; each file keeps only its latest one. Deleting an action
  asks for no new Reviews and keeps the reports.
- The Personal list, «Para ti» and the «Boletines» widget (#12): opening an Edition, each Reader sees their own part,
  computed then and never stored: the timed events in their calendars during the Edition's period and its documents
  whose Reading names one of their Cargo groups. «Para ti» merges what is ahead in these lists (#28: the meetings not
  yet ended and the documents new or changed) from the latest Edition of each Bulletin the person reads, with the
  protocols about to expire and those Editions, and says they come from their calendar and Cargo, not from the Model.
  The Dashboard shows the latest Editions each person can see. «IA» reads the Cargo list from its app config as the
  Cargo groups' ids; a Reading keeps the ids it names.
- Missed dates, late Editions and «cuando cambien sus fuentes» (#13): after missed dates the next Off hours write one
  Edition, for the latest date due, and none opens while another is being written or before the night after the last
  one was published. An Edition still unpublished once the Off hours of its date are over is «atrasada»: its Editors
  are told once, it continues the next night from its last finished step, and it shows «atrasada» once published;
  meanwhile its Editors see step i of n. They are told once when a Source is gone and once per version when a
  document Source can't be read. A Bulletin that comes «cuando cambien sus fuentes» gets an Edition only after a
  folder's or document's etag or a calendar's sync token changed from what its Editions are up to. Each Edition keeps
  its period for the Personal list's meetings: to the next date due, or, «cuando cambien sus fuentes», to the next
  Edition's date and a week on while it is the latest.
- «Estado» (#8), at the top of the «IA» admin page: whether the Model answers, the work in line by kind, the last Run
  that ended well and the failed Runs with their last error, each with «Reintentar», which puts it back in line from
  its last finished step (not an Edition a newer one has passed). A Run with nobody to act as shows there and tells
  nobody. Each tick checks the Model through TaskProcessing with a one-word probe, every 30 minutes while it answers
  and every tick while it doesn't; a probe waiting in the queue is no failed check. Once it has been down 30 minutes
  from the first failed check, the admins get one notification and a Nextcloud setup check warns; the time the server
  is stopped, as for the nightly backup, doesn't count. That check also reports whether a provider takes
  core:text2text. Admins and the admin page's delegated group find «Estado» at the foot of «IA»'s navigation.
- «Revisar con IA» in Files (#5): a file's menu offers it for one file «IA» reads (PDF with text, DOCX, ODT, TXT, MD)
  that the person can open, not for folders, other types or the trash. It starts the same Review as «Revisar un
  documento», with the same «Revisión lista» notification, and a toast says whether it runs now or in Off hours, or
  is ready already, and links to it with «Ver en «IA»».
- Correct or withdraw an Edition (#14): any Editor of the Bulletin's group corrects its Resumen, and its Readers see
  «Corregida por» and who, next to «Escrito por IA». Withdrawing takes the Edition at once from its Readers' pages,
  «Para ti» and the «Boletines» widget; its notification's link then says its Editors withdrew it, and its Editors
  still see it, with «Deshacer», which brings it back. Anyone else is forbidden.
- Retention (#16): a daily job deletes reports 90 days after they were asked for and Editions 12 months after their
  date, each with its Run and notifications, and «IA»'s ended TaskProcessing tasks that nothing waits for.
- The Flow rule «Revisar con IA» (#11): an admin who wants Flow's richer conditions adds it in Flujo with their own
  instructions. A file event it matches puts the file's automatic Review in line for Off hours, as an Automatic
  action would, acting as whoever set the event off; with no session it fails into «Estado» and nobody is told.
  Editors see the rules, read-only, in «Acciones automáticas».
- «Documentos» for Editors (#15), under «Para editores»: the documents their Editor group watches (its Bulletins'
  Sources and its Automatic actions' folders) that the Editor can open now, each with its Reading's status, expiry
  date and Cargos and its latest Review's findings. Filters: Todos, Nuevos y cambiados (in the last 7 days), Por
  vencer (within 30 days, or read without a date), Con hallazgos and Para mi cargo. A document's sidebar shows its
  Reading, its latest Review or «Revisar ahora», and the Bulletins it appears in.
