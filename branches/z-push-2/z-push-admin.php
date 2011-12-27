#!/usr/bin/php
<?php
/***********************************************
* File      :   z-push-admin.php
* Project   :   Z-Push
* Descr     :   This is a small command line
*               client to see and modify the
*               wipe status of Zarafa users.
*
* Created   :   14.05.2010
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

include ("lib/zpushdefs.php");
include ("lib/zpush.php");
include ("lib/request.php");
include ("lib/requestprocessor.php");
include ("lib/debug.php");
include ("lib/utils.php");
include ("lib/zpushadmin.php");
include ("lib/statemanager.php");
include ("lib/exceptions.php");
include ("lib/interfaces.php");
include ("lib/device.php");
include ("config.php");
include ("version.php");

/**
 * //TODO resync of single folders of a users device
 */

/************************************************
 * MAIN
 */
    ZPush::CheckConfig();
    ZPushAdminCLI::CheckEnv();
    ZPushAdminCLI::CheckOptions();

    if (! ZPushAdminCLI::SureWhatToDo()) {
        // show error message if available
        if (ZPushAdminCLI::GetErrorMessage())
            echo "ERROR: ". ZPushAdminCLI::GetErrorMessage() . "\n";

        echo ZPushAdminCLI::UsageInstructions();
        exit(1);
    }

    ZPushAdminCLI::RunCommand();


/************************************************
 * Z-Push-Admin CLI
 */
class ZPushAdminCLI {
    const COMMAND_SHOWALLDEVICES = 1;
    const COMMAND_SHOWDEVICESOFUSER = 2;
    const COMMAND_SHOWUSERSOFDEVICE = 3;
    const COMMAND_WIPEDEVICE = 4;
    const COMMAND_REMOVEDEVICE = 5;
    const COMMAND_RESYNCDEVICE = 6;

    static private $command;
    static private $user = false;
    static private $device = false;
    static private $errormessage;

    /**
     * Returns usage instructions
     *
     * @return string
     * @access public
     */
    static public function UsageInstructions() {
        return  "Usage:\n\tz-push-admin.php [actions] [options]\n\n" .
                "Parameters:\n\t[-a|--action] list/wipe/remove/resync\n\t[-u|--user] username\n\t[-d|--device] deviceid\n\n" .
                "Actions:\n\tlist\t\t\t Lists all devices and synchronized users\n" .
                "\tlist -u USER\t\t Lists all devices of user USER\n" .
                "\tlist -d DEVICE\t\t Lists all users of device DEVICE\n" .
                "\twipe -u USER\t\t Remote wipes all devices of user USER\n" .
                "\twipe -d DEVICE\t\t Remote wipes device DEVICE\n" .
                "\twipe -u USER -d DEVICE\t Remote wipes device DEVICE of user USER\n" .
                "\tremove -u USER\t\t Removes all state data of all devices of user USER\n" .
                "\tremove -d DEVICE\t Removes all state data of all users synchronized on device DEVICE\n" .
                "\tremove -u USER -d DEVICE Removes all related state data of device DEVICE of user USER\n" .
                "\tresync -u USER -d DEVICE Resynchronizes all data of device DEVICE of user USER\n" .
                "\n";
    }

    /**
     * Checks the environment
     *
     * @return
     * @access public
     */
    static public function CheckEnv() {
        if (!isset($_SERVER["TERM"]) || !isset($_SERVER["LOGNAME"]))
            self::$errormessage = "This script should not be called in a browser.";

        if (!function_exists("getopt"))
            self::$errormessage = "PHP Function getopt not found. Please check your PHP version and settings.";
    }

    /**
     * Checks the options from the command line
     *
     * @return
     * @access public
     */
    static public function CheckOptions() {
        if (self::$errormessage)
            return;

        $options = getopt("u:d:a:", array("action:"));

        // get 'user'
        if (isset($options['u']) && !empty($options['u']))
            self::$user = trim($options['u']);
        else if (isset($options['user']) && !empty($options['user']))
            self::$user = trim($options['user']);

        // get 'device'
        if (isset($options['d']) && !empty($options['d']))
            self::$device = trim($options['d']);
        else if (isset($options['device']) && !empty($options['device']))
            self::$device = trim($options['device']);

        // get 'action'
        $action = false;
        if (isset($options['a']) && !empty($options['a']))
            $action = strtolower(trim($options['a']));
        elseif (isset($options['action']) && !empty($options['action']))
            $action = strtolower(trim($options['action']));

        // get a command for the requested action
        switch ($action) {
            // list data
            case "list":
                if (self::$user === false && self::$device === false)
                    self::$command = self::COMMAND_SHOWALLDEVICES;

                if (self::$user !== false)
                    self::$command = self::COMMAND_SHOWDEVICESOFUSER;

                if (self::$device !== false)
                    self::$command = self::COMMAND_SHOWUSERSOFDEVICE;
                break;

            // remove wipe device
            case "wipe":
                if (self::$user === false && self::$device === false)
                    self::$errormessage = "Not possible to execute remote wipe. Device, user or both must be specified.";
                else
                    self::$command = self::COMMAND_WIPEDEVICE;
                break;

            // remove device data of user
            case "remove":
                if (self::$user === false && self::$device === false)
                    self::$errormessage = "Not possible to remove data. Device, user or both must be specified.";
                else
                    self::$command = self::COMMAND_REMOVEDEVICE;
                break;

            // resync a device
            case "resync":
            case "re-sync":
            case "sync":
            case "resynchronize":
            case "re-synchronize":
            case "synchronize":
                if (self::$user === false || self::$device === false)
                    self::$errormessage = "Not possible to resynchronize device. Device and user must be specified.";
                else
                    self::$command = self::COMMAND_RESYNCDEVICE;
                break;

            default:
                self::UsageInstructions();
        }
    }

    /**
     * Indicates if the options from the command line
     * could be processed correctly
     *
     * @return boolean
     * @access public
     */
    static public function SureWhatToDo() {
        return isset(self::$command);
    }

    /**
     * Returns a errormessage of things which could have gone wrong
     *
     * @return string
     * @access public
     */
    static public function GetErrorMessage() {
        return (isset(self::$errormessage))?self::$errormessage:"";
    }

    /**
     * Runs a command requested from an action of the command line
     *
     * @return
     * @access public
     */
    static public function RunCommand() {
        echo "\n";
        switch(self::$command) {
            case self::COMMAND_SHOWALLDEVICES:
                self::CommandShowDevices();
                break;

            case self::COMMAND_SHOWDEVICESOFUSER:
                self::CommandShowDevices();
                break;

            case self::COMMAND_SHOWUSERSOFDEVICE:
                self::CommandDeviceUsers();
                break;

            case self::COMMAND_WIPEDEVICE:
                if (self::$device)
                    echo sprintf("Are you sure you want to REMOTE WIPE device '%s' [y/N]: ", self::$device);
                else
                    echo sprintf("Are you sure you want to REMOTE WIPE all devices of user '%s' [y/N]: ", self::$user);

                $confirm  =  strtolower(trim(fgets(STDIN)));
                if ( $confirm === 'y' || $confirm === 'yes')
                    self::CommandWipeDevice();
                else
                    echo "Aborted!\n";
                break;

            case self::COMMAND_REMOVEDEVICE:
                self::CommandRemoveDevice();
                break;

            case self::COMMAND_RESYNCDEVICE:
                if (self::$device == false) {
                    echo sprintf("Are you sure you want to re-synchronize all devices of user '%s' [y/N]: ", self::$user);
                    $confirm  =  strtolower(trim(fgets(STDIN)));
                    if ( !($confirm === 'y' || $confirm === 'yes'))
                        echo "Aborted!\n";
                        exit(1);
                }
                self::CommandResyncDevices();
                break;
        }
        echo "\n";
    }

    /**
     * Command "Show all devices" and "Show devices of user"
     * Prints the device id of/and connected users
     *
     * @return
     * @access public
     */
    static public function CommandShowDevices() {
        $devicelist = ZPushAdmin::ListDevices(self::$user);
        if (empty($devicelist))
            echo "\tno devices found\n";
        else {
            if (self::$user === false) {
                echo "All synchronized devices\n\n";
                echo str_pad("Device id", 32). "Synchronized users\n";
                echo "-----------------------------------------------------\n";
            }
            else
                echo "Synchronized devices of user: ". self::$user. "\n";
        }

        foreach ($devicelist as $deviceId) {
            if (self::$user === false) {
                echo str_pad($deviceId, 32) . implode (",", ZPushAdmin::ListUsers($deviceId)) ."\n";
            }
            else
                self::printDeviceData($deviceId, self::$user);
        }
    }

    /**
     * Command "Show users of device"
     * Prints informations about all users which use a device
     *
     * @return
     * @access public
     */
    static public function CommandDeviceUsers() {
        $users = ZPushAdmin::ListUsers(self::$device);

        if (empty($users))
            echo "\tno user data synchronized to device\n";

        foreach ($users as $user) {
            echo "Synchronized by user: ". $user. "\n";
            self::printDeviceData(self::$device, $user);
        }
    }

    /**
     * Command "Wipe device"
     * Marks a device of that user to be remotely wiped
     *
     * @return
     * @access public
     */
    static public function CommandWipeDevice() {
        $stat = ZPushAdmin::WipeDevice($_SERVER["LOGNAME"], self::$user, self::$device);

        if (self::$user !== false && self::$device !== false) {
            echo sprintf("Mark device '%s' of user '%s' to be wiped: %s", self::$device, self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";

            if ($stat) {
                echo "Updated information about this device:\n";
                self::printDeviceData(self::$device, self::$user);
            }
        }
        elseif (self::$user !== false) {
            echo sprintf("Mark devices of user '%s' to be wiped: %s", self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
            self::CommandShowDevices();
        }
    }

    /**
     * Command "Remove device"
     * Remove a device of that user from the device list
     *
     * @return
     * @access public
     */
    static public function CommandRemoveDevice() {
        $stat = ZPushAdmin::RemoveDevice(self::$user, self::$device);
        if (self::$user === false)
           echo sprintf("State data of device '%s' removed: %s", self::$device, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
        elseif (self::$device === false)
           echo sprintf("State data of all devices of user '%s' removed: %s", self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
        else
           echo sprintf("State data of device '%s' of user '%s' removed: %s", self::$device, self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
    }

    /**
     * Command "Resync device(s)"
     * Resyncs one or all devices of that user
     *
     * @return
     * @access public
     */
    static public function CommandResyncDevices() {
        $stat = ZPushAdmin::ResyncDevice(self::$user, self::$device);
        echo sprintf("Resync of device '%s' of user '%s': %s", self::$device, self::$user, ($stat)?'Requested':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
    }

    /**
     * Prints detailed informations about a device
     *
     * @param string    $deviceId       the id of the device
     *
     * @return
     * @access private
     */
    static private function printDeviceData($deviceId, $user) {
        $device = ZPushAdmin::GetDeviceDetails($deviceId, $user);

        if (! $device instanceof ASDevice)
            return false;

        // Gather some statistics about synchronized folders
        $folders = $device->GetAllFolderIds();
        $synchedFolders = 0;
        $synchedFolderTypes = array();
        foreach ($folders as $folderid) {
            if ($device->GetFolderUUID($folderid)) {
                $synchedFolders++;
                $type = $device->GetFolderType($folderid);
                switch($type) {
                    case SYNC_FOLDER_TYPE_APPOINTMENT:
                    case SYNC_FOLDER_TYPE_USER_APPOINTMENT:
                        $gentype = "Calendars";
                        break;
                    case SYNC_FOLDER_TYPE_CONTACT:
                    case SYNC_FOLDER_TYPE_USER_CONTACT:
                        $gentype = "Contacts";
                        break;
                    case SYNC_FOLDER_TYPE_TASK:
                    case SYNC_FOLDER_TYPE_USER_TASK:
                        $gentype = "Tasks";
                        break;
                    default:
                        $gentype = "Emails";
                        break;
                }
                if (!isset($synchedFolderTypes[$gentype]))
                    $synchedFolderTypes[$gentype] = 0;
                $synchedFolderTypes[$gentype]++;
            }
        }
        $folderinfo = "";
        foreach ($synchedFolderTypes as $gentype=>$count) {
            $folderinfo .= $gentype;
            if ($count>1) $folderinfo .= "($count)";
            $folderinfo .= " ";
        }
        if (!$folderinfo) $folderinfo = "None available";

        echo "-----------------------------------------------------\n";
        echo "DeviceId:\t\t$deviceId\n";
        echo "Device type:\t\t". ($device->GetDeviceType() !== ASDevice::UNDEFINED ? $device->GetDeviceType() : "unknown") ."\n";
        echo "UserAgent:\t\t".($device->GetDeviceUserAgent()!== ASDevice::UNDEFINED ? $device->GetDeviceUserAgent() : "unknown") ."\n";
        // TODO implement $device->GetDeviceUserAgentHistory()

        echo "First sync:\t\t". strftime("%Y-%m-%d %H:%M", $device->GetFirstSyncTime()) ."\n";
        echo "Last sync:\t\t"."not implemented\n";
        echo "Total folders:\t\t". count($folders). "\n";
        echo "Synchronized folders:\t". $synchedFolders . "\n";
        echo "Synchronized data:\t$folderinfo\n";
        echo "Status:\t\t\t";
        switch ($device->GetWipeStatus()) {
            case SYNC_PROVISION_RWSTATUS_OK:
                echo "OK\n";
                break;
            case SYNC_PROVISION_RWSTATUS_PENDING:
                echo "Pending wipe\n";
                break;
            case SYNC_PROVISION_RWSTATUS_REQUESTED:
                echo "Wipe requested on device\n";
                break;
            case SYNC_PROVISION_RWSTATUS_WIPED:
                echo "Wiped\n";
                break;
            default:
                echo "Not available\n";
                break;
        }

        echo "WipeRequest on:\t\t". ($device->GetWipeRequestedOn() !== false ? strftime("%Y-%m-%d %H:%M", $device->GetWipeRequestedOn()) : "not set")."\n";
        echo "WipeRequest by:\t\t". ($device->GetWipeRequestedBy() !== false ? $device->GetWipeRequestedBy() : "not set")."\n";
        echo "Wiped on:\t\t". ($device->GetWipedOn() !== false ? strftime("%Y-%m-%d %H:%M", $device->GetWipedOn()) : "not set")."\n";
    }
}

?>