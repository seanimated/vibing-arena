<?php

require_once 'scrape.php';
require_once 'download.php';
require_once 'parser.php';
require_once 'ai.php';
require_once 'database.php';

$pdfs = include 'scrape.php';

foreach($pdfs as $index => $pdfUrl){

    $filename = "tender_" . time() . "_" . $index . ".pdf";

    $localFile = downloadPDF($pdfUrl, $filename);

    $text = extractPDFText($localFile);

    $ai = analyzeTender($text);

    $content = $ai['choices'][0]['message']['content'] ?? '{}';

    $data = json_decode($content, true);

    if(!$data){
        continue;
    }

    $stmt = $pdo->prepare("
        INSERT INTO tenders
        (title, department, closing_date, pdf_url, local_pdf)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $data['title'] ?? '',
        $data['department'] ?? '',
        $data['closing_date'] ?? '',
        $pdfUrl,
        $localFile
    ]);

    echo "Saved tender: " . ($data['title'] ?? 'Unknown') . PHP_EOL;

    sleep(rand(2,5));
}
?>
