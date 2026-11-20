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
  a damaged file is turned down plainly, and the Model is asked nothing.
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
  whose Reading names one of their Cargo groups. «Para ti» merges these lists from the latest Edition of each Bulletin
  the person reads, with the protocols about to expire and those Editions, and says they come from their calendar and
  Cargo, not from the Model. The Dashboard shows the latest Editions each person can see. «IA» reads the Cargo list
  from its app config as the Cargo groups' ids; a Reading keeps the ids it names.
