<?php
// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die('Restricted access');
$lang = &JFactory::getLanguage();
$lang->load('com_users', JPATH_SITE, 'en-GB', true);

//"development" or "production" environment
if (!defined("ENV")) {
	define("ENV", 'development');
}

define("GOOGLE_API_KEY", "");
define("ANDROID", "0");
define("IOS", "1");

if (!defined("NOTIFICATION_TABLE")) {
	define("NOTIFICATION_TABLE", 'push_notifications');
}

if (!defined("USER_DEVICE_TABLE")) {
	define("USER_DEVICE_TABLE", 'user_devices');
}

class Notification {
	function __construct() {
		$this->env = ENV;
		$this->db = JFactory::getDBO();
	}

	/**
	 * Adds a device to the database. If device already exists then replaces the user.
	 **/
	function addDevice($deviceId, $userId, $deviceType = 1) {
		$query = "SELECT device_id, user_id " .
			" FROM  " . USER_DEVICE_TABLE .
			" WHERE " .
			" device_id = '" . $deviceId . "'" .
			" AND " .
			" device_type = '" . $deviceType . "'"
		;

		$this->db->setQuery($query);

		$this->db->Query();

		if ($this->db->getErrorNum()) {
			throw new DBException();
		}

		$rows = $this->db->loadAssocList();

		//update if exists
		if (!empty($rows)) {
			$query = "UPDATE ";
		} //insert if entry doesn't exist
		else {
			$query = "INSERT INTO ";
		}

		$query .= USER_DEVICE_TABLE .
			" SET " .
			" user_id = " . $userId .
			", device_type = '" . $deviceType . "'" .
			", device_id = '" . $deviceId . "'"
		;

		if (!empty($rows)) {

			$query .= " WHERE " .
				" device_id = '" . $deviceId . "'" .
				" AND " .
				" device_type = '" . $deviceType . "'"
			;
		}

		$this->db->setQuery($query);
		$this->db->Query();
		return true;
	}

	/**
	 * Gets array of device Ids from the database. false if doesn't exist.
	 **/
	function getDeviceId($userId) {
		$query = "SELECT device_id " .
			" FROM  " . USER_DEVICE_TABLE .
			" WHERE " .
			" user_id = " . $userId;

		$this->db->setQuery($query);
		$this->db->Query();
		$deviceId = $this->db->loadResultArray();
		return empty($deviceId) ? false : $deviceId;
	}

	/**
	 * Gets array of User Ids from the database. false if doesn't exist.
	 **/
	function getUserID($deviceId) {
		$query = "SELECT user_id " .
			" FROM  " . USER_DEVICE_TABLE .
			" WHERE " .
			" device_id = '" . $deviceId . "'";

		$this->db->setQuery($query);
		$this->db->Query();
		$userId = $this->db->loadResultArray();

		return empty($userId) ? false : $userId;
	}

	/*
		 * To get device info
		 * name: getDeviceDetails
		 * @param $userId
		 * @return device details
		 *
	*/
	function getDeviceDetails($userId) {
		$deviceDetails = array();

		$query = "SELECT " .
			" device_id, device_type " .
			" FROM " . USER_DEVICE_TABLE .
			" WHERE " .
			" user_id = " . $userId
		;

		$this->db->setQuery($query);

		$row = $this->db->loadAssocList();

		foreach ($row as $key => $val) {

			$deviceDetails[] = array('device_id' => $val['device_id'],
				'device_type' => $val['device_type'],
			);

		}
		return $deviceDetails;
	}

	/**
	 * Deletes device details from the database. False if id doesn't exists.
	 **/
	function deleteDevice($deviceId) {
		$query = "DELETE FROM " . USER_DEVICE_TABLE .
		" WHERE " .
		" device_id = " . $this->db->quote($deviceId)
		;

		$this->db->setQuery($query);
		$this->db->Query();

		return true;
	}

	/**
	 * Sends message to devices.
	 **/
	function sendMessage($userIds = null, $message) {
		$dbh = new PDO($this->connectionString, $this->dbUserName, $this->dbPass, array(PDO::ATTR_PERSISTENT => true));
		if ($userIds == "") {
			$stmt = $dbh->prepare('SELECT user_id,device_id,device_type from ' . USER_DEVICE_TABLE);
			if ($stmt->execute()) {
				while ($row = $stmt->fetch()) {
					$deviceDetails[$row['user_id']]['device_id'] = $row['device_id'];
					$deviceDetails[$row['user_id']]['device_type'] = $row['device_type'];
				}
			} else {
				throw new Exception("DBError");
			}

		} else {
			foreach ($userIds as $userId) {
				$tempDeviceDetails = $this->getDeviceDetails($userId);
				$deviceDetails[$userId] = $tempDeviceDetails;
			}
		}
		foreach ($deviceDetails as $key => $deviceDetails_array) {
			foreach ($deviceDetails_array as $deviceDetail) {
				if ($deviceDetail['device_type'] == IOS) {
					$result[] = $this->sendApn(array('deviceId' => $deviceDetail['device_id']), $message);
				} else if ($deviceDetail['device_type'] == ANDROID) {
					$result[] = $this->sendGcm(array('deviceId' => $deviceDetail['device_id']), $message);
				}

			}

		}
		return $result;
	}

	/**
	 * Input an assoc array of userId or deviceId. sendApn(array('deviceId'=>'jdfslkj'))
	 **/
	function sendApn($deviceDetails, $message) {
		$ctx = stream_context_create();
		if ($this->env == 'production') {
			stream_context_set_option($ctx, 'ssl', 'local_cert', JPATH_ROOT . '/certificates/PushProCerKey.pem');
			$gateway = 'ssl://gateway.push.apple.com:2195';
		} else {
			stream_context_set_option($ctx, 'ssl', 'local_cert', JPATH_ROOT . '/certificates/PushDevCerKey.pem');
			$gateway = 'ssl://gateway.sandbox.push.apple.com:2195';
		}
		stream_context_set_option($ctx, 'ssl', 'passphrase', 'admin');
		$payload = json_encode($message); // Encode the payload as JSON
		$deviceId = isset($deviceDetails['deviceId']) ? $deviceDetails['deviceId'] : $this->getDeviceId($deviceDetails['userId']);
		$err = $errstr = null;
		$apns = stream_socket_client($gateway, $err, $errstr, 30, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx); // Open a connection to the APNS server

		if (!$apns) {
			$result = "Failed to connect: $err $errstr" . PHP_EOL;
			//error_log("Failed to send APN to ".$deviceDetails['deviceId']);
		} else {
			$imsg = chr(0) . pack('n', 32) . pack('H*', trim($deviceId)) . pack('n', strlen($payload)) . $payload;
			$res = fwrite($apns, $imsg, strlen($imsg)); // Send it to the server

			if (!$res) {
				$result = array('id' => 1, 'fieldname' => 'N/A', 'message' => 'Message not delivered', 'sys_message' => 'Message not delivered');
			} else {
				$result = array('notification_result' => 'Message successfully delivered');
			}
			fclose($apns);
		}
		return $result;
	}

	/**
	 * Sends message to android devices.
	 **/
	public function sendGcm($deviceDetails, $message) {
		$url = 'https://android.googleapis.com/gcm/send';
		$deviceId = isset($deviceDetails['deviceId']) ? $deviceDetails['deviceId'] : $this->getDeviceId($deviceDetails['userId']);
		$fields = array(
			'registration_ids' => array($deviceId),
			'data' => $message,
		);

		$headers = array(
			'Authorization: key=' . GOOGLE_API_KEY,
			'Content-Type: application/json',
		);
		// Open connection
		$ch = curl_init();
		// Set the url, number of POST vars, POST data
		curl_setopt($ch, CURLOPT_URL, $url);

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Disabling SSL Certificate support temporarly
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));

		// Execute post
		$result = curl_exec($ch);
		if ($result === FALSE) {
			error_log('Curl failed: ' . curl_error($ch));
		}

		// Close connection
		curl_close($ch);

		//Log GCM notifications
		if (defined("LOG_GCM") && LOG_GCM === TRUE) {
			//log
		}

		return $result;
	}

}
