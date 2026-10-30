# LedgerLens — from any supplier document to a validated ledger

**Meta Global AI Developer Hackathon 2026 · Indie track · Theme: Build for Your World**

## The problem (firsthand)
I am a chief accountant in a manufacturing company in Bahrain. Every month I retype
supplier invoices and statements of account into the ledger: PDFs with merged cells,
broken columns, Arabic/English mixed headers, and 3-decimal BHD amounts. 20–30 minutes
per statement, and every retyped number is a chance to be wrong. OCR tools read the text
but not the *accounting*: they miss VAT, discounts, advance adjustments and line totals.

## What LedgerLens does
1. **Ingest** — photo or PDF of an invoice, statement, delivery note or receipt.
2. **Understand** — Meta vision models (SAM 3.1 for document regions, Muse for handwriting
   and mixed-language text) extract vendor, invoice no, date, PO, line items, discounts,
   advance payments, taxable amount, VAT and total.
3. **Validate like an accountant** — arithmetic checks on every document:
   - qty × unit price = line total
   - line totals − discounts = taxable amount
   - taxable × VAT rate (10% Bahrain) = VAT amount
   - taxable + VAT − advances = net due
   Any mismatch is flagged in red instead of being silently posted.
4. **Deliver** — one-click CSV/Excel export, or POST straight into an accounting API
   (Arshara Finance API already exists), with a human confirmation step per document.
5. **Learn** — vendor templates are remembered, so the second invoice from the same
   supplier is extracted with near-zero correction.

## Why Meta models
- **SAM 3.1** segments tables, stamps and signatures so numbers are read from the right cell.
- **Muse (vision)** handles handwriting and Arabic/English layouts that classic OCR mangles.
- **Muse Code** accelerated the build: the extraction + validation pipeline was written
  in a day with an agent doing the boilerplate.
- Open-weights models run on Baseten/Modal/Fireworks credits for bulk statement batches.

## Why it matters (Build for Your World)
Small and mid-size businesses in the Gulf run on paper and PDFs. Their accountant is a
human bottleneck. LedgerLens turns that bottleneck into a 30-second review, without asking
anyone to change their accounting system — and it understands the local rules (10% VAT,
BHD 3 decimals, bilingual documents) that generic OCR ignores.

## Demo (what judges will see)
Three real anonymised documents:
1. A GRP tank invoice with a discount and advance adjustment (2 lines, 1 advance).
2. A planter quotation (4 price points, VAT-inclusive).
3. A customer statement of account with 48 rows and ageing buckets.
LedgerLens extracts each, shows the validation panel (all green), and exports a ledger CSV.
Then one document with a deliberate ₹/decimal error → flagged red, not posted.

## How it is judged (mapped)
- **Creativity** — validation-first, not OCR-first: the app reasons about accounting logic.
- **Technical ambition** — vision extraction + arithmetic verification + template memory +
  API posting, in one pipeline.
- **Whether it works** — live demo on real documents from a real business, with a working
  export and a working API target.

## Status / next 5 days
- [x] Validation engine (VAT, totals, advances, rounding) — working on real invoices
- [x] Batch extraction CLI → CSV
- [ ] Photo upload web UI (single page, drag & drop)
- [ ] Meta vision integration behind a provider switch (Muse/SAM via Meta Model API)
- [ ] Vendor template memory
- [ ] Demo video (2 min) + submission write-up

## Privacy
Documents stay on the user's own server. No third-party storage; the vision provider is
called only for the page being extracted, and can be swapped for a local open-weights model.

---

## Official rules (from the hackathon #faqs, 30 Oct)

**Submission must include**
- A **3-minute demo video** (max)
- A link to a **public repository**
- Names of all contributors
- Deadline: **4 Nov 2026, 9:00 PM ET** (= 5 Nov, 5:00 AM Bahrain) — late entries rejected

**Judging — four criteria, equal weight**
1. Impact
2. Use of Meta AI tooling
3. Technical depth and execution
4. Demo quality
Top five per track reach the finals.

**Disqualifiers / discouraged**
- Generic projects: basic RAG apps, simple chatbots, personality analyzers
- Politics or war content; anything against legal/ethical policy

**Bonus awards** (5k–20k): Most Viral Project and Community Vote — these depend on **X (Twitter) engagement through 8 Nov**, so the project must be posted publicly on X.

**Credits**
- $150 Meta Model API credits — claim by 4 Nov; card required; watch usage beyond the credit
- Baseten: $100 + 2M tokens/min (up to 2,000 participants) — model id `meta-models/muse-spark-open-1026`
- Modal: $100 (first 1,000 participants); Fireworks TBC

**Support**: `#office-hours` weekdays 10–11 AM ET; tag @CV for general questions.
