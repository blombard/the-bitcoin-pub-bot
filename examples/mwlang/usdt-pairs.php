<?php
require 'vendor/autoload.php';

// This bot will trade USDT to the other pairs and back to make more USDT
// It shall use all the trading pairs to make more UDST except the ones we tell it not to use

$interval = 5;            // interval between price sampling
$stperiod = 50;           // Short Term Trend - must be less than $mtperiod
$mtperiod = 90;           // Medium Term Trend - must be less than $ltperiod
$ltperiod = 120;          // Long Term Trend
$tradefile = "BTC.txt";   // The Trade Logging file name
$minspread = 1.1;         // The minimum spread percentage needed for a trade
$minrsi = 45;             // Relative Strength must be below this number to buy
$sellbuffer = 1.003;      // Create a buffer to hold CDA if sell is not profitable
$maxorders = 10;          // Maximum number of concurrent orders

// Do not change any of the flags, we use this to signal the bot what to do and when
$buyready = 0;            // This flag signals the bot that the pair meets rules to buy
$buyprep = 1;             // This flag signals the bot to prepare to buy
$buyord = 2;              // This flag signals the bot to place an order
$sellok = 3;              // This flag signals the bot that the order was completed
$sellready = 4;           // This flag signals the bot to sell
$selldone = 5;            // This flag signals the bot the trade completed
$dontbuy = 6;             // This flag signals the bot we dont want to trade BCASH :P

// Trend Directions
$unset = 0;               // Initial trend is indeterminent until set otherwise.
$down = 1;                // price action is trending downward
$flat = 2;                // price action is flat/unchanged
$up = 3;                  // price action is trending upward

// Standard variables and arrays we use
$replay = 1;
$i = 0;
$cdas = array();
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

class Trend {
  public $period = 0;
  public $label = '';
  public $values = array();
  public $direction = 0;
  public $rsi = 100;
  public $value = 0;
  public $prev_value = 0;
  protected $rsi_gains = array();
  protected $rsi_losses = array();

  function __construct ($label, $period) {
    $this->label = $label;
    $this->period = $period;
  }

  public function stat () {
    global $down;
    global $flat;
    global $up;

    print "\t $this->label:";
    printf("%-14.8F",$this->avg());
    if ($this->direction == $down) printf("%-5s",":DOWN");
    if ($this->direction == $flat) printf("%-5s",":FLAT");
    if ($this->direction == $up) printf("%-5s",":UP");
  }

  // Calculate the Relative Strength Indicator on the Array
  // A Low RSI indicates a buy opportunity
  function compute_rsi () {

    if (empty($this->rsi_gains) OR $this->value == $this->prev_value) {
      array_push($this->rsi_gains, 0);
      array_push($this->rsi_losses, 0);

    } elseif ($this->value > $this->prev_value) {
      array_push($this->rsi_gains, $this->value - $this->prev_value);
      array_push($this->rsi_losses, 0);

    } else {
      array_push($this->rsi_losses, $this->prev_value - $this->value);
      array_push($this->rsi_gains, 0);
    }

    if (count($this->rsi_gains) > $this->period) {
      array_shift($this->rsi_gains);
      array_shift($this->rsi_losses);
    }

    $gain_avg = array_sum($this->rsi_gains) / count($this->rsi_gains);
    $loss_avg = array_sum($this->rsi_losses) / count($this->rsi_gains);

    if ($loss_avg > 0) {
      $this->rsi = round(100-(100/(1+($gain_avg/$loss_avg))),3);

    } else {
      $this->rsi = 100;
    }
  }

  public function append ($value) {
    $this->prev_value = end($this->values);
    $this->value = $value;

    array_push($this->values, $value);
    if (count($this->values) > $this->period) array_shift($this->values);
    $this->compute_rsi();
  }

  public function avg () {
    if (count($this->values) == 0) {
      return 0;
    } else {
      return round((array_sum($this->values) / count($this->values)), 8);
    }
  }

  // Calculate the spread, which is the percentage difference between
  // the highest recorded price and the lowest recorded price in the Trend Array
  public function spread () {
    $low = min($this->values);
    $high = max($this->values);
    return round(((1 - ($low/$high)) * 100), 3);
  }

  public function set_direction ($other) {
    global $down;
    global $flat;
    global $up;

    $avg = $this->avg();
    if ($other < $avg) $this->direction = $down;
    if ($other == $avg) $this->direction = $flat;
    if ($other > $avg) $this->direction = $up;
  }
}

class Cda {

  public $tick = '';                // The ticker symbol for this CDA
  public $st = NULL;                // Short Term Trend
  public $mt = NULL;                // Medium Term Trend
  public $lt = NULL;                // Long Term Trend
  public $tradeflag = 0;            // Set this pair to buyready
  public $buyvalue = 0;             // record what we buy for on this pair
  public $sellvalue = 0;            // record what we sell for on this pair
  public $lasttrade = 0;            // record we have had one trade done
  public $lasttpc = 0;              // record what percentage last the trade was
  public $isset = 1;                // used to signal we are initialised for this pair
  public $trade_start = 0;          // start of currently open trade
  public $trade_cycles = array();   // track each trade cycle length

  function __construct($tick) {
    global $stperiod;
    global $mtperiod;
    global $ltperiod;

    $this->tick = $tick;
    $this->st = new Trend('ST', $stperiod);
    $this->mt = new Trend('MT', $mtperiod);
    $this->lt = new Trend('LT', $ltperiod);
  }

  function dontbuy() {
    global $dontbuy;
    $this->tradeflag = $dontbuy;
  }

  function update_price($value) {
    $this->st->append($value);
    $this->mt->append($value);
    $this->lt->append($value);
  }
}

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
  if ($replay == 1) {
    $run_time = round(($i * $interval) / 60, 2);
  } else {
    $run_time = round((($time_end - $time_start)/60),2);
  }
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
      if($i >= $cda->mt->period)
      {
        array_shift($btctrend);
        $btcavg = round((array_sum($btctrend)/$cda->lt->period),8);
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

      // Use the lower case name to form the leading part of varibales and arrays
      // for exmaple, using "btcusdt" and adding "st" for the short term array
      // will initialise an array called "btcusdtst"
      // as we loop thorugh the pairs, each one gets created for each pair
      // for exmaple "NEOUSDT" will become "neousdtst"
      $tick = strtolower($key);

      // Check if the trading pair has been initialised
      // this covers if Binance add a new trading pair on USDT while we are running
      // if Binance adds new trading pairs while bot is running, we shall
      // ignore them and only use the ones since the bot was started and initialised
      if (!isset($cdas[$tick])) $cdas[$tick] = new Cda($tick);

      $cda = $cdas[$tick];

      // Exclude List - these ones we do not trade

      if($key == "BCHABCBTC") $cda->dontbuy();
      if($key == "BCHSVBTC") $cda->dontbuy();
      if($key == "BCCBTC") $cda->dontbuy();

      // Push data into arrays and shift arrays once we have enough data
      $cda->update_price($value);
      $cda->st->set_direction($value);
      $cda->mt->set_direction($cda->st->avg());
      $cda->lt->set_direction($cda->mt->avg());

      // Wait until we have all the arrays populated with data
      if($i <= $cda->lt->period) {
        if($key == "BCHSVBTC") print "Loading Arrays with data until Iteration " . $cda->lt->period . " - patience, Grasshopper...\n";
      }

      // Arrays are populated, so on with the processing
      else
      {

        // Print out only ones in Buy Order and Sell Ready
        if($cda->tradeflag == $buyord OR $cda->tradeflag == $sellready)
        {
          printf("%-9s",$key);
          print "\tV:";
          printf("%-14.8F",$value);

          if($cda->tradeflag == $sellready) {
            print "\tCTC:";
            printf("%-3.2F", ($run_time - $cda->trade_start));
          } else {
            print "\tATC:";
            printf("%-3.2F", $cda->trade_cycle_avg);
          }

          print "\t" . $cda->st->stat();
          print "\t" . $cda->mt->stat();
          print "\t" . $cda->lt->stat();

          print "\t  SPREAD:";
          printf("%-03.3F",$cda->lt->spread());
          print "%\t  RSI:";
          printf("%-06.3F",$cda->st->rsi);
          printf("/%-06.3F",$cda->mt->rsi);
          printf("/%-06.3F",$cda->lt->rsi);

          if($cda->tradeflag == $buyord) $cdastatus = "Buy Order";
          if($cda->tradeflag == $sellready) $cdastatus = "Sell Ready";
          if($cda->tradeflag == $sellready)
          {
            $ctp = round(((($value - $cda->buyvalue)/$cda->buyvalue)*100),3);
            print "\t S:$cdastatus \tBV:$cda->buyvalue CTP:$ctp";
          }
          else
          {
            print "\tS:$cdastatus";
          }
          if($cda->lasttrade == 1)
          {
            print "   LastTPC:$cda->lasttpc";
          }
          print "\n";
        }

        // Trading rules start here
        // ========================

        // CDA is trending up so set to buyprep
        if($cda->tradeflag == $buyready AND $cda->st->direction==$up AND $cda->mt->direction==$up AND $cda->lt->direction==$up)
        {
          printf("%-9s",$key);
          print "Was Buyready, now Buyprep V:$value\n";
          $cda->tradeflag = $buyprep;
        }

        // CDA was buyprep, now trending down, set to buyord if reasonable spread
        if($cda->tradeflag == $buyprep AND $cda->st->direction==$down AND $cda->mt->direction==$down AND $cda->lt->direction==$down AND $cda->lt->spread() >= $minspread)
        {
          printf("%-9s",$key);
          print "Was Buyprep, now Buyord V:$value\n";
          $cda->tradeflag = $buyord;
        }

        // CDA stopped trending down and is ready to buy
        if($cda->tradeflag == $buyord AND $cda->st->direction==$up AND $cda->mt->direction!=$down)
        {
          if($cda->lt->rsi <= $minrsi)
          {
            if($cdaorders < $maxorders)
            {
              printf("%-9s",$key);
              print "Was Buyord, now Buy V:$value\n";
              // Assume we buy at the current value
              $cda->buyvalue = $value;
              $cda->tradeflag = $sellok;
              $cda->trade_start = $run_time;
              $cdaorders = $cdaorders + 1;
              $fh = fopen($tradefile, "a") or die("Cannot open file");
              fwrite($fh, "========================== \n");
              fwrite($fh, "Runtime $run_time \n");
              fwrite($fh, "Buy on $key BV:$cda->buyvalue \n");
              fwrite($fh, "========================== \n");
              fclose($fh);
            }
          }
          else
          {
            printf("%-9s",$key);
            print "RSI Check not meeting Minimum, resetting back to Buy Prep\n";
            $cda->tradeflag = $buyprep;
          }
        }

        // Buy Order on CDA placed, do order tracking here to make sure order completes
        if($cda->tradeflag == $sellok)
        {
          // Since we are not placing an order, we just assume it completed
          $cda->tradeflag = $sellready;
        }

        // CDA is sellready and is no longer trending upwards - time to sell
        if($cda->tradeflag == $sellready AND $cda->st->direction!=$up AND $cda->mt->direction!=$up)
        {
          // Assume we sell at the current value and not sell if not meeting a minimum
          $cdabuff = $cda->buyvalue * $sellbuffer;
          if($value > $cdabuff)
          {
            $cda->sellvalue = $value;
            $cda->tradeflag = $selldone;
          }
          else
          {
            printf("%-9s",$key);
            print "Did not meet minimum sell amount\n";
          }
        }

        // CDA is selldone
        if($cda->tradeflag == $selldone)
        {
          // Sell Order on CDA placed, do order tracking here to make sure order completes
          // Since we are not placing an order, we just assume it completed
          $q = round(((($cda->sellvalue - $cda->buyvalue)/$cda->buyvalue)*100),3);
          $tpc = $q - 0.2;
          $rpc = round($rpc + ($tpc/$maxorders),3);
          $cda->lasttrade = 1;
          $cda->lasttpc = $tpc;
          $cda->tradeflag = $buyready;
          array_push($cda->trade_cycles, ($run_time - $cda->trade_start));
          $cda->trade_cycle_avg = round((array_sum($cda->trade_cycles)/count($cda->trade_cycles)),3);
          $cdaorders = $cdaorders - 1;
          printf("%-9s",$key);
          print "Sell Done BV:$cda->buyvalue SV:$cda->sellvalue TPC:$tpc ATC:$cda->trade_cycle_avg\n";
          $fh = fopen($tradefile, "a") or die("Cannot open file");
          fwrite($fh, "========================== \n");
          fwrite($fh, "Runtime $run_time \n");
          fwrite($fh, "Sell Done on $key BV:$cda->buyvalue SV:$cda->sellvalue TPC:$tpc RPC:$rpc ATC:$cda->trade_cycle_avg\n");
          fwrite($fh, "========================== \n");
          fclose($fh);
        }

      }
    }
  }
  if ($replay == 0) sleep($interval);
}
?>
