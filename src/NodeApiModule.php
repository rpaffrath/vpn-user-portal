<?php

declare(strict_types=1);

/*
 * eduVPN - End-user friendly VPN.
 *
 * Copyright: 2016-2021, The Commons Conservancy eduVPN Programme
 * SPDX-License-Identifier: AGPL-3.0+
 */

namespace LC\Portal;

use DateTimeImmutable;
use LC\Portal\CA\CaInterface;
use LC\Portal\Exception\NodeApiException;
use LC\Portal\Http\ApiErrorResponse;
use LC\Portal\Http\ApiResponse;
use LC\Portal\Http\InputValidation;
use LC\Portal\Http\Request;
use LC\Portal\Http\Response;
use LC\Portal\Http\Service;
use LC\Portal\Http\ServiceModuleInterface;

class NodeApiModule implements ServiceModuleInterface
{
    private Config $config;

    private CaInterface $ca;

    private Storage $storage;

    private TlsCrypt $tlsCrypt;

    private DateTimeImmutable $dateTime;

    public function __construct(Config $config, CaInterface $ca, Storage $storage, TlsCrypt $tlsCrypt)
    {
        $this->config = $config;
        $this->ca = $ca;
        $this->storage = $storage;
        $this->tlsCrypt = $tlsCrypt;
        $this->dateTime = new DateTimeImmutable();
    }

    public function init(Service $service): void
    {
        $service->post(
            '/add_server_certificate',
            function (Request $request, array $hookData): Response {
                $profileId = InputValidation::profileId($request->requirePostParameter('profile_id'));
                $profileConfig = new ProfileConfig($this->config->s('vpnProfiles')->s($profileId));
                $serverName = $profileConfig->hostName();
                $certInfo = $this->ca->serverCert($serverName);
                $certInfo['tls_crypt'] = $this->tlsCrypt->get($profileId);
                $certInfo['ca'] = $this->ca->caCert();

                return new ApiResponse('add_server_certificate', $certInfo, 201);
            }
        );

        $service->post(
            '/connect',
            function (Request $request, array $hookData): Response {
                try {
                    $this->connect($request);

                    return new ApiResponse('connect');
                } catch (NodeApiException $e) {
                    if (null !== $userId = $e->getUserId()) {
                        $this->storage->addUserMessage($userId, 'notification', '[CONNECT] ERROR: '.$e->getMessage(), $this->dateTime);
                    }

                    return new ApiErrorResponse('connect', '[CONNECT] ERROR: '.$e->getMessage());
                }
            }
        );

        $service->post(
            '/disconnect',
            function (Request $request, array $hookData): Response {
                try {
                    $this->disconnect($request);

                    return new ApiResponse('disconnect');
                } catch (NodeApiException $e) {
                    if (null !== $userId = $e->getUserId()) {
                        $this->storage->addUserMessage($userId, 'notification', '[DISCONNECT] ERROR: '.$e->getMessage(), $this->dateTime);
                    }

                    return new ApiErrorResponse('disconnect', '[DISCONNECT] ERROR: '.$e->getMessage());
                }
            }
        );

        $service->get(
            '/profile_list',
            function (Request $request, array $hookData): Response {
                $profileList = [];
                foreach ($this->config->requireArray('vpnProfiles') as $profileId => $profileData) {
                    $profileConfig = new ProfileConfig(new Config($profileData));
                    $profileList[$profileId] = $profileConfig->toArray();
                }

                return new ApiResponse('profile_list', $profileList);
            }
        );
    }

    public function connect(Request $request): void
    {
        $profileId = InputValidation::profileId($request->requirePostParameter('profile_id'));
        $commonName = InputValidation::commonName($request->requirePostParameter('common_name'));
        $ipFour = InputValidation::ipFour($request->requirePostParameter('ipFour'));
        $ipSix = InputValidation::ipSix($request->requirePostParameter('ipSix'));
        $connectedAt = InputValidation::connectedAt($request->requirePostParameter('connected_at'));

        $this->verifyConnection($profileId, $commonName);
        $this->storage->clientConnect($profileId, $commonName, $ipFour, $ipSix, new DateTimeImmutable(sprintf('@%d', $connectedAt)));
    }

    public function disconnect(Request $request): void
    {
        $profileId = InputValidation::profileId($request->requirePostParameter('profile_id'));
        $commonName = InputValidation::commonName($request->requirePostParameter('common_name'));
        $ipFour = InputValidation::ipFour($request->requirePostParameter('ipFour'));
        $ipSix = InputValidation::ipSix($request->requirePostParameter('ipSix'));

        $connectedAt = InputValidation::connectedAt($request->requirePostParameter('connected_at'));
        $disconnectedAt = InputValidation::disconnectedAt($request->requirePostParameter('disconnected_at'));
        $bytesTransferred = InputValidation::bytesTransferred($request->requirePostParameter('bytes_transferred'));

        $this->storage->clientDisconnect($profileId, $commonName, $ipFour, $ipSix, new DateTimeImmutable(sprintf('@%d', $connectedAt)), new DateTimeImmutable(sprintf('@%d', $disconnectedAt)), $bytesTransferred);
    }

    private function verifyConnection(string $profileId, string $commonName): void
    {
        // verify status of certificate/user
        if (false === $userCertInfo = $this->storage->getUserCertificateInfo($commonName)) {
            // we do not (yet) know the user as only an existing *//* certificate can be linked back to a user...
            throw new NodeApiException(null, sprintf('user or certificate does not exist [profile_id: %s, common_name: %s]', $profileId, $commonName));
        }

        $userId = $userCertInfo['user_id'];

        if (false === strpos($userId, '!!')) {
            // FIXME "!!" indicates it is a remote guest user coming in with a
            // foreign OAuth token, for those we do NOT check expiry.. this is
            // really ugly hack, we need to get rid of sessionExpiresAt
            // completely instead! This check is skipped when a non remote
            // guest user id contains '!!' for some reason...
            //
            // this is always string, but DB gives back scalar|null
            $sessionExpiresAt = new DateTimeImmutable((string) $this->storage->getSessionExpiresAt($userId));
            if ($sessionExpiresAt->getTimestamp() < $this->dateTime->getTimestamp()) {
                throw new NodeApiException($userId, sprintf('the certificate is still valid, but the session expired at %s', $sessionExpiresAt->format(DateTimeImmutable::ATOM)));
            }
        }

        if ($userCertInfo['user_is_disabled']) {
            throw new NodeApiException($userId, 'unable to connect, account is disabled');
        }

        $this->verifyAcl($profileId, $userId);
    }

    private function verifyAcl(string $profileId, string $userId): void
    {
        $profileConfig = new ProfileConfig($this->config->s('vpnProfiles')->s($profileId));
        if ($profileConfig->enableAcl()) {
            // ACL is enabled for this profile
            $userPermissionList = $this->storage->getPermissionList($userId);
            $profilePermissionList = $profileConfig->aclPermissionList();
            if (false === self::hasPermission($userPermissionList, $profilePermissionList)) {
                throw new NodeApiException($userId, sprintf('unable to connect, user permissions are [%s], but requires any of [%s]', implode(',', $userPermissionList), implode(',', $profilePermissionList)));
            }
        }
    }

    private static function hasPermission(array $userPermissionList, array $aclPermissionList): bool
    {
        // one of the permissions must be listed in the profile ACL list
        foreach ($userPermissionList as $userPermission) {
            if (\in_array($userPermission, $aclPermissionList, true)) {
                return true;
            }
        }

        return false;
    }
}
