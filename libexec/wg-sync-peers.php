<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

require_once dirname(__DIR__).'/vendor/autoload.php';
$baseDir = dirname(__DIR__);

use LC\Portal\Config;
use LC\Portal\HttpClient\CurlHttpClient;
use LC\Portal\ProfileConfig;
use LC\Portal\Storage;
use LC\Portal\WireGuard\WgDaemon;

try {
    $configFile = sprintf('%s/config/config.php', $baseDir);
    $config = Config::fromFile($configFile);
    $dataDir = sprintf('%s/data', $baseDir);
    $storage = new Storage(
        new PDO(
            $config->s('Db')->requireString('dbDsn', 'sqlite://'.$dataDir.'/db.sqlite'),
            $config->s('Db')->optionalString('dbUser'),
            $config->s('Db')->optionalString('dbPass')
        ),
        sprintf('%s/schema', $baseDir)
    );
    $wgDaemon = new WgDaemon(new CurlHttpClient());
    foreach ($config->requireArray('vpnProfiles') as $profileId => $profileData) {
        $profileConfig = new ProfileConfig(new Config($profileData));
        if ('wireguard' === $profileConfig->vpnType()) {
            $wgDevice = 'wg'.(string) $profileConfig->profileNumber();
            // extract the peers from the DB per profile
            $wgDaemon->syncPeers($wgDevice, $storage->wgGetAllPeers($profileId));
        }
    }
} catch (Exception $e) {
    echo sprintf('ERROR: %s', $e->getMessage()).\PHP_EOL;
    exit(1);
}