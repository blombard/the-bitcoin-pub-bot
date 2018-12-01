<?php
require '../vendor/autoload.php';

$short_trend = 81;
print "Our short term trend line is $short_trend\n";
$long_trend = 151;
print "Our long term trend line is $long_trend\n";

$i = 0;
$binance_prices = array();
$icx_shorttrend = array();
$icx_longtrend = array();
$icx_shortavg = 0;
$icx_longavg = 0;
$time_start = time();
$time_end = 0;
$run_time = 0;
$tradefile = "ICX1.txt";
$icx = 1;
$buyvalue = 0;
$sellvalue = 0;
$rpc = 0;
$tpc = 0;

function getprices()
{
  $api = new Binance\API("<api key>","<secret>");
  $mp = $api->prices();
  return $mp;
}

for($i = 0; $i <= 2000000; $i++)
{
  $time_end = time();
  $run_time = round((($time_end - $time_start)/60),2);
  print "====================================\n";
  print "Iteration = $i \n";
  print "Running Time: $run_time mins \n";
  print "Current running percentage = $rpc \n";
  print "====================================\n";
  $binance_prices = getprices();
  foreach($binance_prices as $key => $value)
  {
    if($key == "ICXUSDT")
    {
      if($i < $short_trend)
      {
        array_push($icx_shorttrend, $value);
      }
      else
      {
        array_shift($icx_shorttrend);
        array_push($icx_shorttrend, $value);
      }
      if($i < $long_trend)
      {
        array_push($icx_longtrend, $value);
        print "Loading arrays with the price data\n";
      }
      else
      {
        array_shift($icx_longtrend);
        array_push($icx_longtrend, $value);
        $icx_shortavg = round((array_sum($icx_shorttrend)/$short_trend),8);
        $icx_longavg = round((array_sum($icx_longtrend)/$long_trend),8);
        if($icx_shortavg > $icx_longavg)
        {
          if($icx == 0)
          {
            print "Buying ICX with USDT at $value\n";
            $fh = fopen($tradefile, "a") or die("Cannot open file");
            fwrite($fh, "========================== \n");
            fwrite($fh, "Runtime $run_time \n");
            fwrite($fh, "Buy ICX at $value \n");
            fwrite($fh, "========================== \n");
            fclose($fh);
            $buyvalue = $value;
            $icx = 1;
          }
          print "Holding ICX as it trends up against USDT\n";
          print "Current ICX price is   : $value \n";
          print "Short Moving Average is: $icx_shortavg\n";
          print "Long Moving average is : $icx_longavg\n";
        }
        if($icx_shortavg < $icx_longavg)
        {
          if($icx == 1)
          {
            print "Selling ICX for USDT at $value\n";
            $fh = fopen($tradefile, "a") or die("Cannot open file");
            fwrite($fh, "========================== \n");
            fwrite($fh, "Runtime $run_time \n");
            fwrite($fh, "Sell ICX at $value \n");
            fwrite($fh, "========================== \n");
            fclose($fh);
            $sellvalue = $value;
            $icx = 0;
          }
          $ctpc = round(((($sellvalue - $value)/$value)*100),3);
          print "Holding USDT as it trends up against ICX\n";
          print "Current ICX price is   : $value \n";
          print "Sold ICX at            : $sellvalue\n";
          print "Current Trade Percent  : $ctpc\n";
          print "Short Moving Average is: $icx_shortavg\n";
          print "Long Moving average is : $icx_longavg\n";
        }
        if($icx == 1)
        {
          if($buyvalue != 0 AND $sellvalue != 0)
          {
            $tpc = round(((($sellvalue - $buyvalue)/$buyvalue)*100),3);
            $rpc = $rpc + ($tpc - 0.2);
            $fh = fopen($tradefile, "a") or die("Cannot open file");
            fwrite($fh, "========================== \n");
            fwrite($fh, "Runtime $run_time \n");
            fwrite($fh, "TPC = $tpc : RPC = $rpc : Trade Completed\n");
            fwrite($fh, "========================== \n");
            fclose($fh);
            $buyvalue = 0;
            $sellvalue = 0;
          }
        }
      }
    }
  }
  sleep(5);
}
?>
