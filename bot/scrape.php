<?php
require_once 'database.php';

$url = "https://www.etenders.gov.za";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");

$html = curl_exec($ch);
curl_close($ch);

$dom = new DOMDocument();
@$dom->loadHTML($html);

$links = $dom->getElementsByTagName('a');

$pdfs = [];

foreach($links as $link){
    $href = $link->getAttribute('href');

    if(str_contains($href, '.pdf')){
        $pdfs[] = $href;
    }
}

return $pdfs;
?>
