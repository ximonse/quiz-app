<?php
/**
 * Excel-import för flashcards med bildstöd
 *
 * Hanterar .xlsx-filer där bilder är inklistrade i celler.
 * Format: Kolumn A = Begrepp (front), Kolumn B = Definition (back), Kolumn C = Bild (optional)
 */

require_once '../config.php';
requireTeacher();

header('Content-Type: application/json');
requireValidCsrf(true);

$teacher_id = getCurrentTeacherID();
$teacher_name = $_SESSION['teacher_name'] ?? 'Lärare';

// Definiera flashcards-fil
if (!defined('FLASHCARDS_FILE')) {
    define('FLASHCARDS_FILE', DATA_DIR . 'flashcards.json');
}

/**
 * Extraherar text från sharedStrings.xml
 */
function getSharedStrings($zip) {
    $strings = [];
    $content = $zip->getFromName('xl/sharedStrings.xml');
    if (!$content) return $strings;

    $xml = simplexml_load_string($content);
    if (!$xml) return $strings;

    foreach ($xml->si as $si) {
        // Hantera både enkla strängar och formaterade strängar
        if (isset($si->t)) {
            $strings[] = (string)$si->t;
        } elseif (isset($si->r)) {
            // Formaterad text med flera runs
            $text = '';
            foreach ($si->r as $r) {
                $text .= (string)$r->t;
            }
            $strings[] = $text;
        } else {
            $strings[] = '';
        }
    }

    return $strings;
}

/**
 * Hämtar bild-mappning från richData
 * Returnerar array: [cellMetadataIndex => imagePath]
 */
function getImageMapping($zip) {
    $mapping = [];

    // Läs richValueRel för att få bild-relationer
    $relsContent = $zip->getFromName('xl/richData/_rels/richValueRel.xml.rels');
    if (!$relsContent) return $mapping;

    $relsXml = simplexml_load_string($relsContent);
    if (!$relsXml) return $mapping;

    // Skapa mapping: rId -> imagePath
    $relIdToImage = [];
    foreach ($relsXml->Relationship as $rel) {
        $id = (string)$rel['Id'];
        $target = (string)$rel['Target'];
        // Target är t.ex. "../media/image1.png", vi vill ha "xl/media/image1.png"
        $imagePath = 'xl/media/' . basename($target);
        $relIdToImage[$id] = $imagePath;
    }

    // Läs richValueRel.xml för att få index-ordning
    $richValueRelContent = $zip->getFromName('xl/richData/richValueRel.xml');
    if (!$richValueRelContent) return $mapping;

    $richValueRelXml = simplexml_load_string($richValueRelContent);
    if (!$richValueRelXml) return $mapping;

    // Namespace-hantering
    $namespaces = $richValueRelXml->getNamespaces(true);
    $rNs = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $index = 0;
    foreach ($richValueRelXml->rel as $rel) {
        $attrs = $rel->attributes($rNs);
        $rId = (string)$attrs['id'];
        if (isset($relIdToImage[$rId])) {
            $mapping[$index] = $relIdToImage[$rId];
        }
        $index++;
    }

    return $mapping;
}

/**
 * Läser celldata från sheet
 * Returnerar array med rader: [['A' => 'text', 'B' => 'text', 'C' => imageIndex], ...]
 */
function readSheetData($zip, $sharedStrings) {
    $rows = [];

    $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
    if (!$sheetContent) return $rows;

    $xml = simplexml_load_string($sheetContent);
    if (!$xml) return $rows;

    foreach ($xml->sheetData->row as $row) {
        $rowNum = (int)$row['r'];
        $rowData = [];

        foreach ($row->c as $cell) {
            $cellRef = (string)$cell['r'];
            // Extrahera kolumnbokstav (A, B, C, etc.)
            preg_match('/^([A-Z]+)/', $cellRef, $matches);
            $col = $matches[1] ?? '';

            $type = (string)$cell['t'];
            $vm = (string)$cell['vm']; // Value metadata (för bilder)

            if ($vm !== '') {
                // Detta är en bildcell
                $rowData[$col] = ['type' => 'image', 'vm' => (int)$vm - 1]; // vm är 1-baserat
            } elseif ($type === 's') {
                // Shared string
                $stringIndex = (int)$cell->v;
                $rowData[$col] = ['type' => 'text', 'value' => $sharedStrings[$stringIndex] ?? ''];
            } elseif ($type === 'str' || $type === 'inlineStr') {
                // Inline string
                $rowData[$col] = ['type' => 'text', 'value' => (string)$cell->v];
            } else {
                // Numeriskt eller annat värde
                $rowData[$col] = ['type' => 'text', 'value' => (string)$cell->v];
            }
        }

        $rows[$rowNum] = $rowData;
    }

    return $rows;
}

/**
 * Sparar en bild från xlsx till data/images/
 */
function saveImageFromXlsx($zip, $imagePath) {
    $imageData = $zip->getFromName($imagePath);
    if (!$imageData) return null;

    // Skapa images-mapp om den inte finns
    if (!file_exists(IMAGE_DIR)) {
        mkdir(IMAGE_DIR, 0755, true);
    }

    // Bestäm filtyp
    $extension = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $extension = 'png'; // Default
    }

    // Generera unikt filnamn
    $filename = generateID('img_') . '.' . $extension;
    $filepath = IMAGE_DIR . $filename;

    if (file_put_contents($filepath, $imageData)) {
        return $filename;
    }

    return null;
}

// Huvudlogik
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Endast POST tillåtet']);
    exit;
}

if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
    $errorMsg = 'Ingen fil uppladdad';
    if (isset($_FILES['excel_file'])) {
        $errorMsg .= ' (Error code: ' . $_FILES['excel_file']['error'] . ')';
    }
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$file = $_FILES['excel_file'];
$title = trim($_POST['title'] ?? '');
$language = $_POST['language'] ?? 'sv';
$subject = trim($_POST['subject'] ?? '');
$grade = trim($_POST['grade'] ?? '');
$tags = trim($_POST['tags'] ?? '');
$image_side = $_POST['image_side'] ?? 'front';

if (!$title) {
    echo json_encode(['success' => false, 'error' => 'Du måste ange en titel']);
    exit;
}

// Validera filtyp
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    echo json_encode(['success' => false, 'error' => 'Endast .xlsx-filer stöds']);
    exit;
}

// Öppna xlsx som zip
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    echo json_encode(['success' => false, 'error' => 'Kunde inte öppna Excel-filen']);
    exit;
}

try {
    // Extrahera data
    $sharedStrings = getSharedStrings($zip);
    $imageMapping = getImageMapping($zip);
    $sheetData = readSheetData($zip, $sharedStrings);

    // Bygg flashcards
    $cards = [];
    $skippedRows = 0;

    foreach ($sheetData as $rowNum => $row) {
        // Hoppa över första raden (rubrikrad)
        if ($rowNum === 1) continue;

        // Hämta front (kolumn A) och back (kolumn B)
        $front = '';
        $back = '';
        $imageFilename = null;

        if (isset($row['A']) && $row['A']['type'] === 'text') {
            $front = trim($row['A']['value']);
        }

        if (isset($row['B']) && $row['B']['type'] === 'text') {
            $back = trim($row['B']['value']);
        }

        // Hoppa över rader utan front eller back
        if (empty($front) || empty($back)) {
            $skippedRows++;
            continue;
        }

        // Kolla om det finns en bild i kolumn C
        if (isset($row['C']) && $row['C']['type'] === 'image') {
            $vmIndex = $row['C']['vm'];
            if (isset($imageMapping[$vmIndex])) {
                $imagePath = $imageMapping[$vmIndex];
                $imageFilename = saveImageFromXlsx($zip, $imagePath);
            }
        }

        $card = [
            'front' => $front,
            'back' => $back
        ];

        if ($imageFilename) {
            $card['image'] = $imageFilename;
            $card['image_side'] = $image_side;
        }

        $cards[] = $card;
    }

    $zip->close();

    if (count($cards) === 0) {
        echo json_encode(['success' => false, 'error' => 'Inga giltiga kort hittades i Excel-filen. Kontrollera att kolumn A (Begrepp) och B (Definition) innehåller text.']);
        exit;
    }

    // Skapa deck
    $flashcards = readJSON(FLASHCARDS_FILE);
    $stats = readJSON(STATS_FILE);

    $deck_id = generateID('deck_');
    $flashcards[$deck_id] = [
        'id' => $deck_id,
        'title' => $title,
        'type' => 'flashcard',
        'language' => $language,
        'subject' => $subject,
        'grade' => $grade,
        'tags' => $tags,
        'teacher_id' => $teacher_id,
        'teacher_name' => $teacher_name,
        'created' => date('Y-m-d H:i:s'),
        'cards' => $cards
    ];

    writeJSON(FLASHCARDS_FILE, $flashcards);

    // Initiera statistik
    $stats[$deck_id] = [
        'type' => 'flashcard',
        'total_attempts' => 0,
        'completed' => 0,
        'avg_time_seconds' => 0,
        'avg_grade' => 0,
        'attempts' => [],
        'card_difficulty' => []
    ];
    writeJSON(STATS_FILE, $stats);

    // Räkna kort med bilder
    $cardsWithImages = count(array_filter($cards, fn($c) => isset($c['image'])));

    echo json_encode([
        'success' => true,
        'deck_id' => $deck_id,
        'cards_count' => count($cards),
        'cards_with_images' => $cardsWithImages,
        'skipped_rows' => $skippedRows
    ]);

} catch (Exception $e) {
    $zip->close();
    echo json_encode(['success' => false, 'error' => 'Fel vid bearbetning: ' . $e->getMessage()]);
}
