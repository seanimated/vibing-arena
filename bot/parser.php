<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Smalot\PdfParser\Parser;

function extractPDFText($filePath){

    $parser = new Parser();

    $pdf = $parser->parseFile($filePath);

    return $pdf->getText();
}
?>
