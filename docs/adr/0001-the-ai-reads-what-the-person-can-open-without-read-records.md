<!--
  - SPDX-FileCopyrightText: 2026 APS Conecta
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# 1. The AI reads what the person can open, without read records

Accepted, 2026-11-18 (grill for the «IA» app).

«IA» lets the Model read whatever the person it works for can open, patient-data apps (Farmacia, Epidemiología)
included. It keeps no read or view log: Reviews and Editions name the file versions they used, but nothing records
who looked at what. Nextcloud's own permissions, checked live, are the only limit, but for the «Recursos Humanos»
folder, which «IA» never reads (recursos-humanos ADR 0004).
The Model runs on the suite server, so no data leaves the establishment.

## Considered Options

- Keep patient-data apps out until each is reviewed (the 2026-11-09 suite-AI rule): rejected; the owner chose access
  from day one.
- Record every read in Nextcloud's audit log: rejected, keeping the suite's rule that only confirmed writes are
  audited.
- Read only folders the admin allows: rejected, because permissions already say who may see what.

## Consequences

- Patient data may appear in Reviews and Editions, under the same live checks.
- «IA» can't answer "who looked at this data".
- Chile's Ley 21.719 treats health data as sensitive from 2026-12-01. So «IA» ships only after the privacy review it
  shares with Buscador Documental.
