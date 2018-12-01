<?php
print "Load the Biance API Framework\n";
require 'vendor/autoload.php';

print "Initialize the variables and arrays we use\n";
$i = 0;
$binance_prices = array();
$icxtrend = array();

print "Create a function that gets price data from Binance\n";
function getprices()
{
  $api = new Binance\API("<api key>","<secret>");
  $mp = $api->prices();
  return $mp;
}

print "Starting our Loop for 2000 iterations\n";

for($i = 0; $i <= 2000; $i++)
{
  $binance_prices = getprices();
  foreach($binance_prices as $key => $value)
  {
    if($key == "ICXUSDT")
    {
      if($i < 200)
      {
        print "Iteration number $i in our loop got the price $value\n";
        array_push($icxtrend, $value);
      }
      else
      {
        print "Shift our array up one place and add the new price at $value\n";
        array_shift($icxtrend);
        array_push($icxtrend, $value);
        print "Calculate the moving average rounded to 8 decimal places\n";
        $movingavg = round((array_sum($icxtrend)/200),8);
        if($value >= $movingavg)
        {
          print "The price $value is equal to or above the moving average price of $movingavg\n";
        }
        else
        {
          print "The price $value is below the moving average price of $movingavg\n";
        }
      }
    }
  }
  sleep(5);
}
?>
