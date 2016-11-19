mespace Adobe\EchoSign\BoxBundle\Controller;

use Adobe\EchoSign\BoxBundle\Entity\BoxUser;
use Adobe\EchoSign\BoxBundle\Entity\Document;
use Adobe\EchoSign\BoxBundle\Entity\EchoSignUser;
use Adobe\EchoSign\BoxBundle\Entity\Folder;
use Adobe\EchoSign\BoxBundle\Manager\BoxRequestManager;
use Adobe\EchoSign\BoxBundle\Validator\BoxRequestValidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Adobe\EchoSign\BoxBundle\Model\BoxRequest;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class CallbackController extends Controller implements IpCheckerController
{
    public function frontCallbackAction()
    {
        return $this->redirect('https://box.com');
    }

    public function openCallbackAction(Request $request)
    {
        if (!$this->requestManager->registerRequest($request)) {
            throw new BadCredentialsException('Invalid request');
        }

        return $this->render("@AdobeEchoSignBox/Callback/index.html.twig", array(
            'redirect_url' => $this->generateUrl('sign', $request->query->all(), UrlGeneratorInterface::ABSOLUTE_URL),
            'loggedUser' => ($user = $this->userManager->fetchCurrentEchoSignUser()) ? $user->getEmail() : null
        ));
    }

    public function boxTokenCallbackAction(Request $request)
    {
        $now = new \DateTime();
        $code = filter_var($request->query->get('code'), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_LOW);
        $box = $this->cryptManager->unSerializeAndDecrypt($request->query->get('state'));

        if (!$box instanceof BoxRequest || !BoxRequestValidator::validate($box)) {
            throw new BadRequestHttpException("Did not get correct state from request");
        }

        $response = json_decode($this->boxApi->getAccessToken($code));

        if (!$boxUser = $this->entityManager->getRepository('AdobeEchoSignBoxBundle:BoxUser')->findOneBy(array('userId' => $box->getUserId()))) {
            $boxUser = new BoxUser();
        }

        $boxUser->setUserId($box->getUserId());
        $boxUser->setToken($this->cryptManager->encrypt($response->access_token));
        $boxUser->setRefreshToken($this->cryptManager->encrypt($response->refresh_token));
        $boxUser->setTokenInitialized($now);
        $boxUser->setRefreshTokenInitialized($now);

        $this->entityManager->persist($boxUser);
        $this->entityManager->flush();

        return $this->redirectToRoute('sign');
    }

    public function echoSignTokenCallbackAction(Request $request)
    {
        $code = filter_var($request->query->get('code'), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_LOW);
        $state = $request->query->get('state');

        if ($request->query->get('error') && $request->query->get('error_description')) {
            $errorMessage = filter_var($request->query->get('error_description'), FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_LOW);
            $this->session->getFlashBag()->add('error', ucfirst($errorMessage));
            return $this->render("@AdobeEchoSignBox/base.html.twig");
        }

        if ($state) {
            $box = $this->cryptManager->unSerializeAndDecrypt($state);
        }

        if (!isset($box) || !$box instanceof BoxRequest || !BoxRequestValidator::validate($box)) {
            throw new BadRequestHttpException("Gotten invalid state from request");
        }

        $response = $this->echoSignApi->getAccessOAuthToken($code);
        if ($response && is_object($response)) {
            if (property_exists($response, 'access_token') && property_exists($response, 'refresh_token')) {
                $token = $response->access_token;
                $refreshToken = $response->refresh_token;
                $expirationIn = sprintf('+%d seconds', $response->expires_in);
                $expiration = (new \DateTime())->modify($expirationIn);

                if (!$boxUser = $this->entityManager->getRepository('AdobeEchoSignBoxBundle:BoxUser')->findOneBy(array('userId' => $box->getUserId()))) {
                    throw new BadRequestHttpException("BoxUser not found");
                }

                if (!$echoSignUser = $this->entityManager->getRepository('AdobeEchoSignBoxBundle:EchoSignUser')->fetchEchoSignUser($box->getUserId())) {
                    $echoSignUser = new EchoSignUser();
                }

                $userInfo = $this->echoSignApi->getUserInfo($token);

                $echoSignUser->setToken($this->cryptManager->encrypt($token));
                $echoSignUser->setRefreshToken($this->cryptManager->encrypt($refreshToken));
                $echoSignUser->setExpireToken($expiration);
                $echoSignUser->setBoxUser($boxUser);
                $echoSignUser->setEmail($userInfo->getUserInfoResult->data->email);

                $this->entityManager->persist($boxUser);
                $this->entityManager->persist($echoSignUser);
                $this->entityManager->flush();

                return $this->redirectToRoute('sign');
            }

            if (property_exists($response, 'error_description')) {
                $errorMessage = filter_var($response->error_description, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_LOW);
                throw new BadRequestHttpException($errorMessage);
            } else {
                throw new BadRequestHttpException("Token was not issued");
            }
        }
        return true;
    }

    public function echoSignSignedCallbackAction(Request $request)
    {
        $userId = filter_var($request->get('userId'), FILTER_SANITIZE_NUMBER_INT);
        $folderId = filter_var($request->get('folderId'), FILTER_SANITIZE_NUMBER_INT);
        $documentKey = $request->get('documentKey');
        $documentId = $request->get('documentId');

        $documentsForUser = $this->entityManager->getRepository('AdobeEchoSignBoxBundle:Document')->fetchDocumentForUser($documentId, $userId);

        if (!count($documentsForUser)) {
            $document = new Document();
            $boxUser = $this->userManager->findBoxUser($userId);
            $echoSignUser = $this->userManager->getEchoSignUser($userId);
            $document->setUser($echoSignUser);
            if (!$folder = $this->entityManager->getRepository('AdobeEchoSignBoxBundle:Folder')->findOneByBoxId($folderId)) {
                $folder = new Folder();
                $folder->setBoxId($folderId);
            }
            $document->setFolder($folder);

            $this->entityManager->persist($folder);
            $this->entityManager->persist($echoSignUser);
        } else {
            $document = $documentsForUser[0];
            $echoSignUser = $document->getUser();
            $boxUser = $echoSignUser->getBoxUser();
        }

        $document->setDocumentId($documentId);
        $document->setDocumentKey($documentKey);
        $document->setQueued(true);
        $this->entityManager->persist($document);
        $this->entityManager->flush();

        if ($echoSignUser->isValidToken()) {
            $this->documentManager->uploadDocuments($boxUser, array($document));
        }

        return Response::create('Got callback from echoSign API');
    }
}
