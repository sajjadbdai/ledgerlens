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
 "net_due": number|null
}
Rules: numbers only (no currency symbols, no thousands separators, 3 decimals). If a value is absent use null.
Do not invent values. If the document is not an invoice/statement, use doc_type "unknown" and fill what you can.
TXT;

$payload = [
    'contents' => [[
        'parts' => [
            ['inline_data' => ['mime_type' => $mime, 'data' => $data]],
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => ['temperature' => 0, 'response_mime_type' => 'application/json'],
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
