#!/usr/bin/env python3
"""Mask customer names in demo PDFs (true redaction, text removed)."""
import os
import sys
import fitz  # PyMuPDF

SRC_DIR = "/data/.openclaw/workspace/media/inbound/openclaw-staged-53be7d51-79a1-45d6-8a5e-99a2c93b0da9"
OUT_DIR = "/data/.openclaw/workspace/hackathon-ledgerlens/samples/masked"

TARGETS = {
    "CUS0653": [
        "MECHANICAL CONTRACTING & SERVICES COMPANY W.L.L",
        "MECHANICAL CONTRACTING & SERVICES",
        "MECHANICAL CONTRACTING",
        "CUS0653",
    ],
    "GULF_FG": [
        "Gulf Fiberglass & Plastic Factory. W.L.L",
        "Gulf Fiberglass & Plastic Factory",
        "GULF FIBERGLASS & PLASTIC FACTORY",
        "Gulf Fiberglass",
    ],
    "Deposit": [
        "NASIF SAJJAD",
        "NASIF",
        "SAJJAD",
    ],
}

def which(name):
    for key, terms in TARGETS.items():
        if key.lower() in name.lower():
            return key, terms
    return None, []

def main():
    os.makedirs(OUT_DIR, exist_ok=True)
    files = [f for f in os.listdir(SRC_DIR) if f.lower().endswith(".pdf")]
    for f in files:
        key, terms = which(f)
        if not key:
            print("skip", f)
            continue
        src = os.path.join(SRC_DIR, f)
        doc = fitz.open(src)
        hits = 0
        for page in doc:
            for term in terms:
                for rect in page.search_for(term):
                    page.add_redact_annot(rect, fill=(0, 0, 0), text="", align=0)
                    hits += 1
            page.apply_redactions()
        out = os.path.join(OUT_DIR, key + "-masked.pdf")
        doc.save(out, garbage=4, deflate=True)
        doc.close()

        left = ""
        chk = fitz.open(out)
        for p in chk:
            left += p.get_text()
        chk.close()
        leaked = [t for t in terms if t in left]
        print(f"{key}: redactions={hits} -> {os.path.basename(out)} leaked={leaked or 'none'}")

if __name__ == "__main__":
    main()
