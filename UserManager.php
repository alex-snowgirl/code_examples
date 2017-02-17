<?php

namespace Adobe\EchoSign\GoogleBundle\Manager;

use Adobe\EchoSign\GoogleBundle\Api\EchoSignApi;
use Adobe\EchoSign\GoogleBundle\Api\GoogleDriveApi;
use Adobe\EchoSign\GoogleBundle\Entity\EchoSignUser;
use Adobe\EchoSign\GoogleBundle\Entity\EchoSignUserRepository;
use Adobe\EchoSign\GoogleBundle\Entity\GoogleUser;
use Adobe\EchoSign\GoogleBundle\Model\DriveRequest;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\DependencyInjection\Container;

class UserManager
{
    private $container;

    function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @return GoogleDriveApi
     */
    protected function getDriveApi()
    {
        return $this->container->get('adobe_echo_sign_google.drive_api');
    }

    /**
     * @return EchoSignApi
     */
    protected function getSignApi()
    {
        return $this->container->get('adobe_echo_sign_google.echosign_api');
    }

    /**
     * @return GoogleRequestManager
     */
    protected function getRequestManager()
    {
        return $this->container->get('adobe_echo_sign_google.request_manager');
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager()
    {
        return $this->container->get('doctrine.orm.entity_manager');
    }

    /**
     * @return EntityRepository
     */
    protected function getGoogleUserRepository()
    {
        return $this->getEntityManager()->getRepository('AdobeEchoSignGoogleBundle:GoogleUser');
    }

    /**
     * @return EchoSignUserRepository
     */
    protected function getSignUserRepository()
    {
        return $this->getEntityManager()->getRepository('AdobeEchoSignGoogleBundle:EchoSignUser');
    }

    /**
     * @return CryptManager
     */
    protected function getCryptManager()
    {
        return $this->container->get('adobe_echo_sign_google.crypt_manager');
    }

    /**
     * @param $userId
     * @return GoogleUser
     */
    public function getGoogleUser($userId)
    {
        return $this->getGoogleUserByGoogleUserId($userId);
    }

    public function getCurrentDriveUser()
    {
        $request = $this->getRequestManager()->getRequest();
        $user = null;

        if ($request && $request instanceof DriveRequest) {
            $user = $this->getGoogleUserByGoogleUserId($request->getUserId());
        }

        return $user;
    }

    /**
     * @return EchoSignUser|null
     */
    public function getSignUser()
    {
        /** @var DriveRequest $request */
        $request = $this->getRequestManager()->getRequest();
        $user = null;

        if ($request && $request instanceof DriveRequest) {
            $user = $this->getSignUserByGoogleUserId($request->getUserId());
        }

        return $user;
    }

    /**
     * @param $googleUserId
     * @return EchoSignUser
     */
    public function getSignUserByGoogleUserId($googleUserId)
    {
        return $this->getSignUserRepository()->fetchEchoSignUser($googleUserId);
    }

    public function getValidGoogleToken(GoogleUser $user)
    {
        $token = $this->getGoogleToken($user);

        if (!$this->getDriveApi()->isValidToken($token)) {
            $token = $this->getGoogleToken($user, true);

            if ($token = $this->getDriveApi()->refreshOAuthToken($token->refresh_token)) {
                $this->updateGoogleToken($user, $token);
            } else {
                return false;
            }
        }

        return $token;
    }

    /**
     * @param EchoSignUser $user
     * @return bool
     */
    public function getValidSignToken(EchoSignUser $user)
    {
//        $now = new \DateTime();
//        $tokenExpire = $user->getExpireToken();
//        $tokenExpire->modify('+1 hour');

//        if ($now->getTimestamp() < $tokenExpire->getTimestamp()) {
//            $token = $this->getCryptManager()->decrypt($user->getToken());
//        } else {
        if ($user->getRefreshToken() && mb_check_encoding($this->getSignToken($user), 'UTF-8')) {
            $refreshToken = $this->getSignRefreshToken($user);
            $refreshedToken = $this->getSignApi()->refreshOAuthToken($refreshToken);

            if (property_exists($refreshedToken, 'access_token') && property_exists($refreshedToken, 'expires_in')) {
                $token = $refreshedToken->access_token;
                $expiration = (new \DateTime())->modify(sprintf('+%d seconds', $refreshedToken->expires_in));
                $this->updateSignToken($user, $token, $expiration);
            } else {
                return false;
            }
        } else {
            return false;
        }
//        }

        return $token;
    }

    /**
     * @param GoogleUser $user
     * @param bool $returnAsObject
     * @return mixed
     */
    public function getGoogleToken(GoogleUser $user, $returnAsObject = false)
    {
        $cryptManager = $this->getCryptManager();
        $token = $cryptManager->decrypt($user->getToken());

        if ($returnAsObject) {
            $token = json_decode($token);
        }

        return $token;
    }

    public function getSignToken(EchoSignUser $user = null)
    {
        if (!$user || !$user->getToken() || !$user->isValidToken()) {
            return null;
        }

        return $this->getCryptManager()->decrypt($user->getToken());
    }

    public function getSignRefreshToken(EchoSignUser $user = null)
    {
        if (!$user || !$user->getToken() || !$user->getRefreshToken()) {
            return null;
        }

        return $this->getCryptManager()->decrypt($user->getRefreshToken());
    }

    /**
     * @param EchoSignUser $user
     * @param $token
     * @param null $expiration
     */
    public function updateSignToken(EchoSignUser $user, $token, $expiration = null)
    {
        $token = $this->getCryptManager()->encrypt($token);
        $user->setToken($token);

        if (!$expiration) {
            $expiration = ((new \DateTime())->modify('+1 hour'));
        }

        $user->setExpireToken($expiration);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * @param GoogleUser $user
     * @param $token
     */
    public function updateGoogleToken(GoogleUser $user, $token)
    {
        $token = $this->getCryptManager()->encrypt($token);
        $user->setToken($token);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * @param $userId
     * @return GoogleUser
     */
    public function getGoogleUserByGoogleUserId($userId)
    {
        return $this->getGoogleUserRepository()->findOneBy(array(
            'userId' => $userId,
        ));
    }
}
