<?php
namespace BlueHerons\StatTracker;

use StdClass;
use DateTime;
use Exception;

use BlueHerons\StatTracker\StatTracker;

class Agent {

	public $name;
	public $auth_code;
	public $faction;
	public $level;
	public $stats;

	/**
	 * Returns the registered Agent for the given email address. If no agent is found, a generic
	 * Agent object is returned.
	 *
	 * @param string $email_address 
	 *
	 * @return string Agent object
	 */
	public static function lookupAgentName($email_address) {
		global $db;

		$stmt = $db->prepare("SELECT agent, faction, auth_code FROM Agent WHERE email = ?;");
		$stmt->execute(array($email_address));
		extract($stmt->fetch());
		$stmt->closeCursor();

		if (empty($agent)) {
			return new Agent();
		}
		else {
			$agent = new Agent($agent, $auth_code);
			$agent->faction = $faction;
			return $agent;
		}
	}

	/**
	 * Retruns the registered Agent for the given auth_code. If not agent is found, a generic
	 * Agent object is returned.
	 *
	 * @param string $auth_code
	 *
	 * @return object Agent object
	 */
	public static function lookupAgentByAuthCode($auth_code) {
		global $db;
		$stmt = $db->prepare("SELECT agent, faction FROM Agent WHERE auth_code = ?;");
		$stmt->execute(array($auth_code));
		extract($stmt->fetch());
		$stmt->closeCursor();

		if (empty($agent)) {
			return new Agent();
		}
		else {
			$agent = new Agent($agent, $auth_code);
			$agent->faction = $faction;
			return $agent;
		}
	}

	/**
	 * Constructs a new Agent object for the given agent name. This object will include all information
	 * publicly visible from the "Agent Profile" screen in Ingress: Agent name, AP, and badges earned.
	 *
	 * @param string $agent the name of the agent. This name will be searched for in the database. If 
	 * it is not found, an exception will be thrown.  
	 *
	 * @return Agent object with public stats populated.
	 *
	 * @throws Exception if agent name is not found.
	 */
	public function __construct($agent = "Agent", $auth_code = null) {
		if (!is_string($agent)) {
			throw new Exception("Agent name must be a string");
		}

		$this->name = $agent;
		$this->auth_code = $auth_code;

		if ($this->isValid()) {
			$this->getLevel();
			$this->hasSubmitted();
			$this->getStat('ap');
			$this->getUpdateTimestamp();
		}
	}

	/**
	 * Determines if a valid name has been set for this agent.
	 *
	 * @return boolean true if agent is valid, false otherwise
	 */
	public function isValid() {
		return $this->name != "Agent" && !empty($this->auth_code);
	}

	/**
	 * Generates JSON formatted data for use in a Google Visualization API pie chart.
	 *
	 * @param Agent agent the agent whose data should be used
	 *
	 * @return string Object AP Breakdown object
	 */
	public function getAPBreakdown() {
		global $db;

		$stmt = $db->prepare("CALL GetAPBreakdown(?);");
		$stmt->execute(array($this->name));
		$stmt->closeCursor();

		$stmt = $db->query("SELECT * FROM APBreakdown ORDER BY grouping, sequence ASC;");

		$data = array();
		$colors = array();

                // TODO: Numbers only!
		while ($row = $stmt->fetch()) {
			$data[] = array($row['name'], $row['ap_gained']);
			if ($row['grouping'] == 1) {
				$color =$this->faction == "R" ? ENL_GREEN : RES_BLUE;
			}
			else if ($row['grouping'] == 3) {
				$color = $this->faction == "R" ? RES_BLUE : ENL_GREEN;
			}
			else {
				$color = "#999";
			}
			$colors[] = $color;
		}
		$stmt->closeCursor();

	        return array("data" => $data, "slice_colors" => $colors);
	}

	/**
	 * Gets the auth code for the agent
	 * 
	 * @param bool $refresh Whether or not to refetch the value from the database
	 *
	 * @return the auth code for thw agent
	 */
	public function getAuthCode($refresh = false) {
		if (!isset($this->auth_code) || $refresh) {
			global $db;
			$stmt = $db->prepare("SELECT auth_code FROM Agent WHERE agent = ?;");
			$stmt->execute(array($this->name));
			extract($stmt->fetch());
			$stmt->closeCursor();

			$this->auth_code = $auth_code;
		}

		return $this->auth_code;
	}

	/**
	 * Generates JSON formatted data for use in a line graph.
	 *
	 * @param string $stat the stat to generate the data for
	 *
	 * @return string Object Graph Data object
	 */
	public function getGraphData($stat) {
		global $db;
		$stmt = $db->prepare("CALL GetGraphForStat(?, ?);");
		$stmt->execute(array($this->name, $stat));
	
		$stmt = $db->query("SELECT * FROM GraphDataForStat;");
		
		$data = array();
		while ($row = $stmt->fetch()) {
			if (sizeof($data) == 0) {
				foreach (array_keys($row) as $key) {
					$series = new StdClass();
					$series->name = $key;
					$series->data = array();
					$data[] = $series;
				}
			}

			$i = 0;
			foreach (array_values($row) as $value) {
				$data[$i]->data[] = $value;

				$i++;
			}
		}
		$stmt->closeCursor();

		$response = new StdClass();
		$response->data = $data;
		$response->prediction = $this->getPrediction($stat); // TODO: move elsewhere

		return $response;
	}

	/**
	 * Gets the current level for the Agent. Considers AP and badges.
	 *
	 * @returns int current Agent level
	 */
	public function getLevel($date = "latest") {
		if (!isset($this->level)) {
			global $db;

			if ($date == "latest") {
				$date = date("Y-m-d");
			}

			$stmt = $db->prepare("CALL GetLevel(?, ?);");
			$stmt->execute(array($this->name, $date));
			$stmt->closeCursor();

			$stmt = $db->query("SELECT level FROM _Level;");
			extract($stmt->fetch());
			$stmt->closeCursor();

			$this->level = $level;
		}

		return $this->level;
	}

	public function getTrend($stat, $when) {
		global $db;
		$start = "";
		$end = "";

		switch ($when) {
			case "last-week":
				$start = date("Y-m-d", strtotime("last monday", strtotime("6 days ago")));
				$end = date("Y-m-d", strtotime("next sunday", strtotime("8 days ago")));
				break;
			case "this-week":
			case "weekly":
			default:
				$start = date("Y-m-d", strtotime("last monday", strtotime("tomorrow")));
				$end = date("Y-m-d", strtotime("next sunday", strtotime("yesterday")));
				break;
		}

		$stmt = $db->prepare("CALL GetDailyTrend(?, ?, ?, ?);");
		$stmt->execute(array($this->name, $stat, $start, $end));
		$stmt->closeCursor();

		$stmt = $db->query("SELECT * FROM DailyTrend");

		$data = array();
		while ($row = $stmt->fetch()) {
			$data["dates"][] = $row["date"];
			$data["target"][] = $row["target"];
			$data["value"][] = $row["value"];
		}
		$stmt->closeCursor();

		return $data;
	}

	/**
	 * Determines if the Agent has submitted to Stat Tracker
	 */
	public function hasSubmitted($refresh = false) {
		if (!isset($this->has_submitted) || $refresh) {
			global $db;
			$stmt = $db->prepare("SELECT count(stat) > 0 AS result FROM Data WHERE stat = 'ap' AND agent = ?;");
			$stmt->execute(array($this->name));
			extract($stmt->fetch());
			$stmt->closeCursor();

			$this->has_submitted = $result > 0;
		}

		return $this->has_submitted;
	}

	/**
	 * Gets the timestamp for which the last update was made for the agent. If $data is provided, the timestamp will 
	 * be the update for that day
	 */
	public function getUpdateTimestamp($date = "latest", $refresh = false) {
		if (!isset($this->update_time) || $this->update_time == null || $refresh) {
			global $db;
			$stmt = null;
			if ($date == "latest" || new DateTime() < new DateTime($date)) {
				$stmt = $db->prepare("SELECT UNIX_TIMESTAMP(MAX(updated)) `updated` FROM Data WHERE agent = ?");
				$stmt->execute(array($this->name));
			}
			else {
				$stmt = $db->prepare("SELECT UNIX_TIMESTAMP(MAX(updated)) `updated` FROM Data WHERE agent = ? AND date = ?;");
				$stmt->execute(array($this->name, $date));
			}

			extract($stmt->fetch());
			$stmt->closeCursor();

			$this->update_time = $updated;
		}

		return $this->update_time;
	}

	/**
	 * Gets the values of all stats.
	 *
	 * @param string|date $when "latest" to get the latest stats submitted by the agent, or a date in "yyyy-mm-dd"
	 *                    format to retrieve  stats on that date
	 * @param boolean $refresh whether or not to refresh the cached values
	 *
	 * @return array values for stats
	 */
	public function getStats($when = "latest", $refresh = true) {
		if (!is_array($this->stats) || $refresh) {
			global $db;

			if ($when == "latest" || new DateTime() < new DateTime($when)) {
				$when = date("Y-m-d", $this->getUpdateTimestamp("latest", $refresh));
			}

			$stmt = $db->prepare("SELECT stat, value FROM Data WHERE agent = ? AND date = ? ORDER BY stat ASC;");
			$stmt->execute(array($this->name, $when));

			if (!is_array($this->stats) || $refresh) {
				$this->stats = array();
				$this->stats['ap'] = 0;
			}

			while ($row = $stmt->fetch()) {
				extract($row);
				$this->stats[$stat] = $value;
			}

			$stmt->closeCursor();
		}

		return $this->stats;
	}

	/**
	 * Gets the value of the specified stat.
	 *
	 * @param string|object If string, the stat's database key. If object, a Stat object for the class
	 * #param boolean $refresh whether or not to refresh the cached value
	 *
	 * @return the value for the stat
	 */
	public function getStat($stat, $when = "latest", $refresh = false) {
		if (is_object($stat)) {
			$stat = $stat->stat;
		}
	
		if (!isset($this->stats[$stat]) || $refresh) {
			global $db;

			if ($when == "latest" || new DateTime() < new DateTime($when)) {
				$when = date("Y-m-d", $this->getUpdateTimestamp($when, $refresh));
			}

			$stmt = $db->prepare("SELECT value FROM Data WHERE stat = ? AND agent = ? AND date = ? ORDER BY date DESC LIMIT 1;");
			$stmt->execute(array($stat, $this->name, $when));
			extract($stmt->fetch());
			$stmt->closeCursor();

			if (!is_array($this->stats)) {
				$this->stats = array();
			}
	
			$this->stats[$stat] = $value;
		}

		return $this->stats[$stat];
	}

	/**
	 * Gets an array of badges for the current player. array index is the badge name, and the array value 
	 * is the level of the current badge
	 *
	 * @param boolean $refresh Whether or not to refresh the cached values
	 *
	 * @return array the array of current badges the Agent has earned
	 */
	public function getBadges($date = "today", $refresh = false) {
		if (!is_array($this->badges) || $refresh) {
			global $db;

			$stmt = $db->prepare("CALL GetBadges(?, ?);");

			if ($date == "today") {
				$today = true;
				$date = date("Y-m-d");
			}

			$stmt->execute(array($this->name, $date));
			$stmt->closeCursor();

			$stmt = $db->query("SELECT * FROM _Badges;");

			if ($today && $stmt->rowCount() == 0) {
				$this->getBadges(date("Y-m-d", $this->getUpdateTimestamp("latest", $refresh)), true);
			}

			if (!is_array($this->badges)) {
				$this->badges = array();
			}
			
			while ($row = $stmt->fetch()) {
				extract($row);
				$badge = str_replace(" ", "_", $badge);
				$badge = strtolower($badge);

				$this->badges[$badge] = strtolower($level);
			}

			$stmt->closeCursor();
		}

		return $this->badges;
	}
	
        /**
	 * Gets the prediction for a stat. If the stat has a badge associated with it, this will also
	 * retrieve the badge name, current level, next level, and percentage complete to attain the next
	 * badge level.
	 *
	 * @param string $stat Stat to retrieve prediction for
	 *
	 * @return Object prediciton object
	 */
	public function getPrediction($stat) {
		global $db;

		$prediction = new StdClass();
		$stmt = $db->prepare("CALL GetBadgePrediction(?, ?);");
		$stmt->execute(array($this->name, $stat));

		$stmt = $db->query("SELECT * FROM BadgePrediction");
                $row = $stmt->fetch();

		$prediction->stat = $row['stat'];
		$prediction->name = $row['name'];
		$prediction->unit = $row['unit'];
		$prediction->badge = $row['badge'];
		$prediction->current = $row['current'];
		$prediction->next = $row['next'];
                $prediction->rate = $row['rate'];
		$prediction->progress = $row['progress'];
                $prediction->days_remaining = $row['days'];

                if ($stat !== "level") {
                    $prediction->amount_remaining = $row['remaining'];
                }
                else {
		    $prediction->silver_remaining = $row['silver_remaining'];
		    $prediction->gold_remaining = $row['gold_remaining'];
		    $prediction->platinum_remaining = $row['platinum_remaining'];
		    $prediction->onyx_remaining = $row['onyx_remaining'];
                }

		return $prediction;
	}

	/**
	 * Gets the ratios of stats for the given agent.
	 *
	 * @return array top level entries are a ratio "pair", with a sub array containing keys stat1, stat2, and ratio
	 */
	public function getRatios() {
		if (!is_array($this->ratios)) {
			global $db;
			$stmt = $db->prepare("CALL GetRatiosForAgent(?);");
			$stmt->execute(array($this->name));
			$stmt->closeCursor();
	
			$stmt = $db->query("SELECT * FROM RatiosForAgent WHERE badge_1 IS NOT NULL AND badge_2 IS NOT NULL;");

			$this->ratios = array();
			
			while ($row = $stmt->fetch()) {
				extract($row);
				$badge = str_replace(" ", "_", $badge);
				$badge = strtolower($badge);

				$this->ratio[] = array(
					"stat1" => array(
						"stat" => $stat_1,
						"badge" => strtolower(str_replace(" ", "_", $badge_1)),
						"level" => strtolower($badge_1_level),
						"name" => $stat_1_name
					),
					"stat2" => array(
						"stat" => $stat_2,
						"badge" => strtolower(str_replace(" ", "_", $badge_2)),
						"level" => strtolower($badge_2_level),
						"name" => $stat_2_name
					),
					"ratio" => $ratio
				);
			}
			$stmt->closeCursor();
		}

		return $this->ratio;
	}

	/**
	 * Gets the next X badges for the agent, ordered by least time remaining
	 *
	 * @param int $limit number of badges to return, default 3
	 *
	 * @return array of badges
	 */
	public function getUpcomingBadges($limit = 4) {
		if (!is_array($this->upcoming_badges)) {
			global $db;
			$stmt = $db->prepare("CALL GetUpcomingBadges(?);");
			$stmt->execute(array($this->name));
			$stmt->closeCursor();

			// sprintf still used intentionally
			$stmt = $db->query(sprintf("SELECT * FROM UpcomingBadges WHERE (days_remaining > 0 OR days_remaining IS NULL) ORDER BY days_remaining ASC LIMIT %d;", $limit));

			if (!is_array($this->upcoming_badges)) {
				$this->upcoming_badges = array();
			}
			
			while ($row = $stmt->fetch()) {
				extract($row);
				$badge = str_replace(" ", "_", $badge);
				$badge = strtolower($badge);

				$this->upcoming_badges[] = array(
					"name" => $badge,
					"level" => strtolower($next),
					"progress" => $progress,
					"days_remaining" => $days_remaining
				);
			}
		}

		return $this->upcoming_badges;
	}
}
?>
