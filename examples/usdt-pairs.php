<?php
require '../vendor/autoload.php';

// This bot will trade USDT to the other pairs and back to make more USDT
// It shall use all the trading pairs to make more UDST except the ones we tell it not to use

$strend = 100;            // Short Term Trend - must be less than $mtrend
$mtrend = 180;            // Medium Term Trend - must be less than $ltrend
$ltrend = 280;            // Long Term Trend
$tradefile = "USDT.txt";  // The Trade Logging file name
$minspread = 0.6;         // The minimum spread percentage needed for a trade
$minrsi = 46;             // Relative Strength must be below this number to buy

// Do not change any of the flags, we use this to signal the bot what to do and when
$buyready = 0;            // This flag signals the bot that the pair meets rules to buy
$buyprep = 1;             // This flag signals the bot to prepare to buy
$buyord = 2;              // This flag signals the bot to place an order
$sellok = 3;              // This flag signals the bot that the order was completed
$sellready = 4;           // This flag signals the bot to sell
$selldone = 5;            // This flag signals the bot the trade completed
$dontbuy = 6;             // This flag signals the bot we dont want to trade BCASH :P

// Standard variables and arrays we use
$i = 0;
$binance_prices = array();
$time_start = time();
$time_end = 0;
$run_time = 0;
$rpc = 0;
$tpc = 0;
$q = 0;

// API call to fetch pricing data from Binance
function getprices()
{
  $api = new Binance\API("<api key>","<secret>");
  $mp = $api->prices();
  return $mp;
}

// Start of the Loop - can run for months - press CTRL-C to stop
for($i = 0; $i <= 2000000; $i++)
{
  $time_end = time();
  $run_time = round((($time_end - $time_start)/60),2);
  print "====================================\n";
  print "Iteration = $i \n";
  print "Running Time: $run_time mins \n";
  print "Current running percentage = $rpc \n";
  print "====================================\n";

// Fetch current prices from Binance
  $binance_prices = getprices();

// Loop through the price data as key and value pairs
  foreach($binance_prices as $key => $value)
  {

// Only process pairs with USDT
    if(strpos($key, "USDT"))
    {

// Convert the pair name to lower case in varibale $tick
// for example the name "BTCUSDT" will become "btcusdt"

      $tick = strtolower($key);

// For the first iteration, create arrays and varibales for the bot
      if($i == 0)
      {

// Use the lower case name to form the leading part of varibales and arrays
// for exmaple, using "btcusdt" and adding "st" for the short term array
// will initialise an array called "btcusdtst"
// as we loop thorugh the pairs, each one gets created for each pair
// for exmaple "NEOUSDT" will become "neousdtst"

        ${$tick . "st"} = array();         // Short Term Array
        ${$tick . "mt"} = array();         // Medium Term Array
        ${$tick . "lt"} = array();         // Long Term Array
        ${$tick . "stavg"} = 0;            // Short Term Moving Average
        ${$tick . "mtavg"} = 0;            // Medium Term Moving Average
        ${$tick . "ltavg"} = 0;            // Long Term Moving Average
        ${$tick . "strend"} = 0;           // Short Term Moving Trend
        ${$tick . "mtrend"} = 0;           // Medium Term Moving Trend
        ${$tick . "ltrend"} = 0;           // Long Term Moving Trend
        ${$tick . "mspread"} = 0;          // Medium Term Spread
        ${$tick . "rsi"} = 0;              // Relative Strength Indicator for this pair
        ${$tick . "buyflag"} = $buyready;  // Set this pair to buyready
        ${$tick . "buyvalue"} = 0;         // record what we buy for on this pair
        ${$tick . "sellvalue"} = 0;        // record what we sell for on this pair
        ${$tick . "isset"} = 1;            // used to signal we are initialised for this pair
      }

// We are not on the first loop anymore, we proceed with processing the data
      else
      {

// Exclude List - these ones we do not trade

        if($key == "BCHABCUSDT") ${$tick . "buyflag"} = $dontbuy;
        if($key == "BCHSVUSDT") ${$tick . "buyflag"} = $dontbuy;
        if($key == "BCCUSDT") ${$tick . "buyflag"} = $dontbuy;
        if($key == "TUSDUSDT") ${$tick . "buyflag"} = $dontbuy;
        if($key == "VENUSDT") ${$tick . "buyflag"} = $dontbuy;
				if($key == "PAXUSDT") ${$tick . "buyflag"} = $dontbuy;

// Check if the trading pair has been initialised
// this covers if Binance add a new trading pair on USDT while we are running
// if Binance adds new trading pairs while bot is running, we shall
// ignore them and only use the ones since the bot was started and initialised

        if(isset(${$tick . "isset"}))
        {

// Push data into arrays and shift arrays once we have enough data
          array_push(${$tick . "st"}, $value);
          array_push(${$tick . "mt"}, $value);
          array_push(${$tick . "lt"}, $value);
          if($i > $strend) array_shift(${$tick . "st"});
          if($i > $mtrend) array_shift(${$tick . "mt"});
          if($i > $ltrend) array_shift(${$tick . "lt"});

// Wait until we have all the arrays populated with data
          if($i <= $ltrend)
          {
            printf("%-9s",$key);
            print "\t:Loading Arrays with data\n";
          }

// Arrays are populated, so on with the processing
          else
          {

// Calculate the Moving Average for the 3 arrays
            ${$tick . "stavg"} = round((array_sum(${$tick . "st"})/$strend),8);
            ${$tick . "mtavg"} = round((array_sum(${$tick . "mt"})/$mtrend),8);
            ${$tick . "ltavg"} = round((array_sum(${$tick . "lt"})/$ltrend),8);

// Check if the Short Term Trend is trending down, flat or up
// We use the current price to see if it is above or below the short term moving average
// We use "1" to signal it is trending down, "2" for flat, "3" for trending up
            if($value < ${$tick . "stavg"}) ${$tick . "strend"} = 1;
            if($value == ${$tick . "stavg"}) ${$tick . "strend"} = 2;
            if($value > ${$tick . "stavg"}) ${$tick . "strend"} = 3;

// Check if the Medium Term Trend is trending down, flat or up
// We use the short term moving average to see if it is above or below the medium term moving average
// We use "1" to signal it is trending down, "2" for flat, "3" for trending up
            if(${$tick . "stavg"} < ${$tick . "mtavg"}) ${$tick . "mtrend"} = 1;
            if(${$tick . "stavg"} == ${$tick . "mtavg"}) ${$tick . "mtrend"} = 2;
            if(${$tick . "stavg"} > ${$tick . "mtavg"}) ${$tick . "mtrend"} = 3;

// Check if the Long Term Trend is trending down, flat or up
// We use the medium term moving average to see if it is above or below the long term moving average
// We use "1" to signal it is trending down, "2" for flat, "3" for trending up
            if(${$tick . "mtavg"} < ${$tick . "ltavg"}) ${$tick . "ltrend"} = 1;
            if(${$tick . "mtavg"} == ${$tick . "ltavg"}) ${$tick . "ltrend"} = 2;
            if(${$tick . "mtavg"} > ${$tick . "ltavg"}) ${$tick . "ltrend"} = 3;

// Calculate the Medium Term spread, which is the percentage difference between
// the highest recorded price and the lowest recorded price in the Medium Term Array
            $mlow = min(${$tick . "mt"});
            $mhigh = max(${$tick . "mt"});
            ${$tick . "mspread"} = round(((1-($mlow/$mhigh))*100),3);

// Calculate the Relative Strength Indicator on the Long Term Array
// A Low RSI indicates a buy opportunity
            $rsitck = 0;
            ${$tick . "gain"} = array();
            ${$tick . "loss"} = array();
            foreach(${$tick . "lt"} as $cdaval)
            {
              if($rsitck == 0)
              {
                $cdagain = 0;
                $cdaloss = 0;
              }
              else
              {
                if($cdaval == $cdaprev)
                {
                  $cdagain = 0;
                  $cdaloss = 0;
                }
                elseif($cdaval > $cdaprev)
                {
                  $cdacalc = $cdaval - $cdaprev;
                  $cdagain = number_format($cdacalc,8);
                  $cdaloss = 0;
                }
                else
                {
                  $cdacalc = $cdaprev - $cdaval;
                  $cdaloss = number_format($cdacalc,8);
                  $cdagain = 0;
                }
              }
              array_push(${$tick . "gain"}, $cdagain);
              array_push(${$tick . "loss"}, $cdaloss);
              $cdaprev = $cdaval;
              $rsitck++;
            }
            $cdarsgain = (array_sum(${$tick . "gain"})) / $ltrend;
            $cdarsloss = (array_sum(${$tick . "loss"})) / $ltrend;
            if($cdarsloss > 0)
            {
              ${$tick . "rsi"} = round(100-(100/(1+($cdarsgain/$cdarsloss))),3);
              if(${$tick . "rsi"} == 0) ${$tick . "rsi"} = 0;
            }
            else
            {
              ${$tick . "rsi"} = 100;
            }

// Print out what we have so far so we can see what is going on
            printf("%-9s",$key);
            print "\tV:";
            printf("%-14.8F",$value);
            print "\t  ST:";
            printf("%-14.8F",${$tick . "stavg"});
            if(${$tick . "strend"} == 1) printf("%-5s",":DOWN");
            if(${$tick . "strend"} == 2) printf("%-5s",":FLAT");
            if(${$tick . "strend"} == 3) printf("%-5s",":UP");
            print "\t  MT:";
            printf("%-14.8F",${$tick . "mtavg"});
            if(${$tick . "mtrend"} == 1) printf("%-5s",":DOWN");
            if(${$tick . "mtrend"} == 2) printf("%-5s",":FLAT");
            if(${$tick . "mtrend"} == 3) printf("%-5s",":UP");
            print "\t  LT:";
            printf("%-14.8F",${$tick . "ltavg"});
            if(${$tick . "ltrend"} == 1) printf("%-5s",":DOWN");
            if(${$tick . "ltrend"} == 2) printf("%-5s",":FLAT");
            if(${$tick . "ltrend"} == 3) printf("%-5s",":UP");
            print "\t  SPREAD:";
            printf("%-03.3F",${$tick . "mspread"});
            print "%\t  RSI:";
						printf("%-06.3F",${$tick . "rsi"});
						if(${$tick . "buyflag"} == $buyready) $cdastatus = "Buy Ready";
						if(${$tick . "buyflag"} == $buyprep) $cdastatus = "Buy Prep";
						if(${$tick . "buyflag"} == $buyord) $cdastatus = "Buy Order";
						if(${$tick . "buyflag"} == $sellok) $cdastatus = "Sell OK";
						if(${$tick . "buyflag"} == $sellready) $cdastatus = "Sell Ready";
						if(${$tick . "buyflag"} == $selldone) $cdastatus = "Sell Done";
						if(${$tick . "buyflag"} == $dontbuy) $cdastatus = "Dont Trade";
						print "\tS:$cdastatus \n";

// Trading rules start here
// ========================

// CDA is trending up so set to buyprep
            if(${$tick . "buyflag"} == $buyready AND ${$tick . "strend"}==3 AND ${$tick . "mtrend"}==3 AND ${$tick . "ltrend"}==3)
            {
              printf("%-9s",$key);
              print "Was Buyready, now Buyprep V:$value\n";
              ${$tick . "buyflag"} = $buyprep;
            }

// CDA was buyprep, now trending down, set to buyord if reasonable spread
            if(${$tick . "buyflag"} == $buyprep AND ${$tick . "strend"}==1 AND ${$tick . "mtrend"}==1 AND ${$tick . "ltrend"}==1 AND ${$tick . "mspread"} >= $minspread)
            {
              printf("%-9s",$key);
              print "Was Buyprep, now Buyord V:$value\n";
              ${$tick . "buyflag"} = $buyord;
            }

// CDA stopped trending down and is ready to buy
            if(${$tick . "buyflag"} == $buyord AND ${$tick . "strend"}!=1 AND ${$tick . "rsi"} <= $minrsi)
            {
              printf("%-9s",$key);
              print "Was Buyord, now Buy V:$value\n";
// Assume we buy at the current value
              ${$tick . "buyvalue"} = $value;
              ${$tick . "buyflag"} = $sellok;
							$fh = fopen($tradefile, "a") or die("Cannot open file");
              fwrite($fh, "========================== \n");
              fwrite($fh, "Runtime $run_time \n");
              fwrite($fh, "Buy on $key BV:${$tick . "buyvalue"} \n");
              fwrite($fh, "========================== \n");
              fclose($fh);
            }

// Buy Order on CDA placed, do order tracking here to make sure order completes
            if(${$tick . "buyflag"} == $sellok)
            {
// Since we are not placing an order, we just assume it completed
              ${$tick . "buyflag"} = $sellready;
            }

// CDA is sellready and is no longer trending upwards - time to sell
            if(${$tick . "buyflag"} == $sellready AND ${$tick . "strend"}!=3 AND ${$tick . "mtrend"}!=3)
            {
// Assume we sell at the current value
              ${$tick . "sellvalue"} = $value;
              ${$tick . "buyflag"} = $selldone;
		        }

// CDA is selldone
            if(${$tick . "buyflag"} == $selldone)
            {
// Sell Order on CDA placed, do order tracking here to make sure order completes
// Since we are not placing an order, we just assume it completed
              $q = round((((${$tick . "sellvalue"} - ${$tick . "buyvalue"})/${$tick . "buyvalue"})*100),3);
              $tpc = $q - 0.2;
              $rpc = $rpc + $tpc;
              ${$tick . "buyflag"} = $buyready;
              printf("%-9s",$key);
              print "Sell Done BV:${$tick . "buyvalue"} SV:${$tick . "sellvalue"} TPC:$tpc \n";
              $fh = fopen($tradefile, "a") or die("Cannot open file");
              fwrite($fh, "========================== \n");
              fwrite($fh, "Runtime $run_time \n");
              fwrite($fh, "Sell Done on $key BV:${$tick . "buyvalue"} SV:${$tick . "sellvalue"} TPC:$tpc \n");
              fwrite($fh, "========================== \n");
              fclose($fh);
						}
          }
        }
      }
    }
  }
  sleep(5);
}
?>
