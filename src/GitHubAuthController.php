<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Auth\GitHub;

use Exception;
use Flarum\Forum\AuthenticationResponseFactory;
use Flarum\Settings\SettingsRepositoryInterface;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response\RedirectResponse;

class GitHubAuthController implements RequestHandlerInterface
{
    /**
     * @var AuthenticationResponseFactory
     */
    protected $authResponse;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @param AuthenticationResponseFactory $authResponse
     */
    public function __construct(AuthenticationResponseFactory $authResponse, SettingsRepositoryInterface $settings)
    {
        $this->authResponse = $authResponse;
        $this->settings = $settings;
    }

    /**
     * @param Request $request
     * @return ResponseInterface
     * @throws Exception
     */
    public function handle(Request $request): ResponseInterface
    {
        $redirectUri = (string) $request->getAttribute('originalUri', $request->getUri())->withQuery('');

        $provider = new Github([
            'clientId' => $this->settings->get('flarum-auth-github.client_id'),
            'clientSecret' => $this->settings->get('flarum-auth-github.client_secret'),
            'redirectUri' => $redirectUri
        ]);

        $session = $request->getAttribute('session');
        $queryParams = $request->getQueryParams();

        $code = array_get($queryParams, 'code');

        if (! $code) {
            $authUrl = $provider->getAuthorizationUrl(['scope' => ['user:email']]);
            $session->put('oauth2state', $provider->getState());

            return new RedirectResponse($authUrl.'&display=popup');
        }

        $state = array_get($queryParams, 'state');

        if (! $state || $state !== $session->get('oauth2state')) {
            $session->remove('oauth2state');

            throw new Exception('Invalid state');
        }

        $token = $provider->getAccessToken('authorization_code', compact('code'));

        /** @var GithubResourceOwner $user */
        $user = $provider->getResourceOwner($token);

        return $this->authResponse->make([
            'identification' => [
                'email' => $user->getEmail() ?: $this->getEmailFromApi($provider, $token)
            ],
            'attributes' => [
                'avatarUrl' => array_get($user->toArray(), 'avatar_url')
            ],
            'suggestions' => [
                'username' => $user->getNickname()
            ]
        ]);
    }

    private function getEmailFromApi(Github $provider, AccessToken $token)
    {
        $url = $provider->apiDomain.'/user/emails';

        $emails = $provider->getResponse(
            $provider->getAuthenticatedRequest('GET', $url, $token)
        );

        foreach ($emails as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }
    }
}
