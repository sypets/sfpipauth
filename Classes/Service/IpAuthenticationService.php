<?php

namespace Snowflake\Sfpipauth\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Sv\AbstractAuthenticationService;


/**
 * Class IpAuthenticationService
 *
 * @package Snowflake\Sfpipauth\Service
 */
class IpAuthenticationService extends AbstractAuthenticationService
{


    /**
     * @var array
     */
    protected $ipConfigurations = [];


    /**
     *
     */
    public function __construct()
    {

        try {
            // Get all available ip configurations
            $result = static::getDatabaseConnection()->exec_SELECTgetRows(
                'ip,feusers,loginmode',
                'tx_sfpipauth_ipconfiguration',
                'hidden=0 AND deleted=0'
            );

            if (is_array($result)) {
                $this->ipConfigurations = $result;
            }
        } catch (\InvalidArgumentException $e) {
            // cannot happen anyways
        }
    }


    /**
     * Gets the database object.
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected static function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }


    /**
     * Find user by incoming ip address
     *
     * @return array|bool
     */
    public function getUser()
    {

        $user = false;

        if ($this->mode === 'getUserFE' && $this->login['status'] !== 'login') {

            // Find user by incoming ip address
            $user = $this->findUserByIp($this->authInfo['REMOTE_ADDR']);
        }

        return $user;
    }


    /**
     * Find user which matches provided ip
     *
     * @param $userIp
     * @return array|bool
     */
    protected function findUserByIp($userIp)
    {

        $user = false;

        foreach ($this->ipConfigurations as $ipConfiguration) {

            $userId = $ipConfiguration['feusers'];

            // Check if ip address matches && user ID is valid
            if ($userId > 0 && GeneralUtility::cmpIP($userIp, $ipConfiguration['ip'])) {

                // Get user from database
                $user = $this->pObj->getRawUserByUid($userId);
                break;

            }
        }

        return $user;

    }


    /**
     * Authenticate user by ip
     *
     * @param array $user
     * @return bool|int
     */
    public function authUser($user)
    {

        // If there is no ip list given then the user is valid
        $authentication = 100;

        if ($this->authInfo['loginType'] === 'FE') {

            // Find ip configurations for provided user
            $ipConfigurations = $this->findConfigurationsByUserId($user['uid']);
            $userIp = $this->authInfo['REMOTE_ADDR'];
            $authentications = [];

            // Get first match
            foreach ($ipConfigurations as $ipConfiguration) {

                $ipMatch = GeneralUtility::cmpIP($userIp, $ipConfiguration['ip']);
                $loginMode = (int)$ipConfiguration['loginmode'];

                $authentications[] = array ($loginMode, $this->getAuthenticationByLoginMode($ipMatch, $loginMode));

            }

            $authentication = $this->getAuthentication($authentications);

        }

        return $authentication;
    }


    /**
     * Find all ip configurations from specified user
     *
     * @param $userId
     * @return array
     */
    protected function findConfigurationsByUserId($userId)
    {

        $configurations = [];

        foreach ($this->ipConfigurations as $ipConfiguration) {

            // If user found & ip address matches, set login mode & stop foreach
            if ((int)$userId > 0 && (int)$ipConfiguration['feusers'] === (int)$userId) {
                $configurations[] = $ipConfiguration;
            }

        }

        return $configurations;

    }


    /**
     * Determinate login mode
     *
     * @param $ipMatch
     * @param $loginMode
     * @return bool|int
     */
    protected function getAuthenticationByLoginMode($ipMatch, $loginMode)
    {

        $authentication = false;

        switch ($loginMode) {
            case 1:
                $authentication = $ipMatch ? 200 : 100;
                break;
            case 2:
                $authentication = $ipMatch ? 200 : 0;
                break;
            case 3:
                $authentication = $ipMatch ? 100 : 0;
                break;
        }

        return $authentication;
    }


    /**
     * @param $authentications array
     * @return int
     */
    protected function getAuthentication($authentications)
    {

        $authenticationComparison = 100;

        $disableLogin = false;
        $autoLogin = false;

        foreach ($authentications as $authentication) {

            if ($authentication[1] === 0 && ($authentication[0] === 3 || $authentication[0] === 2)) {
                $disableLogin = true;
            }

            if ($authentication[0] === 1 || $authentication[0] === 2) {
                $autoLogin = true;
            }

        }

        if ($disableLogin) {
            $authenticationComparison = 0;
        } elseif ($autoLogin) {
            $authenticationComparison = 200;
        }

        return $authenticationComparison;
    }

}