# POS Roadmap

Agreed phase list for the Pocket Solutions POS programme.

| Phase | Title | Status |
|------:|-------|--------|
| 1 | POS Transaction Foundation | **Complete** |
| 2 | Inventory Foundation | **Complete** |
| 3 | Product Management | **Complete** (closed Module 3.10, 2026-10-07) |
| 4 | Purchasing & Suppliers | **Complete** (4A + 4B + 4C + 4D, 2026-10-07) |
| 5 | Customers | **Complete** (5.1 + 5.2 + 5.3 + 5.4, 2026-10-08) |
| 6 | Credit / Customer Accounts | In progress — **6.1 + 6.2 + 6.3 + 6.4 + 6.5 Complete** (2026-10-08); remainder Future |
| 7 | Cash Management | **COMPLETE** (7.1–7.6, 2026-10-08) — documented limitations remain (legacy paths, no mid-session handover, no app-wide CSRF, browser testing not performed) |
| 8 | Refunds / Voids / Authorisation | **COMPLETE** (8.1–8.6, 2026-10-09) — closed with documented limitations (CREDIT/AR non-reversal, legacy unmappable refunds, Phase 7 cash attribution, legacy sale paths, no app-wide CSRF, browser testing not performed, live-computed recon findings) |
| 9 | Multi-Branch Control | **COMPLETE** (9.1–9.6, 2026-10-09) — documented limitations remain (historical orphans preserved; NULL OPEN tills; 18 out-of-registry users; mobile sale sync fail-closed; no app-wide CSRF; browser testing not performed) |
| 10 | Reporting & Dashboard | **COMPLETE** (2026-10-09) — management dashboard + sales/inventory/purchasing/cash/refund reports; documented limitations remain (legacy sales vs modern COMPLETED, orphan branches excluded, stock valuation conditional, NULL-branch tills, no app-wide CSRF, browser testing not performed) |
| 11 | Accounting Integration | **COMPLETE** (2026-10-09) — accounting-ready export + reconciliation layer; configurable mappings; no external GL credentials in app; documented limitations remain |
| 12 | VAT / Tax | **COMPLETE** (2026-10-09) — configurable rates/classifications; server-side sale/refund tax; reports; accounting tax split; documented limitations |
| 13 | Barcode & Hardware | **COMPLETE** (2026-10-09) — product barcodes; keyboard-wedge POS scan; browser labels/receipts; documented hardware limits |
| 14 | Security & Audit | **COMPLETE** (2026-10-09) — authn/session/password hardening; CSRF inventory on state-changing web ops; security_audit; Phase 9 ACL preserved; documented deployment residuals |
| 15 | Backup / Reliability | **COMPLETE** (2026-10-09) — plaintext password migration verified; mysqldump backups outside web; retention; isolated restore-tested; health; scheduler instructions only; see `docs/POS_BACKUP.md` |
| 17 | ZIMRA FDMS Fiscalisation | **Implemented** (2026-10-15) — fiscal invoices / credit notes / fiscal days per branch device; Supervisor + Management roles; live ZIMRA test server signing verified; see `docs/POS_ZIMRA.md` |
| 16 | Production Hardening | **COMPLETE** (2026-10-09) — final roadmap phase; see `docs/POS_DEPLOYMENT.md` + `docs/POS_SECURITY.md` |

## Phase 3 modules (completed)

| Module | Title |
|-------:|-------|
| 3.1 | Product Master Audit & Consolidation |
| 3.2 | Product Master Consolidation |
| 3.3 | Product Active/Inactive Status |
| 3.4 | Product Identity & Duplicate Control |
| 3.5 | Product Pricing |
| 3.6 | Branch Product Management |
| 3.7 | Categories / Button Categories |
| 3.8 | Product Import/Export Finalisation |
| 3.9 | Product Audit History |
| 3.10 | Product Management Regression, Architecture Documentation & Phase Closure |

## Phase 4 packages

| Package | Title | Status |
|--------:|-------|--------|
| 4A | Supplier & Purchasing Foundation | **Complete** (2026-10-07) |
| 4B | Purchase Orders | **Complete** (2026-10-07) |
| 4C | Goods Receiving / PO-linked Receiving | **Complete** (2026-10-07) |
| 4D | Purchasing Completion (Returns, Supplier History, Purchasing Reports, Audit Hardening) | **Complete** (2026-10-07) |

Phase 4 is closed.

## Phase 5 packages

| Package | Title | Status |
|--------:|-------|--------|
| 5.1 | Customer Master & Identity Foundation | **Complete** (2026-10-07) |
| 5.2 | POS Customer Integration & Transaction Identity | **Complete** (2026-10-08) |
| 5.3 | Customer Profile & Transaction History | **Complete** (2026-10-08) |
| 5.4 | Customer Management Finalisation & Regression Closure | **Complete** (2026-10-08) |

**Phase 5 — Customers: COMPLETE** (2026-10-08).

## Phase 6 packages

| Package | Title | Status |
|--------:|-------|--------|
| 6.1 | Customer Account & Receivables Ledger Foundation | **Complete** (2026-10-08) |
| 6.2 | Credit Sales / Account-Charged Sales Integration | **Complete** (2026-10-08) |
| 6.3 | Customer Payments & Receivables Allocation | **Complete** (2026-10-08) |
| 6.4 | Customer Account Statements & Receivables Ageing | **Complete** (2026-10-08) |
| 6.5 | Receivables Credit Controls & Collections Foundation | **Complete** (2026-10-08) |

Phase 6 overall is **not** closed — only 6.1–6.5. Later AR packages (interest, write-offs, automated reminders, GL, etc.) remain future.

## Phase 7 packages

| Package | Title | Status |
|--------:|-------|--------|
| 7.1 | Cash Movement & Till Ledger Foundation | **Complete** (2026-10-08) |
| 7.2 | Till Cash Reconciliation | **Complete** (2026-10-08) |
| 7.3 | Cash-In / Cash-Out Controls | **Complete** (2026-10-08) |
| 7.4 | Till Closure & Cashier Handover | **Complete** (2026-10-08) — closure complete; mid-session handover deferred (documented limitation) |
| 7.5 | Cash Management Dashboard & Reporting | **Complete** (2026-10-08) — read-only dashboard + movement/recon/closure reports; no second ledger |
| 7.6 | Cash Management Finalisation & Closure | **Complete** (2026-10-08) — integration audit 7.1–7.5; single cash formula; regression; Phase 7 closed |

**Phase 7 — Cash Management: COMPLETE** (2026-10-08). Out-of-scope / deferred limitations documented (mid-session handover, deposits/bank recon, GL/shortage accounting, app-wide CSRF, legacy non-ledger sale paths, browser testing not performed).

## Phase 8 packages

| Package | Title | Status |
|--------:|-------|--------|
| 8.1 | Refund Foundation Audit & Controlled Refund Model | **Complete** (2026-10-08) |
| 8.2 | Refund authorisation / manager approval | **Complete** (2026-10-08) — thresholds not implemented (deferred) |
| 8.3 | Partial refunds / line-level cumulative tracking | **Complete** (2026-10-08) |
| 8.4 | Voids / cancellation | **Complete** (2026-10-09) |
| 8.5 | Refund / cash / stock reconciliation hardening | **Complete** (2026-10-09) |
| 8.6 | Refund reporting | **Complete** (2026-10-09) |

**Phase 8.1 COMPLETE** (2026-10-08). **Phase 8.2 COMPLETE** (2026-10-08). **Phase 8.3 COMPLETE** (2026-10-08). **Phase 8.4 COMPLETE** (2026-10-09). **Phase 8.5 COMPLETE** (2026-10-09). **Phase 8.6 COMPLETE** (2026-10-09). **Phase 8 — Refunds / Voids / Authorisation: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS (final closure audit).

## Phase 9 packages

| Package | Title | Status |
|--------:|-------|--------|
| 9.1 | Multi-Branch Control Audit | **Complete** (2026-10-09) — audit-only; no application/schema/data changes |
| 9.2 | Authorised branch resolution & switch hardening | **Complete** (2026-10-09) — C1/C2/H1; trusted home/working/authorised helpers |
| 9.3 | Legacy / unauthenticated endpoint branch gates | **Complete** (2026-10-09) — C4/C5/H2/H4; auth+branch gates; `submit_sales.php` write path retired |
| 9.4 | Mobile / API branch authorisation | **Complete** (2026-10-09) — C3/H3; mobile token auth + home-branch binding; catalogue/sale/session sync hardened |
| 9.5 | Historical orphan branch data policy & soft integrity | **Complete** (2026-10-09) — classification policy; soft write gates; read-only diagnostics; no historical rewrite; no sequence reset |
| 9.6 | Unified branch helpers, constraints & regression closure | **Complete** (2026-10-09) — helper consolidation; legacy UI gates; UNIQUE/FK deferred; mobile sync fail-closed; consolidated regression |

**Phase 9.1 COMPLETE** (2026-10-09). **Phase 9.2 COMPLETE** (2026-10-09). **Phase 9.3 COMPLETE** (2026-10-09). **Phase 9.4 COMPLETE** (2026-10-09). **Phase 9.5 COMPLETE** (2026-10-09). **Phase 9.6 COMPLETE** (2026-10-09). **Phase 9 — Multi-Branch Control: COMPLETE** (READY WITH DOCUMENTED LIMITATIONS).

## Phase 10 packages

| Package | Title | Status |
|--------:|-------|--------|
| 10 | Reporting & Dashboard (complete implementation) | **Complete** (2026-10-09) — read-only management dashboard; sales/inventory reports + CSV; purchasing CSV; reuses Phase 7 cash + Phase 8 refund recon; Phase 9 branch ACL |

**Phase 10 — Reporting & Dashboard: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS.

## Phase 11 packages

| Package | Title | Status |
|--------:|-------|--------|
| 11 | Accounting Integration (complete implementation) | **Complete** (2026-10-09) — configurable account mappings; balanced journal preview/export batches + CSV; idempotent source locks; safe reversal; POS↔export recon; Phase 9 ACL; no external GL connector |

**Phase 11 — Accounting Integration: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS. External accounting platform connection remains a dependency (credentials/platform unavailable).

## Phase 12 packages

| Package | Title | Status |
|--------:|-------|--------|
| 12 | VAT / Tax (complete implementation) | **Complete** (2026-10-09) — configurable tax rates (TAXABLE / ZERO_RATED / EXEMPT), inclusive/exclusive, effective dates; server-side sale & refund tax persistence; receipts; tax reports; accounting `tax.output` split for COMPLETE data; legacy incomplete never invented |

**Phase 12 — VAT / Tax: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS. Legal/tax-policy confirmation and external accounting acceptance remain business dependencies (not claimed).

## Phase 13 packages

| Package | Title | Status |
|--------:|-------|--------|
| 13 | Barcode & Hardware Support (complete implementation) | **Complete** (2026-10-09) — `goods.barcode` + UNIQUE(branch,barcode); create/edit/import/search; POS keyboard-wedge scan via existing cart/`submit_sale2`; browser Code 128 labels + 80mm receipt print CSS; GRV/adjustment/catalogue barcode lookup; no second sale path; no invented barcodes |

**Phase 13 — Barcode & Hardware: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS. Physical peripherals (wedge scanners, thermal printers, cash drawer, scale, customer display) were **not** tested on hardware. Direct ESC/POS / cash-drawer / scale / customer-display integrations are **not** claimed. Browser print + keyboard-wedge (HID) are the supported compatibility model.

## Phase 14 packages

| Package | Title | Status |
|--------:|-------|--------|
| 14 | Security & Audit: Full-System Hardening | **Complete** (2026-10-09) — see `docs/POS_SECURITY.md` |

**Phase 14 — Security & Audit: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS. Not a claim of “fully secure”; browser/pen-test/external certification not performed.

## Phase 15 packages

| Package | Title | Status |
|--------:|-------|--------|
| 15 | Backup, Recovery & Reliability (complete implementation) | **Complete** (2026-10-09) — mass plaintext→hash migration (plaintext remaining 0); CSRF fixes on residual financial POSTs; mysqldump to private root; retention; isolated restore test PASS; health endpoint; scheduling instructions only |

**Phase 15 — Backup / Reliability: COMPLETE** (2026-10-09) — CLOSED WITH DOCUMENTED LIMITATIONS. Encryption-at-rest / off-site / host scheduler activation are deployment dependencies (not claimed active).

## Phase 16 packages

| Package | Title | Status |
|--------:|-------|--------|
| 16 | Production Hardening: Final Roadmap Phase | **Complete** (2026-10-09) — secrets out of webroot; legacy unauth writers disabled; session/CSRF/access hardening; deployment + ops guide; CSRF inventory updated honestly |

**Phase 16 — Production Hardening: COMPLETE** (2026-10-09) — FINAL ROADMAP PHASE. CLOSED WITH DOCUMENTED LIMITATIONS. **Not a claim of live production deployment**, HTTPS/certificate configuration, OS ACL application, scheduled backups, off-site/encrypted DR, or browser/pen-test. Phase 17 (ZIMRA fiscalisation) added 2026-10-15 at the business's request. Phase 18 (companies register, Super Admin, administration on behalf of clients — `docs/POS_PLATFORM.md`) added 2026-10-15.
