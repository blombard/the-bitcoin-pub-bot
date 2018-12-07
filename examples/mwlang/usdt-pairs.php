<?php
require 'vendor/autoload.php';

// This bot will trade USDT to the other pairs and back to make more USDT
// It shall use all the trading pairs to make more UDST except the ones we tell it not to use

$init_stperiod = 50;             // Short Term Trend - must be less than $mtperiod
$init_mtperiod = 90;             // Medium Term Trend - must be less than $ltperiod
$init_ltperiod = 120;            // Long Term Trend
$tradefile = "BTC.txt";          // The Trade Logging file name
$minspread = 1.1;                // The minimum spread percentage needed for a trade
$minrsi = 45;                    // Relative Strength must be below this number to buy
$sellbuffer = 1.003;             // Create a buffer to hold CDA if sell is not profitable
$maxorders = 10;                 // Maximum number of concurrent orders

// Do not change any of the flags, we use this to signal the bot what to do and when
$buyready = 0;            // This flag signals the bot that the pair meets rules to buy
$buyprep = 1;             // This flag signals the bot to prepare to buy
$buyord = 2;              // This flag signals the bot to place an order
$sellok = 3;              // This flag signals the bot that the order was completed
$sellready = 4;           // This flag signals the bot to sell
$selldone = 5;            // This flag signals the bot the trade completed
$dontbuy = 6;             // This flag signals the bot we dont want to trade BCASH :P

// Trend Directions
$unset = 0;
$down = 1;
$flat = 2;
$up = 3;

// Standard variables and arrays we use
$replay = 1;
$i = 0;
$binance_prices = array();
$time_start = time();
$time_end = 0;
$run_time = 0;
$rpc = 0;
$tpc = 0;
$q = 0;
$cdaorders = 0;
$btcprice = 0;
$btctrend = array();
$bttrend = "WAIT";

// API call to fetch pricing data from Binance
function getprices()
{
  global $replay;
  global $pricefh;

  if($replay == 0)
  {
    $api = new Binance\API("<api key>","<secret>");
    $mp = $api->prices();
    $pricefh = fopen("BTC-prices.txt","a") or die("Cannot open file");
    fwrite($pricefh, serialize($mp) . "\n");
    fclose($pricefh);
  }
  else
  {
    if (!$pricefh)
    {
      $pricefh = fopen("BTC-prices.txt","r") or die("Cannot open replay file");
    }
    $mp = unserialize(fgets($pricefh));
    if ($mp == NULL) die("end of price data reached.\n");
  }
  return $mp;
}

if ($replay == 1)
{
  $simulation_mode = "replaying";
}
else
{
  $simulation_mode = "real-time";
  if(file_exists("BTC-prices.txt"))
  {
    unlink("BTC-prices.txt");
  }
}

// Start of the Loop - can run for months - press CTRL-C to stop
for($i = 0; $i <= 2000000; $i++)
{
  $time_end = time();
  $run_time = round((($time_end - $time_start)/60),2);
  print "====================================\n";
  print "Iteration = $i ($simulation_mode) \n";
  print "Running Time: $run_time mins \n";
  print "Current BTC Price is: $btcprice T:$bttrend \n";
  print "Current Orders in progress = $cdaorders / $maxorders\n";
  print "Current running percentage = $rpc \n";
  print "====================================\n";

  // Fetch current prices from Binance
  $binance_prices = getprices();

  // Loop through the price data as key and value pairs
  foreach($binance_prices as $key => $value)
  {
    // Track BTC price for display
    if($key == "BTCUSDT")
    {
      $btcprice = $value;
      array_push($btctrend, $value);
      if($i >= ${$tick . "mtperiod"})
      {
        array_shift($btctrend);
        $btcavg = round((array_sum($btctrend)/${$tick . "ltperiod"}),8);
        if($value >= $btcavg)
        {
          $bttrend = "UP";
        }
        else
        {
          $bttrend = "DOWN";
        }
      }
    }
    // Only process pairs with BTC
    if(strpos($key, "BTC"))
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

        ${$tick . "st"} = array();           // Short Term Array
        ${$tick . "mt"} = array();           // Medium Term Array
        ${$tick . "lt"} = array();           // Long Term Array

        ${$tick . "stperiod"} = $init_stperiod; // Short Term Duration
        ${$tick . "mtperiod"} = $init_mtperiod; // Medium Term Duration
        ${$tick . "ltperiod"} = $init_ltperiod; // Long Term Duration

        ${$tick . "stavg"} = 0;              // Short Term Moving Average
        ${$tick . "mtavg"} = 0;              // Medium Term Moving Average
        ${$tick . "ltavg"} = 0;              // Long Term Moving Average
        ${$tick . "stdir"} = $unset;         // Short Term Moving Trend
        ${$tick . "mtdir"} = $unset;         // Medium Term Moving Trend
        ${$tick . "ltdir"} = $unset;         // Long Term Moving Trend
        ${$tick . "lspread"} = 0;            // Long Term Spread
        ${$tick . "strsi"} = 0;              // Short Term Relative Strength Indicator for this pair
        ${$tick . "mtrsi"} = 0;              // Medium Term Relative Strength Indicator for this pair
        ${$tick . "ltrsi"} = 0;              // Long Term Relative Strength Indicator for this pair
        ${$tick . "tradeflag"} = $buyready;  // Set this pair to buyready
        ${$tick . "buyvalue"} = 0;           // record what we buy for on this pair
        ${$tick . "sellvalue"} = 0;          // record what we sell for on this pair
        ${$tick . "lasttrade"} = 0;          // record we have had one trade done
        ${$tick . "lasttpc"} = 0;            // record what percentage last the trade was
        ${$tick . "isset"} = 1;              // used to signal we are initialised for this pair
      }

      // We are not on the first loop anymore, we proceed with processing the data
      else
      {

        // Exclude List - these ones we do not trade

        if($key == "BCHABCBTC") ${$tick . "tradeflag"} = $dontbuy;
        if($key == "BCHSVBTC") ${$tick . "tradeflag"} = $dontbuy;
        if($key == "BCCBTC") ${$tick . "tradeflag"} = $dontbuy;

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
          if($i > ${$tick . "stperiod"}) array_shift(${$tick . "st"});
          if($i > ${$tick . "mtperiod"}) array_shift(${$tick . "mt"});
          if($i > ${$tick . "ltperiod"}) array_shift(${$tick . "lt"});

          // Wait until we have all the arrays populated with data
          if($i <= ${$tick . "ltperiod"})
          {
            if($key == "BCHSVBTC") print "Loading Arrays with data until Iteration ${$tick . "ltperiod"} - patience, Grasshopper...\n";
          }

          // Arrays are populated, so on with the processing
          else
          {

            // Calculate the Moving Average for the 3 arrays
            ${$tick . "stavg"} = round((array_sum(${$tick . "st"})/${$tick . "stperiod"}),8);
            ${$tick . "mtavg"} = round((array_sum(${$tick . "mt"})/${$tick . "mtperiod"}),8);
            ${$tick . "ltavg"} = round((array_sum(${$tick . "lt"})/${$tick . "ltperiod"}),8);

            // Check if the Short Term Trend is trending down, flat or up
            // We use the current price to see if it is above or below the short term moving average
            // We use "1" to signal it is trending down, "2" for flat, "3" for trending up
            if($value < ${$tick . "stavg"}) ${$tick . "stdir"} = $down;
            if($value == ${$tick . "stavg"}) ${$tick . "stdir"} = $flat;
            if($value > ${$tick . "stavg"}) ${$tick . "stdir"} = $up;

            // Check if the Medium Term Trend is trending down, flat or up
            // We use the short term moving average to see if it is above or below the medium term moving average
            // We use "1" to signal it is trending down, "2" for flat, "3" for trending up
            if(${$tick . "stavg"} < ${$tick . "mtavg"}) ${$tick . "mtdir"} = $down;
            if(${$tick . "stavg"} == ${$tick . "mtavg"}) ${$tick . "mtdir"} = $flat;
            if(${$tick . "stavg"} > ${$tick . "mtavg"}) ${$tick . "mtdir"} = $up;

            // Check if the Long Term Trend is trending down, flat or up
            // We use the medium term moving average to see if it is above or below the long term moving average
            // We use "1" to signal it is trending down, "2" for flat, "3" for trending up
            if(${$tick . "mtavg"} < ${$tick . "ltavg"}) ${$tick . "ltdir"} = $down;
            if(${$tick . "mtavg"} == ${$tick . "ltavg"}) ${$tick . "ltdir"} = $flat;
            if(${$tick . "mtavg"} > ${$tick . "ltavg"}) ${$tick . "ltdir"} = $up;

            // Calculate the Medium Term spread, which is the percentage difference between
            // the highest recorded price and the lowest recorded price in the Medium Term Array
            $mlow = min(${$tick . "lt"});
            $mhigh = max(${$tick . "lt"});
            ${$tick . "lspread"} = round(((1-($mlow/$mhigh))*100),3);

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

            // Long Term RSI
            ${$tick . "ltrsi"} = 100;
            $cdarsgain = (array_sum(${$tick . "gain"})) / ${$tick . "ltperiod"};
            $cdarsloss = (array_sum(${$tick . "loss"})) / ${$tick . "ltperiod"};
            if($cdarsloss > 0) ${$tick . "ltrsi"} = round(100-(100/(1+($cdarsgain/$cdarsloss))),3);

            // Medium Term RSI
            ${$tick . "mtrsi"} = 100;
            $gains = array_slice(${$tick . "gain"}, ${$tick . "ltperiod"} - ${$tick . "mtperiod"}, ${$tick . "mtperiod"});
            $losses = array_slice(${$tick . "loss"}, ${$tick . "ltperiod"} - ${$tick . "mtperiod"}, ${$tick . "mtperiod"});
            $cdarsgain = (array_sum($gains) / ${$tick . "mtperiod"});
            $cdarsloss = (array_sum($losses) / ${$tick . "mtperiod"});
            if($cdarsloss > 0) ${$tick . "mtrsi"} = round(100-(100/(1+($cdarsgain/$cdarsloss))),3);

            // Short Term RSI
            ${$tick . "strsi"} = 100;
            $gains = array_slice(${$tick . "gain"}, ${$tick . "ltperiod"} - ${$tick . "stperiod"}, ${$tick . "stperiod"});
            $losses = array_slice(${$tick . "loss"}, ${$tick . "ltperiod"} - ${$tick . "stperiod"}, ${$tick . "stperiod"});
            $cdarsgain = (array_sum($gains) / ${$tick . "stperiod"});
            $cdarsloss = (array_sum($losses) / ${$tick . "stperiod"});
            if($cdarsloss > 0) ${$tick . "strsi"} = round(100-(100/(1+($cdarsgain/$cdarsloss))),3);

            // Print out only ones in Buy Order and Sell Ready
            if(${$tick . "tradeflag"} == $buyord OR ${$tick . "tradeflag"} == $sellready)
            {
              printf("%-9s",$key);
              print "\tV:";
              printf("%-14.8F",$value);
              print "\t  ST:";
              printf("%-14.8F",${$tick . "stavg"});
              if(${$tick . "stdir"} == $down) printf("%-5s",":DOWN");
              if(${$tick . "stdir"} == $flat) printf("%-5s",":FLAT");
              if(${$tick . "stdir"} == $up) printf("%-5s",":UP");
              print "\t  MT:";
              printf("%-14.8F",${$tick . "mtavg"});
              if(${$tick . "mtdir"} == $down) printf("%-5s",":DOWN");
              if(${$tick . "mtdir"} == $flat) printf("%-5s",":FLAT");
              if(${$tick . "mtdir"} == $up) printf("%-5s",":UP");
              print "\t  LT:";
              printf("%-14.8F",${$tick . "ltavg"});
              if(${$tick . "ltdir"} == $down) printf("%-5s",":DOWN");
              if(${$tick . "ltdir"} == $flat) printf("%-5s",":FLAT");
              if(${$tick . "ltdir"} == $up) printf("%-5s",":UP");
              print "\t  SPREAD:";
              printf("%-03.3F",${$tick . "lspread"});
              print "%\t  RSI:";
              printf("%-06.3F",${$tick . "strsi"});
              printf("/%-06.3F",${$tick . "mtrsi"});
              printf("/%-06.3F",${$tick . "ltrsi"});

              if(${$tick . "tradeflag"} == $buyord) $cdastatus = "Buy Order";
              if(${$tick . "tradeflag"} == $sellready) $cdastatus = "Sell Ready";
              if(${$tick . "tradeflag"} == $sellready)
              {
                $ctp = round(((($value - ${$tick . "buyvalue"})/${$tick . "buyvalue"})*100),3);
                print "\t S:$cdastatus \tBV:${$tick . "buyvalue"} CTP:$ctp";
              }
              else
              {
                print "\tS:$cdastatus";
              }
              if(${$tick . "lasttrade"} == 1)
              {
                print "   LastTPC:${$tick . "lasttpc"}";
              }
              print "\n";
            }

            // Trading rules start here
            // ========================

            // CDA is trending up so set to buyprep
            if(${$tick . "tradeflag"} == $buyready AND ${$tick . "stdir"}==$up AND ${$tick . "mtdir"}==$up AND ${$tick . "ltdir"}==$up)
            {
              printf("%-9s",$key);
              print "Was Buyready, now Buyprep V:$value\n";
              ${$tick . "tradeflag"} = $buyprep;
            }

            // CDA was buyprep, now trending down, set to buyord if reasonable spread
            if(${$tick . "tradeflag"} == $buyprep AND ${$tick . "stdir"}==$down AND ${$tick . "mtdir"}==$down AND ${$tick . "ltdir"}==$down AND ${$tick . "lspread"} >= $minspread)
            {
              printf("%-9s",$key);
              print "Was Buyprep, now Buyord V:$value\n";
              ${$tick . "tradeflag"} = $buyord;
            }

            // CDA stopped trending down and is ready to buy
            if(${$tick . "tradeflag"} == $buyord AND ${$tick . "stdir"}==$up AND ${$tick . "mtdir"}!=$down)
            {
              if(${$tick . "ltrsi"} <= $minrsi)
              {
                if($cdaorders < $maxorders)
                {
                  printf("%-9s",$key);
                  print "Was Buyord, now Buy V:$value\n";
                  // Assume we buy at the current value
                  ${$tick . "buyvalue"} = $value;
                  ${$tick . "tradeflag"} = $sellok;
                  $cdaorders = $cdaorders + 1;
                  $fh = fopen($tradefile, "a") or die("Cannot open file");
                  fwrite($fh, "========================== \n");
                  fwrite($fh, "Runtime $run_time \n");
                  fwrite($fh, "Buy on $key BV:${$tick . "buyvalue"} \n");
                  fwrite($fh, "========================== \n");
                  fclose($fh);
                }
              }
              else
              {
                printf("%-9s",$key);
                print "RSI Check not meeting Minimum, resetting back to Buy Prep\n";
                ${$tick . "tradeflag"} = $buyprep;
              }
            }

            // Buy Order on CDA placed, do order tracking here to make sure order completes
            if(${$tick . "tradeflag"} == $sellok)
            {
              // Since we are not placing an order, we just assume it completed
              ${$tick . "tradeflag"} = $sellready;
            }

            // CDA is sellready and is no longer trending upwards - time to sell
            if(${$tick . "tradeflag"} == $sellready AND ${$tick . "stdir"}!=$up AND ${$tick . "mtdir"}!=$up)
            {
              // Assume we sell at the current value and not sell if not meeting a minimum
              $cdabuff = ${$tick . "buyvalue"} * $sellbuffer;
              if($value > $cdabuff)
              {
                ${$tick . "sellvalue"} = $value;
                ${$tick . "tradeflag"} = $selldone;
              }
              else
              {
                printf("%-9s",$key);
                print "Did not meet minimum sell amount\n";
              }
            }

            // CDA is selldone
            if(${$tick . "tradeflag"} == $selldone)
            {
              // Sell Order on CDA placed, do order tracking here to make sure order completes
              // Since we are not placing an order, we just assume it completed
              $q = round((((${$tick . "sellvalue"} - ${$tick . "buyvalue"})/${$tick . "buyvalue"})*100),3);
              $tpc = $q - 0.2;
              $rpc = round($rpc + ($tpc/$maxorders),3);
              ${$tick . "lasttrade"} = 1;
              ${$tick . "lasttpc"} = $tpc;
              ${$tick . "tradeflag"} = $buyready;
              $cdaorders = $cdaorders - 1;
              printf("%-9s",$key);
              print "Sell Done BV:${$tick . "buyvalue"} SV:${$tick . "sellvalue"} TPC:$tpc \n";
              $fh = fopen($tradefile, "a") or die("Cannot open file");
              fwrite($fh, "========================== \n");
              fwrite($fh, "Runtime $run_time \n");
              fwrite($fh, "Sell Done on $key BV:${$tick . "buyvalue"} SV:${$tick . "sellvalue"} TPC:$tpc RPC:$rpc \n");
              fwrite($fh, "========================== \n");
              fclose($fh);
            }
          }
        }
      }
    }
  }
  if ($replay == 0) sleep(5);
}
?>
