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
  again. The document is never changed. Other formats and documents over about 10 pages are refused for now.
