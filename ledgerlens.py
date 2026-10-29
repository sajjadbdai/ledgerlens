#!/usr/bin/env python3
"""LedgerLens — extract invoice fields and validate the accounting arithmetic.

Usage:
  python3 ledgerlens.py <invoice.pdf> [--csv out.csv]

Works on text-based PDFs today; photos go through the vision step (next milestone).
"""
import argparse
import csv
import json
import re
import sys

from pypdf import PdfReader

NUM = r"([0-9][0-9,]*\.?[0-9]{0,3})"


def money(s):
    return float(s.replace(",", "")) if s else 0.0


def text_of(path):
    reader = PdfReader(path)
    return "\n".join((p.extract_text() or "") for p in reader.pages)


def grab(pattern, text, flags=re.I):
    m = re.search(pattern, text, flags)
    return m.group(1).strip() if m else ""


def extract(path):
    t = text_of(path)
    flat = re.sub(r"[ \t]+", " ", t)

    inv_no = grab(r"Invoice\s*No\.?\s*:?\s*([A-Z0-9\-/]+)", flat) or grab(r"(?:Invoice|Tax Invoice)\s*(?:No\.?|Number)\s*:?\s*([A-Z0-9\-/]+)", flat)
    date = grab(r"Invoice\s*Date\s*:?\s*([0-9]{1,2}[./-][0-9]{1,2}[./-][0-9]{2,4})", flat)
    customer = grab(r"Bill\s*To\s*:?\s*\n?\s*([^\n]{3,80})", flat)
    po = grab(r"P\.?O\.?\s*(?:No\.?|Number)?\s*[/ ]?Ref\s*:?\s*([A-Za-z0-9\-/ ]{2,40})", flat)

    taxable = money(grab(r"Taxable\s*(?:Amount|Amt)\s*:?\s*" + NUM, flat))
    vat = money(grab(r"(?:VAT|VALUE ADDED TAX)[^0-9]{0,30}" + NUM, flat))
    gross = money(grab(r"(?:Gross\s*Amount|Total\s*Amount|Net\s*Due\s*Amount)\s*:?\s*" + NUM, flat))
    sales = money(grab(r"Sales\s*Amount\s*:?\s*" + NUM, flat))

    checks = []
    if taxable and vat:
        expected = round(taxable * 0.10, 3)
        checks.append({
            "rule": "VAT is 10% of taxable",
            "expected": expected,
            "found": round(vat, 3),
            "ok": abs(expected - vat) <= 0.005,
        })
    if taxable and vat and gross:
        expected = round(taxable + vat, 3)
        checks.append({
            "rule": "Gross = taxable + VAT",
            "expected": expected,
            "found": round(gross, 3),
            "ok": abs(expected - gross) <= 0.005,
        })

    return {
        "file": path,
        "invoice_no": inv_no,
        "date": date,
        "customer": customer,
        "po_ref": po,
        "sales": round(sales, 3),
        "taxable": round(taxable, 3),
        "vat": round(vat, 3),
        "gross": round(gross, 3),
        "checks": checks,
        "status": "ok" if all(c["ok"] for c in checks) and checks else ("no-checks" if not checks else "mismatch"),
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("pdf")
    ap.add_argument("--csv")
    args = ap.parse_args()

    row = extract(args.pdf)
    print(json.dumps(row, indent=1, ensure_ascii=False))

    if args.csv:
        new = not __import__("os").path.exists(args.csv)
        with open(args.csv, "a", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=["file", "invoice_no", "date", "customer", "po_ref", "taxable", "vat", "gross", "status"])
            if new:
                w.writeheader()
            w.writerow({k: row[k] for k in w.fieldnames})
        print("appended to", args.csv)

    return 0 if row["status"] in ("ok", "no-checks") else 1


if __name__ == "__main__":
    sys.exit(main())
