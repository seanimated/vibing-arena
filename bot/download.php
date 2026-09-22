<?php
function downloadPDF($url, $filename){

    $savePath = __DIR__ . "/downloads/" . $filename;

    $pdf = file_get_contents($url);

    file_put_contents($savePath, $pdf);

    return $savePath;
}
?>
