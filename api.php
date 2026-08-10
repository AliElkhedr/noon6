<?php
/**
 * Backend API for Student Results System (Noon Project)
 * 
 * تطوير: علي الخضر (Ali El-Khedr)
 * الموقع الرسمي: https://alielkhedr.com/
 * ترخيص الاستخدام: MIT License (مشروع مفتوح المصدر لدعم المدارس والتعليم)
 * 
 * مميزات السكريبت:
 * 1. دعم التوليد المباشر من Google Sheets وسرعة استجابة فائقة بخادم كاش محلي.
 * 2. بحث محصور في العمود الأول وتوقف فوري بمجرد العثور على طالب لضمان أقصى أداء.
 * 3. حماية كاملة للملفات والدرجات من أدوات المطورين F12.
 */

ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// =========================================================================
//  معرف جدول بيانات جوجل أو الرابط المنشور 2PACX...
// =========================================================================
$SPREADSHEET_ID = "ضع_معرف_شيت_جوجل_هنا";

$action = $_GET['action'] ?? '';

if ($action === 'getSheets') {
    $sheetsMap = getSheetsCached($SPREADSHEET_ID);
    $sheetNames = array_keys($sheetsMap);
    if (empty($sheetNames)) {
        $sheetNames = ["الصف الأول"];
    }
    echo json_encode(array_values($sheetNames), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search') {
    $studentId = trim($_GET['id'] ?? '');
    $sheetName = trim($_GET['sheetName'] ?? '');

    if (empty($studentId)) {
        echo json_encode(['error' => 'الرجاء إدخال رقم الطالب / الجلوس.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($SPREADSHEET_ID) || $SPREADSHEET_ID === "ضع_معرف_جدول_البيانات_هنا") {
        echo json_encode(['error' => 'لم يتم ضبط معرف جدول البيانات SPREADSHEET_ID في ملف api.php'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // جلب خريطة الشيتات من الكاش المحلي
    $sheetsMap = getSheetsCached($SPREADSHEET_ID);

    $gid = "0";
    if (!empty($sheetName) && isset($sheetsMap[$sheetName])) {
        $gid = $sheetsMap[$sheetName];
    } else if (!empty($sheetsMap)) {
        $gid = reset($sheetsMap);
    }

    // جلب محتوى CSV عبر الكاش الفائق السرعة
    $csvData = getCsvCached($SPREADSHEET_ID, $gid);

    if ($csvData === false || empty($csvData) || !isValidCsv($csvData)) {
        echo json_encode(['error' => 'تعذر جلب بيانات هذه الورقة من جوجل. تأكد من إعدادات النشر على الويب للجدول بأكمله.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // إزالة UTF-8 BOM
    $csvData = preg_replace('/^\xEF\xBB\xBF/', '', $csvData);

    $lines = explode("\n", str_replace("\r", "", $csvData));
    if (count($lines) < 2) {
        echo json_encode(['message' => 'لا توجد بيانات في الورقة المحددة.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1. عناوين الصف الأول
    $rawHeaders = str_getcsv(array_shift($lines));
    $headers = array_map('trim', $rawHeaders);

    $foundStudent = null;

    // 2. البحث المحصور في العمود الأول فقط row[0]
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $row = str_getcsv($line);
        if (empty($row)) continue;

        $firstColumnValue = isset($row[0]) ? trim($row[0]) : '';

        if ($firstColumnValue === $studentId) {
            $foundStudent = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') {
                    $foundStudent[$header] = isset($row[$index]) ? trim($row[$index]) : '';
                }
            }
            break; // التوقف الفوري المباشر
        }
    }

    if ($foundStudent) {
        echo json_encode($foundStudent, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['message' => 'لم يتم العثور على نتيجة لهذا الرقم.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['error' => 'إجراء غير صالح.'], JSON_UNESCAPED_UNICODE);

/**
 * دالة التحقق من أن البيانات التي تم جلبها هي CSV حقيقي وليست صفحة خطأ HTML من جوجل
 */
function isValidCsv($data) {
    if (empty($data)) return false;
    $trim = ltrim($data);
    if (strpos($trim, '<!DOCTYPE') === 0 || strpos($trim, '<html') === 0 || strpos($trim, '<HTML') === 0 || strpos($trim, 'errorMessage') !== false) {
        return false;
    }
    return true;
}

/**
 * دالة الكاش الفائق لبيانات الـ CSV
 */
function getCsvCached($spreadsheetInput, $gid = "0") {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $cacheFile = $cacheDir . '/csv_' . md5($spreadsheetInput) . '_' . $gid . '.csv';
    $cacheLifetime = 30; // 30 ثانية

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheLifetime) {
        $content = @file_get_contents($cacheFile);
        if (!empty($content) && isValidCsv($content)) {
            return $content;
        }
    }

    $csvUrl = buildCsvUrl($spreadsheetInput, $gid);
    $csvData = fetchUrlFast($csvUrl);

    // إذا فشل الرابط الأول أو أعاد HTML، نجرّب الرابط البديل
    if ($csvData === false || empty($csvData) || !isValidCsv($csvData)) {
        $fallbackUrl = "https://docs.google.com/spreadsheets/d/{$spreadsheetInput}/export?format=csv&gid={$gid}";
        $fallbackData = fetchUrlFast($fallbackUrl);
        if ($fallbackData !== false && isValidCsv($fallbackData)) {
            $csvData = $fallbackData;
        }
    }

    if ($csvData !== false && !empty($csvData) && isValidCsv($csvData)) {
        @file_put_contents($cacheFile, $csvData);
    }

    return $csvData;
}

/**
 * دالة الكاش المحلي لأسماء الشيتات
 */
function getSheetsCached($spreadsheetInput) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }

    $cacheFile = $cacheDir . '/sheets_' . md5($spreadsheetInput) . '.json';
    $cacheLifetime = 3600;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheLifetime) {
        $content = @file_get_contents($cacheFile);
        $data = json_decode($content, true);
        if (is_array($data) && !empty($data)) {
            return $data;
        }
    }

    $sheets = getSheetsFromGoogleDirect($spreadsheetInput);
    if (!empty($sheets)) {
        @file_put_contents($cacheFile, json_encode($sheets, JSON_UNESCAPED_UNICODE));
    }
    return $sheets;
}

function getSheetsFromGoogleDirect($spreadsheetInput) {
    $pubUrl = buildPubHtmlUrl($spreadsheetInput);
    $html = fetchUrlFast($pubUrl);

    $sheets = [];

    if ($html) {
        if (preg_match_all('/<li\s+id="sheet-button-([0-9]+)"[^>]*><a[^>]*>(.*?)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $gid = $m[1];
                $name = trim(strip_tags($m[2]));
                if (!empty($name)) {
                    $sheets[$name] = $gid;
                }
            }
        }

        if (empty($sheets)) {
            if (preg_match_all('/(?:name|caption)\s*:\s*["\']([^"\']+)["\'].*?gid\s*:\s*["\']?([0-9]+)["\']?/i', $html, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $name = trim($m[1]);
                    $gid = $m[2];
                    if (!empty($name)) {
                        $sheets[$name] = $gid;
                    }
                }
            }
        }
    }

    if (empty($sheets)) {
        $sheets["الورقة الرئيسية"] = "0";
    }

    return $sheets;
}

function fetchUrlFast($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        $output = curl_exec($ch);
        curl_close($ch);
        if ($output !== false && strlen($output) > 0) {
            return $output;
        }
    }

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => [
            'timeout' => 8,
            'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nAccept-Encoding: gzip, deflate\r\n"
        ]
    ]);
    $data = @file_get_contents($url, false, $context);
    if ($data !== false && function_exists('gzdecode')) {
        $decompressed = @gzdecode($data);
        if ($decompressed !== false) {
            return $decompressed;
        }
    }
    return $data;
}

function buildPubHtmlUrl($input) {
    $input = trim($input);
    if (strpos($input, '2PACX-') !== false || strpos($input, '/pub') !== false) {
        if (preg_match('/2PACX-[a-zA-Z0-9_-]+/', $input, $matches)) {
            return "https://docs.google.com/spreadsheets/d/e/{$matches[0]}/pubhtml";
        }
    }
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $input, $matches)) {
        return "https://docs.google.com/spreadsheets/d/{$matches[1]}/pubhtml";
    }
    if (strpos($input, '2PACX-') === 0) {
        return "https://docs.google.com/spreadsheets/d/e/{$input}/pubhtml";
    }
    return "https://docs.google.com/spreadsheets/d/{$input}/pubhtml";
}

function buildCsvUrl($input, $gid = "0") {
    $input = trim($input);
    if (strpos($input, '2PACX-') !== false || strpos($input, '/pub') !== false) {
        if (preg_match('/2PACX-[a-zA-Z0-9_-]+/', $input, $matches)) {
            $pubId = $matches[0];
            return "https://docs.google.com/spreadsheets/d/e/{$pubId}/pub?gid={$gid}&single=true&output=csv";
        }
    }
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $input, $matches)) {
        $sheetId = $matches[1];
        return "https://docs.google.com/spreadsheets/d/{$sheetId}/export?format=csv&gid={$gid}";
    }
    if (strpos($input, '2PACX-') === 0) {
        return "https://docs.google.com/spreadsheets/d/e/{$input}/pub?gid={$gid}&single=true&output=csv";
    }
    return "https://docs.google.com/spreadsheets/d/{$input}/export?format=csv&gid={$gid}";
}
