<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace Flarum\Auth\Github;

use Exception;
use Flarum\Forum\Auth\SsoDriverInterface;
use Flarum\Forum\Auth\SsoResponse;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\RedirectResponse;
use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Provider\GithubResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ServerRequestInterface as Request;
use Symfony\Component\Translation\TranslatorInterface;

class GithubAuthDriver implements SsoDriverInterface
{
    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var UrlGenerator
     */
    protected $url;

    /**
     * @param SettingsRepositoryInterface $settings
     * @param TranslatorInterface $translator
     * @param UrlGenerator $url
     */
    public function __construct(SettingsRepositoryInterface $settings, TranslatorInterface $translator, UrlGenerator $url)
    {
        $this->settings = $settings;
        $this->translator = $translator;
        $this->url = $url;
    }

    public function meta(): array
    {
        return [
            "name" => "Github",
            "icon" => "fab fa-github",
            "buttonColor" => "#ccc",
            "buttonText" => $this->translator->trans('flarum-auth-github.forum.log_in.with_github_button'),
            "buttonTextColor" => "#333",
        ];
    }

    public function sso(Request $request, SsoResponse $ssoResponse)
    {
        $redirectUri = $this->url->to('forum')->route('sso', ['provider' => 'github']);

        $provider = new Github([
            'clientId' => $this->settings->get('flarum-auth-github.client_id'),
            'clientSecret' => $this->settings->get('flarum-auth-github.client_secret'),
            'redirectUri' => $redirectUri
        ]);

        $session = $request->getAttribute('session');
        $queryParams = $request->getQueryParams();

        $code = array_get($queryParams, 'code');

        if (!$code) {
            $authUrl = $provider->getAuthorizationUrl(['scope' => ['user:email']]);
            $session->put('oauth2state', $provider->getState());

            return new RedirectResponse($authUrl . '&display=popup');
        }

        $state = array_get($queryParams, 'state');

        if (!$state || $state !== $session->get('oauth2state')) {
            $session->remove('oauth2state');

            throw new Exception('Invalid state');
        }

        $token = $provider->getAccessToken('authorization_code', compact('code'));

        /** @var GithubResourceOwner $user */
        $user = $provider->getResourceOwner($token);

        return $ssoResponse
            ->withIdentifier($user->getId())
            ->provideTrustedEmail($user->getEmail() ?: $this->getEmailFromApi($provider, $token))
            ->provideAvatar(array_get($user->toArray(), 'avatar_url'))
            ->suggestUsername($user->getNickname())
            ->setPayload($user->toArray());
    }

    private function getEmailFromApi(Github $provider, AccessToken $token)
    {
        $url = $provider->apiDomain . '/user/emails';

        $response = $provider->getResponse(
            $provider->getAuthenticatedRequest('GET', $url, $token)
        );

        $emails = json_decode($response->getBody(), true);

        foreach ($emails as $email) {
            if ($email['primary'] && $email['verified']) {
                return $email['email'];
            }
        }
    }
}
