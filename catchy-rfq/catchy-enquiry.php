<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function reject(int $status, string $message): never {
    reply($status, ['ok' => false, 'error' => $message]);
}
function iniBytes(string $value): int {
    $value = trim($value);
    $number = (float)$value;
    $unit = strtolower(substr($value, -1));
    $factor = ['k'=>1024, 'm'=>1048576, 'g'=>1073741824][$unit] ?? 1;
    return $number > 0 ? (int)($number * $factor) : PHP_INT_MAX;
}
$totalLimit = min(10 * 1048576, max(0, iniBytes((string)ini_get('post_max_size')) - 65536));
$fileLimit = min(5 * 1048576, iniBytes((string)ini_get('upload_max_filesize')), $totalLimit);
$fileCount = min(5, (int)ini_get('max_file_uploads'));
$limits = ['max_files'=>$fileCount, 'max_file_bytes'=>$fileLimit, 'max_total_bytes'=>$totalLimit];
if ($_SERVER['REQUEST_METHOD'] === 'GET') reply(200, ['ok'=>true, 'limits'=>$limits]);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reject(405, 'Method not allowed.');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $totalLimit + 65536) reject(413, 'Files exceed the total upload limit.');
if (!empty($_POST['website'] ?? '')) reject(400, 'Could not send this enquiry.');
if (($_POST['consent'] ?? '') !== '1') reject(400, 'Please confirm your consent.');

$data = [];
foreach (['name'=>120, 'company'=>160, 'email'=>180, 'phone'=>60, 'message'=>5000] as $key=>$length) {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) reject(400, 'Invalid enquiry details.');
    $value = trim($value);
    if (mb_strlen($value, 'UTF-8') > $length || ($key !== 'message' && preg_match('/[\r\n\x00]/', $value))) reject(400, 'Invalid enquiry details.');
    $data[$key] = $value;
}
if ($data['name'] === '' || $data['phone'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['message'], 'UTF-8') < 10) reject(400, 'Please complete the required fields.');

$uploads = $_FILES['attachments'] ?? null;
$attachments = [];
$total = 0;
$mimeTypes = ['pdf'=>'application/pdf', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'png'=>'image/png', 'dwg'=>'application/acad', 'dxf'=>'application/dxf', 'zip'=>'application/zip'];
if ($uploads !== null) {
    if (!is_array($uploads) || !is_array($uploads['name'] ?? null)) reject(400, 'Invalid file upload.');
    if (count($uploads['name']) > $fileCount) reject(400, 'Maximum '.$fileCount.' files per enquiry.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    foreach ($uploads['name'] as $i=>$originalName) {
        $error = $uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) continue;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) reject(413, 'A file exceeds the maximum file size.');
        if ($error !== UPLOAD_ERR_OK) reject(400, 'A file did not upload completely. Please select it again.');
        $tmp = $uploads['tmp_name'][$i] ?? '';
        if (!is_string($originalName) || !is_string($tmp) || !is_uploaded_file($tmp)) reject(400, 'Invalid file upload.');
        $name = basename(str_replace('\\', '/', $originalName));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset($mimeTypes[$extension])) reject(415, 'Use PDF, JPG, PNG, DWG, DXF or ZIP files.');
        $size = filesize($tmp);
        if ($size === false || $size < 1) reject(400, 'Empty files cannot be attached.');
        if ($size > $fileLimit) reject(413, 'A file exceeds the maximum file size.');
        $total += $size;
        if ($total > $totalLimit) reject(413, 'Files exceed the total upload limit.');
        $signature = file_get_contents($tmp, false, null, 0, 4096);
        $mime = $finfo->file($tmp);
        $valid = match ($extension) {
            'pdf' => str_contains(substr((string)$signature, 0, 1024), '%PDF-'),
            'jpg', 'jpeg' => $mime === 'image/jpeg',
            'png' => $mime === 'image/png',
            'dwg' => preg_match('/^AC10\d{2}/', (string)$signature) === 1,
            'dxf' => str_starts_with((string)$signature, 'AutoCAD Binary DXF') || preg_match('/(?:^|[\r\n])\s*0\s*[\r\n]+\s*SECTION\b/i', (string)$signature) === 1,
            'zip' => str_starts_with((string)$signature, "PK\x03\x04") || str_starts_with((string)$signature, "PK\x05\x06"),
        };
        if (!$valid) reject(415, 'A file does not match its format. Please check the file.');
        $safeName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name);
        $safeName = substr((string)$safeName, 0, 160);
        if ($safeName === '' || $safeName[0] === '.') $safeName = 'attachment-'.($i+1).'.'.$extension;
        $attachments[] = ['path'=>$tmp, 'name'=>$safeName, 'mime'=>$mimeTypes[$extension]];
    }
}

session_start(['cookie_secure'=>true, 'cookie_httponly'=>true, 'cookie_samesite'=>'Lax']);
if (time() - (int)($_SESSION['catchy_last_send'] ?? 0) < 60) reject(429, 'Please wait one minute, then send again.');
$boundary = 'catchy_'.bin2hex(random_bytes(18));
$crlf = "\r\n";
$text = "CATCHY DESIGN — REQUEST FOR QUOTATION\n\n";
$text .= "Contact: ".$data['name']."\nCompany: ".$data['company']."\nEmail: ".$data['email']."\nTelephone: ".$data['phone']."\n\n".$data['message'];
$text .= "\n\nAttachments: ".count($attachments)."\nConsent to quotation request: confirmed\nSubmitted from senbar.sa/catchy-rfq.html\n";
$headers = [
    'From: Catchy Design <website@senbar.sa>',
    'Reply-To: '.$data['email'],
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
];
$body = '--'.$boundary.$crlf.'Content-Type: text/plain; charset=UTF-8'.$crlf.'Content-Transfer-Encoding: base64'.$crlf.$crlf.chunk_split(base64_encode($text), 76, $crlf);
foreach ($attachments as $file) {
    $bytes = file_get_contents($file['path']);
    if ($bytes === false) reject(500, 'Could not read an attachment. Please try again.');
    $body .= '--'.$boundary.$crlf.'Content-Type: '.$file['mime'].'; name="'.$file['name'].'"'.$crlf;
    $body .= 'Content-Disposition: attachment; filename="'.$file['name'].'"'.$crlf.'Content-Transfer-Encoding: base64'.$crlf.$crlf.chunk_split(base64_encode($bytes), 76, $crlf);
}
$body .= '--'.$boundary.'--'.$crlf;
if (!@mail('jurgen@int-senbar.com', '[Catchy Design] Request for quotation', $body, implode($crlf, $headers))) reject(503, 'Could not send your enquiry. Please try again shortly.');
$_SESSION['catchy_last_send'] = time();
reply(200, ['ok'=>true]);
