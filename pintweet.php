<?php

require 'twitteroauth/autoload.php';
use Abraham\TwitterOAuth\TwitterOAuth;

// tick use required as of PHP 4.3.0
declare (ticks = 1);

function queryData($message = null)
{
    global $serialConnection;

    if (!empty($message)) {
        // Set blocking mode for writing
        stream_set_blocking($serialConnection, 1);
        fputs($serialConnection, $message."\n");
    }

    $timeout = time() + 10;
    $line = '';

    // Set non blocking mode for reading
    stream_set_blocking($serialConnection, 0);
    do {
        // Try to read one character from the device
        $c = fgetc($serialConnection);

        // Wait for data to arrive
        if ($c === false) {
            continue 1;
        }

        $line .= $c;
    } while ($c != "\n" && time() < $timeout);
    $response = trim($line);

    if (!empty($message) && $response == $message) {
        // Original message echoed back, read again
        sleep(1);
        $response = queryData();
    }

    // Drain the read queue if there is anything left
    while ($c !== false) {
        $c = fgets($serialConnection);
    }

    return $response;
}

function initSerial($serialData)
{
    global $serialConnection;

    // Set device controle options (See man page for stty)
    exec('/bin/stty -F /dev/ttyUSB0 57600 sane raw cs8 hupcl cread clocal -crtscts -ixon -ixoff -cstopb -parenb -echo -onlcr ');

    // Open serial port
    $serialConnection = fopen('/dev/ttyUSB0', 'c+');

    if (!$serialConnection) {
        logIt('Could not set serial device.', true);
    }

    $response = queryData('zc ver');

    // check that the version higher or equal to 1.18
    $pattern = '/([0-9]+\.[0-9]{1,2})/';
    preg_match($pattern, $response, $matches);
    if (floatval($matches[0]) < 1.18) {
        logIt('Communication patch v1.18 or later must be installed.', true);
    }

    return true;
}

// signal handler function
function shutdown($signo = null)
{
    global $serialConnection;

    if (!empty($serialConnection)) {
        fclose($serialConnection);
    }

    logIt('*** SHUTDOWN ***');
    exit();
}

function queryScores($maxPlayers)
{
    global $serialConnection;

    if ($maxPlayers < 1) {
        logIt('maxPlayers must be at least 1.', true);
    }

    $scores = array();

    for ($i = 1; $i <= $maxPlayers; ++$i) {
        sleep(1);
        $response = queryData('zc mod 0x5c073564 '.$i);

        $pattern = '/=([0-9a-fA-F]+)/';
        preg_match($pattern, $response, $matches);
        if (!isset($matches[1])) {
            return false;
        }
        $scores[$i] = hexdec($matches[1]);
        if($scores[$i] === 0) {
            // If Player 2 has a score of zero, no need to check Players 3 & 4
            break;
        }
    }

    if (count($scores) == 0) {
        return false;
    }

    return max($scores);
}

function postTweet($OAuth, $status)
{
    if (empty($status)) {
        return false;
    }

    $connection = new TwitterOAuth($OAuth['consumer_key'], $OAuth['consumer_secret'], $OAuth['access_token'], $OAuth['access_token_secret']);
    $result = $connection->post('statuses/update', array('status' => $status.' #PinScore'));

    if (empty($result->id)) {
        logIt('Error posting tweet. '.$result->errors[0]->message);
    } else {
        return true;
    }
}

function logIt($text, $exit = false)
{
    file_put_contents('pintweet.log', date('Y-m-d H:i:s').' '.trim($text)."\n", FILE_APPEND);
    if ($exit) {
        echo(trim($text)."\n");
        shutdown();
    }
}

logIt('*** STARTUP ***');

$config = json_decode(file_get_contents('config.json'), true);
if (empty($config)) {
    logIt('Could not load config.json file. Look at config-sample.json for examples of how to format your config.json', true);
}

if (file_exists('scores.json')) {
    $prevScores = json_decode(file_get_contents('scores.json'), true);
}

initSerial($config['serial']);

// setup signal handlers
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, 'shutdown');
    pcntl_signal(SIGTERM, 'shutdown');
    pcntl_signal(SIGHUP,  'shutdown');
}

$prevScore = 0;
$prevHighScore = !empty($prevScores['highscore']) ? $prevScores['highscore'] : 0;
$lastScoreChange = 0;
$lowScoreFound = FALSE;

while (true) {
    $newScore = queryScores($config['machine']['maxPlayers']);

    if ($newScore !== false) {
        if ($newScore != $prevScore && $prevScore > 0) {
            $lastScoreChange = time();
        }

        /* Make sure you've seen two lower scores in a row before processing it.
           Only one lower score could be a glitch in reading the serial port. */
        if ($newScore < $prevScore && $lowScoreFound == false) {
            $lowScoreFound = true;
            continue 1;
        }
        $lowScoreFound = false;

        // If a new game has started or the scores last changed 2 minutes ago
        if ($newScore < $prevScore || ($lastScoreChange > 0 && (time() - $lastScoreChange) > 120)) {
            $status = 'Score of '.number_format($prevScore).' posted to '.$config['machine']['name'];
            if ($prevScore > $prevHighScore) {
                $status = 'HIGH '.$status;
                file_put_contents('scores.json', json_encode(array('highscore' => $prevScore), JSON_PRETTY_PRINT));
                $prevHighScore = $prevScore;
            }

            if ($prevScore > $config['machine']['minimumScore']) {
                logIt('Tweet: '.$status);
                $result = postTweet($config['OAuth'], $status);
                if (empty($result)) {
                    logIt('Tweet not posted');
                }
	    } else {
	        logIt('Score: '.number_format($prevScore));
	    }

            $prevScore = 0;
            $lastScoreChange = 0;
        } else {
            $prevScore = $newScore;
        }
    }
    sleep(5);
}
