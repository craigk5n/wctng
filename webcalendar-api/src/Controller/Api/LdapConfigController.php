<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Auth\LdapConfig;
use App\Auth\LdapConfigRepository;
use App\Response\ApiResponse;
use App\Security\WebCalendarUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class LdapConfigController
{
    public function __construct(
        private readonly LdapConfigRepository $repository,
    ) {
    }

    #[Route('/api/v2/admin/ldap-config', name: 'api_ldap_config_get', methods: ['GET'])]
    public function get(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        return ApiResponse::success($this->repository->get()->toArray());
    }

    #[Route('/api/v2/admin/ldap-config', name: 'api_ldap_config_put', methods: ['PUT'])]
    public function put(Request $request, #[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $decoded = json_decode($request->getContent(), true);
        if (!\is_array($decoded)) {
            return ApiResponse::error(400, 'Invalid JSON body');
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $existing = $this->repository->get();

        $config = new LdapConfig(
            host: isset($data['host']) && \is_string($data['host']) ? $data['host'] : $existing->host(),
            port: isset($data['port']) && is_numeric($data['port']) ? (int) $data['port'] : $existing->port(),
            baseDn: isset($data['base_dn']) && \is_string($data['base_dn']) ? $data['base_dn'] : $existing->baseDn(),
            bindDn: isset($data['bind_dn']) && \is_string($data['bind_dn']) ? $data['bind_dn'] : $existing->bindDn(),
            bindPassword: isset($data['bind_password']) && \is_string($data['bind_password']) ? $data['bind_password'] : $existing->bindPassword(),
            userFilter: isset($data['user_filter']) && \is_string($data['user_filter']) ? $data['user_filter'] : $existing->userFilter(),
            useTls: isset($data['use_tls']) ? (bool) $data['use_tls'] : $existing->useTls(),
            enabled: isset($data['enabled']) ? (bool) $data['enabled'] : $existing->isEnabled(),
        );

        $this->repository->save($config);

        return ApiResponse::success($config->toArray());
    }

    #[Route('/api/v2/admin/ldap-config/test', name: 'api_ldap_config_test', methods: ['POST'])]
    public function test(#[CurrentUser] ?WebCalendarUser $user): JsonResponse
    {
        if ($user === null || !$user->getCoreUser()->isAdmin()) {
            return ApiResponse::error(403, 'Admin access required');
        }

        $config = $this->repository->get();

        if ($config->host() === '') {
            return ApiResponse::error(400, 'LDAP host not configured');
        }

        // Test LDAP connection
        if (!\function_exists('ldap_connect')) {
            return ApiResponse::error(500, 'PHP LDAP extension not installed');
        }

        $ldapUri = ($config->useTls() ? 'ldaps://' : 'ldap://') . $config->host() . ':' . $config->port();

        try {
            $conn = ldap_connect($ldapUri);
            if ($conn === false) {
                return ApiResponse::error(500, 'Failed to initialize LDAP connection');
            }

            ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);

            if ($config->bindDn() !== '') {
                $bound = @ldap_bind($conn, $config->bindDn(), $config->bindPassword());
            } else {
                $bound = @ldap_bind($conn);
            }

            ldap_unbind($conn);

            if ($bound) {
                return ApiResponse::success(['status' => 'ok', 'message' => 'LDAP connection successful']);
            }

            return ApiResponse::error(401, 'LDAP bind failed — check credentials');
        } catch (\Throwable $e) {
            return ApiResponse::error(500, 'LDAP connection error: ' . $e->getMessage());
        }
    }
}
