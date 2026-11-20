# Changelog

All notable changes to `APS-Conecta/ia` are recorded here.

Format: [Keep a Changelog 1.1.0](https://keepachangelog.com/en/1.1.0/).
Versioning: [Semantic Versioning 2.0.0](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- «IA» in the suite menu, opening on «Para ti», which says the person reads no Bulletin yet.
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
