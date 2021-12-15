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

use fkooman\OAuth\Server\PdoStorage as OAuthStorage;
use fkooman\OAuth\Server\Signer;
use Vpn\Portal\Config;
use Vpn\Portal\Dt;
use Vpn\Portal\Expiry;
use Vpn\Portal\FileIO;
use Vpn\Portal\Http\JsonResponse;
use Vpn\Portal\Http\OAuthTokenModule;
use Vpn\Portal\Http\Request;
use Vpn\Portal\Http\Service;
use Vpn\Portal\OAuth\ClientDb;
use Vpn\Portal\OAuth\VpnOAuthServer;
use Vpn\Portal\OpenVpn\CA\VpnCa;
use Vpn\Portal\Storage;
use Vpn\Portal\SysLogger;

$logger = new SysLogger('vpn-user-portal');

try {
    $request = Request::createFromGlobals();
    FileIO::createDir($baseDir.'/data');

    $config = Config::fromFile($baseDir.'/config/config.php');
    $service = new Service();

    $db = new PDO(
        $config->dbConfig($baseDir)->dbDsn(),
        $config->dbConfig($baseDir)->dbUser(),
        $config->dbConfig($baseDir)->dbPass()
    );
    $storage = new Storage($db, $baseDir.'/schema');
    $storage->update();

    // OAuth module
    $oauthServer = new VpnOAuthServer(
        new OAuthStorage($db, 'oauth_'),
        new ClientDb(),
        new Signer(FileIO::readFile($baseDir.'/config/oauth.key'))
    );

    $ca = new VpnCa($baseDir.'/data/ca', $config->vpnCaPath());

    $oauthServer->setAccessTokenExpiry($config->apiConfig()->tokenExpiry());
    $oauthServer->setRefreshTokenExpiry(
        Expiry::calculate(
            Dt::get(),
            $ca->caCert()->validTo(),
            $config->sessionExpiry()
        )
    );

    $oauthModule = new OAuthTokenModule(
        $oauthServer
    );
    $service->addModule($oauthModule);
    $service->run($request)->send();
} catch (Exception $e) {
    $logger->error($e->getMessage());
    $response = new JsonResponse(['error' => $e->getMessage()], [], 500);
    $response->send();
}
