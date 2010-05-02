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
		
		updatePositions();
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
	}
	
	
	if($count > 0  && $players > 0 && $count >= $players * 5) {
		for($player = 0;$player < $players;$player++) {
		
			$pos = $_SESSION["positions"][$player];
			$db->query("SELECT unit, priority, action, quantity, round FROM desired_event WHERE unit='$player' AND gameid=($gameid) ORDER BY id ASC;");
			while(list($u, $p,$a,$q, $round) = $db->fetchrow()) {
				for($i = 0;$i < $q;$i++) {
					$xChange = $yChange = $rotChange = 0;
					
					switch($a) {
						case "b":
						case "f":
							// 90 - flips direction of rotation for true math and javascript
							$yChange = -1*sin(deg2rad(90-$pos[2]));
							$xChange = cos(deg2rad(90-$pos[2]));
							
							if($a =="b"){
								$xChange *= -1;
								$yChange *= -1;
							}
						break;
				
						
						case "r":
							$rotChange = 90;
						break;
						
						case "l":
							$rotChange = -90;
						break;
					}
					debug( "From: $pos[0]x$pos[1] with $pos[2], we are acting on $a.<br/>\n");
					$x = $pos[0] += $xChange;
					$y = $pos[1] += $yChange;
					$rot = $pos[2] += $rotChange;
					
					debug( "xchange is $xChange, ychange is $yChange, rotChange is $rotChange.<br/>\n");
				}
				$db2->query("INSERT INTO pending_event (unit, x,y,rot, round,gameid) VALUES ('$u','$x','$y','$rot', '$round', ($gameid));");
			}
			$db->query("DELETE FROM desired_event where gameid=($gameid) and unit='$player';");
			$_SESSION["positions"][$player] = $pos;
		}
		return true;
	} else {
		debug("count was $count, $players players,  so failure to run rounds.");
		return false;
	}
}


function updatePositions() {
	$db = new DB();
	$gameid = $_SESSION["gameid"];
	foreach($_SESSION["positions"] as $unit=>$pos) {
		list($x,$y,$r) = $pos;
		$db->query("INSERT INTO pending_event (unit, x,y,rot, gameid) VALUES ('$unit','$x','$y','$r', ($gameid));", true);
	}
}

function updateGamesTable() {
	$db = new DB();
	$db->query("DELETE FROM game WHERE created < (Now() - (60 * 60))");
	$db->query("INSERT INTO game VALUES ();");
	$_SESSION["gameid"] = $db->insertid();
	debug("gameid is " . $_SESSION["gameid"]);
	return $db->insertid();
}
	
	
function debug($msg) {
	$fp = fopen("rally.log", "a");
	fwrite($fp,$msg . "\n");
	
}

