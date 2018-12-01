<?php
require 'vendor/autoload.php';
function getprices()
{
  $api = new Binance\API("<api key>","<secret>");
  $mp = $api->prices();
  return $mp;
}
$p = getprices();
foreach ($p as $key => $value)
{
  if(strpos($key, "USDT"))
  {
    print "$key : $value\n";
  }
}
?>
