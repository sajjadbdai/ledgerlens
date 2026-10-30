<?php
/**
 * LedgerLens — hosted vision extraction + accounting validation.
 * POST multipart: file (PDF/JPEG/PNG)
 */
header('Content-Type: application/json');

const GEMINI_MODEL = 'gemini-2.5-flash';
$API_KEY = getenv('GEMINI_API_KEY') ?: '__GEMINI_KEY__';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'send a file field named "file"']);
    exit;
}

$tmp = $_FILES['file']['tmp_name'];
$name = $_FILES['file']['name'] ?? 'upload';
$size = filesize($tmp);
if ($size > 15 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['error' => 'file too large (max 15 MB)']);
    exit;
}

$mime = 'application/pdf';
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (in_array($ext, ['jpg', 'jpeg'])) $mime = 'image/jpeg';
elseif ($ext === 'png') $mime = 'image/png';
elseif ($ext === 'webp') $mime = 'image/webp';

$data = base64_encode(file_get_contents($tmp));

$prompt = <<<TXT
You are an accounting document reader. Extract this invoice or statement into strict JSON.
Return ONLY JSON with this shape:
{
 "doc_type": "invoice" | "statement" | "quote" | "unknown",
 "invoice_no": string|null,
 "date": "YYYY-MM-DD"|null,
 "customer": string|null,
 "po_ref": string|null,
 "currency": "BHD"|null,
 "line_items": [{"description": string, "qty": number|null, "unit_price": number|null, "amount": number|null}],
 "subtotal": number|null,
 "discount": number|null,
 "advances": number|null,
 "taxable": number|null,
 "vat_rate": number|null,
 "vat_amount": number|null,
 "total": number|null,
 "net_due": number|null,
 "ageing": {"current": number|null, "0_30": number|null, "30_60": number|null, "60_90": number|null, "above_90": number|null}
}
Rules: numbers only (no currency symbols, no thousands separators, 3 decimals). If a value is absent use null.
CRITICAL for statements: list EVERY invoice row you can see, one object per row, in document order. Do NOT summarise, group, or show only the largest items. If the statement spans several pages, extract the rows from ALL pages. A statement with 48 rows must return 48 line_items.
Do not invent values. If the document is not an invoice/statement, use doc_type "unknown" and fill what you can.
TXT;

$payload = [
    'contents' => [[
        'parts' => [
            ['inline_data' => ['mime_type' => $mime, 'data' => $data]],
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => ['temperature' => 0, 'response_mime_type' => 'application/json', 'maxOutputTokens' => 8192],
];

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . urlencode($API_KEY);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 120,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);

// retry once on transient provider failures
if ($resp === false || $code >= 500 || $code === 429) {
    usleep(1200000);
    $ch2 = curl_init($url);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 120,
    ]);
    $r2 = curl_exec($ch2);
    $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    if ($r2 !== false && $c2 < 400) { $resp = $r2; $code = $c2; }
    curl_close($ch2);
}
curl_close($ch);

if ($resp === false || $code >= 400) {
    http_response_code(502);
    echo json_encode(['error' => 'vision provider failed', 'status' => $code, 'detail' => $err ?: substr((string)$resp, 0, 400)]);
    exit;
}

$out = json_decode($resp, true);
$text = $out['candidates'][0]['content']['parts'][0]['text'] ?? '';
$text = trim(preg_replace('/^```json|```$/m', '', $text));
$doc = json_decode($text, true);

if (!is_array($doc)) {
    http_response_code(502);
    echo json_encode(['error' => 'model did not return JSON', 'raw' => substr($text, 0, 400)]);
    exit;
}

// ---- accounting validation ----
$checks = [];
$num = fn($v) => is_numeric($v) ? (float)$v : null;

$taxable = $num($doc['taxable'] ?? null);
$vat = $num($doc['vat_amount'] ?? null);
$rate = $num($doc['vat_rate'] ?? null);
$total = $num($doc['total'] ?? null);
$net = $num($doc['net_due'] ?? null);
$discount = $num($doc['discount'] ?? null);
$advances = $num($doc['advances'] ?? null);
$lines = $doc['line_items'] ?? [];

if ($rate === null && $taxable && $vat) $rate = 10.0;
// the model may return 0.1 instead of 10 — normalise fractions to percent
if ($rate !== null && $rate > 0 && $rate <= 1) $rate = $rate * 100;
if ($rate !== null && $taxable !== null && $vat !== null) {
    $expected = round($taxable * $rate / 100, 3);
    $checks[] = ['rule' => "VAT = {$rate}% of taxable", 'expected' => $expected, 'found' => round($vat, 3), 'ok' => abs($expected - $vat) <= 0.006];
}
if ($taxable !== null && $total !== null && $vat !== null && $advances === null) {
    $expected = round($taxable + $vat, 3);
    $checks[] = ['rule' => 'Total = taxable + VAT', 'expected' => $expected, 'found' => round($total, 3), 'ok' => abs($expected - $total) <= 0.006];
}
if ($net !== null && $total !== null) {
    $checks[] = ['rule' => 'Net due = total', 'expected' => round($total, 3), 'found' => round($net, 3), 'ok' => abs($net - $total) <= 0.006];
}
$sumLines = 0.0; $lineCount = 0;
foreach ($lines as $l) {
    $amt = $num($l['amount'] ?? null);
    if ($amt !== null) { $sumLines += $amt; $lineCount++; }
}
if ($lineCount && $taxable !== null) {
    $expected = round($sumLines - ($discount ?? 0) - ($advances ?? 0), 3);
    $checks[] = ['rule' => 'Sum of lines − discount − advances = taxable', 'expected' => $expected, 'found' => round($taxable, 3), 'ok' => abs($expected - $taxable) <= 0.01];
}

// flatten ageing buckets if the model returned them nested
if (isset($doc['ageing']) && is_array($doc['ageing'])) {
    $map = ['current' => 'current', '0_30' => 'bucket_0_30', '30_60' => 'bucket_30_60', '60_90' => 'bucket_60_90', 'above_90' => 'above_90'];
    foreach ($map as $src => $dst) {
        if (isset($doc['ageing'][$src]) && $doc['ageing'][$src] !== null) { $doc[$dst] = $doc['ageing'][$src]; }
    }
}

// statement-specific: do the ageing buckets add up to the total outstanding?
if (($doc['doc_type'] ?? '') === 'statement') {
    $buckets = 0.0; $seenBucket = false;
    foreach (['current','bucket_0_30','bucket_30_60','bucket_60_90','above_90'] as $bk) {
        $v = $num($doc[$bk] ?? null);
        if ($v !== null) { $buckets += $v; $seenBucket = true; }
    }
    if ($seenBucket && $net !== null) {
        $checks[] = ['rule' => 'Ageing buckets = total outstanding', 'expected' => round($buckets, 3), 'found' => round($net, 3), 'ok' => abs($buckets - $net) <= 0.01];
    }
    $rows = count($lines);
    $doc['rows_extracted'] = $rows;
}

$status = 'ok';
if (!$checks) $status = 'needs-review';
foreach ($checks as $c) if (!$c['ok']) { $status = 'mismatch'; break; }

echo json_encode([
    'ok' => true,
    'file' => $name,
    'document' => $doc,
    'checks' => $checks,
    'status' => $status,
], JSON_UNESCAPED_UNICODE);
