<?php
session_start();


$GLOBALS["dbHost"] = "localhost";
$GLOBALS["dbUser"] = "rallyracer";
$GLOBALS["dbPass"] = "racerrally";
$GLOBALS["dbDatabase"] = "rallyracer";

$db = new DB();

/* MySQL DB Wrapper */
class DB {
        var $conn, $quiet;

        function DB($db = null) {
		if($db) {
			$this->conn = $db->conn;
		}
                if(!$conn) {
                        $this->conn = @mysql_connect($GLOBALS["dbHost"], $GLOBALS["dbUser"], $GLOBALS["dbPass"]) or die("Could not connect to the database (" . mysql_error() . ")");
                        @mysql_select_db($GLOBALS["dbDatabase"],$this->conn) or die("Could not select database");
                        // print "DB Connected";
                }
        }

        function query($query = "", $debug = false) {
                $this->results = mysql_query($query,$this->conn );
                if ($debug) debug($query);
		if(!$this->results && !$this->quiet) {
                        print "Server Error: (" . mysql_error($this->conn) . ") '$query'.";
                        debug("Server Error: (" . mysql_error($this->conn) . ") '$query'.");
                }
                return $this->results;
        }
        function size() {
                return mysql_num_rows($this->results);
        }
        function fetchrow() {
                return mysql_fetch_array( $this->results , MYSQL_NUM );
        }
        function fetchassoc() {
		return mysql_fetch_assoc($this->results);
	}
        function escape($string) {
                return mysql_real_escape_string($string);
        }
        function insertid() {
		return mysql_insert_id($this->conn);
	}
}

/* This is run every 1 second. It does detection of game events that affect the board, or everyone.
*	Should return true if there are new positions to show to the screen.
*	Should return false if there is nothing new for the screen.
*/
function processEvents() {
	$db = new DB();
	$db2 = new DB($db);
	/*
	* We're pretending this is an application and storing the game state in the session of the web client!
	* Game setup here.
	*/
	if(!$_SESSION["positions"]) {
		$_SESSION["positions"] = array();
		$_SESSION["positions"][] = array(4,4,180);
		$_SESSION["positions"][] = array(5,4,180);
		$_SESSION["positions"][] = array(6,4,180);
		
		// Send these new positons to the board
		$db = new DB();
		$gameid = $_SESSION["gameid"];
		foreach($_SESSION["positions"] as $unit=>$pos) {
			list($x,$y,$r) = $pos;
			$db->query("INSERT INTO pending_event (unit, x,y,rot, gameid) VALUES ('$unit','$x','$y','$r', ($gameid));", true);
		}
		return true;
	}
	
	
	/*
	* Fetch all of the desired moves from the db (Assume they are valid). If we have received one from every player,
	* then process the desired moves and send them to the game screen via pending event..
	*/
	// Check if we have all players.
	$gameid = $_SESSION["gameid"];
	
	$db->query("SELECT count(*) FROM desired_event WHERE gameid=($gameid)");
	list($count) = $db->fetchrow();
	
	if($count > 0) {
		$db->query("SELECT max(unit) FROM player WHERE gameid=($gameid)");
		list($players) = $db->fetchrow();
		if($players != "") {
			$players += 1;
		}
		$_SESSION["players"] = $players;
	}
	
	
	
	if($count > 0  && $players > 0 && $count >= $players * 5) {
		convertDesires($gameid, $players);
		
		return true;
	} else {
		debug("count was $count, $players players,  so failure to run rounds.");
		return false;
	}
}

// Process all of the desires for the current game into actual board movements..
function convertDesires($gameid,$players) {
	$db = new DB();
	$db->query("SELECT unit, priority, action, quantity, round FROM desired_event WHERE unit='$player' AND gameid=($gameid) ORDER BY round ASC, priority DESC;");
	
		
	while(list($u, $p,$a,$q, $round) = $db->fetchrow()) {
		
		$xChange = $yChange = $rotChange = 0;
		$pos = $_SESSION["positions"][$u];
		if($a == "b" || $a == "f") {
			for($i = 0;$i < $q;$i++) {
				
				// 90 - flips direction of rotation for true math and javascript
				$yChange = round(-1*sin(deg2rad(90-$pos[2])));
				$xChange = round(cos(deg2rad(90-$pos[2])));
				
				if($a =="b"){
					$xChange *= -1;
					$yChange *= -1;
				}
				moveUnit($u,$round,$xChange,$yChange, 0);
			}
		}
		if($a == "r" || $a == "l") {
	
			if($a == "r") {
				$rotChange = 90 * $q;
			} else {
			
			
				$rotChange = -90 * $q;
			}
			moveUnit($u,$round,0,0,$rotChange);
		}
		
	}
	$db->query("DELETE FROM desired_event where gameid=($gameid);",true);
	$_SESSION["positions"][$player] = $pos;
	
}
/** 
*   Recursively takes in unit changes and affects surrounding units. 
*   Once all movement is resolved, they are inserted into pending_event.
*/
function moveUnit($unit, $round, $xChange, $yChange, $rotChange) {
	debug("moveUnit($unit, $round, $xChange, $yChange, $rotChange);");
	$db = new DB();
	$x = $_SESSION["positions"][$unit][0] + $xChange;
	$y = $_SESSION["positions"][$unit][1] + $yChange;
	$rot = $_SESSION["positions"][$unit][2] + $rotChange;
	
	if(!$rotChange) {
		for($i = 0;$i <= count($_SESSION["positions"]);$i++) {
			if($i != u && $x == $_SESSION["positions"][$i][0] && $y == $_SESSION["positions"][$i][1]) {
				// Collision with other unit, apply same x and ychanges to that unit.
				moveUnit($i,$round,$xChange,$yChange,$rotChange);
			}
		}
		
	}
			
	$gameid = $_SESSION["gameid"];
	$db->query("INSERT INTO pending_event (unit, x,y,rot, round,gameid) VALUES ('$unit','$x','$y','$rot', '$round', ($gameid));",true);
	$_SESSION["positions"][$unit][0] = $x;
	$_SESSION["positions"][$unit][1] = $y;
	$_SESSION["positions"][$unit][2] = $rot;
}

// Create an ID for this game. Clean out old games (like cron)
function updateGamesTable() {
	$db = new DB();
	$db->query("DELETE FROM game WHERE created < (Now() - (60 * 60))");
	$db->query("INSERT INTO game VALUES ();");
	$_SESSION["gameid"] = $db->insertid();
	debug("gameid is " . $_SESSION["gameid"]);
	return $db->insertid();
}
	
// Write a message to the log file.
function debug($msg) {
	$fp = fopen("rally.log", "a");
	fwrite($fp,$msg . "\n");
	
}

