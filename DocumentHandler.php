<?php

namespace Adobe\EchoSign\GoogleBundle\Command;

use Adobe\EchoSign\GoogleBundle\Api\EchoSignApi;
use Adobe\EchoSign\GoogleBundle\Api\GoogleDriveApi;
use Adobe\EchoSign\GoogleBundle\Entity\Document;
use Adobe\EchoSign\GoogleBundle\Entity\DocumentRepository;
use Adobe\EchoSign\GoogleBundle\Entity\EchoSignUser;
use Adobe\EchoSign\GoogleBundle\Entity\GoogleUser;
use Adobe\EchoSign\GoogleBundle\Manager\CryptManager;
use Adobe\EchoSign\GoogleBundle\Manager\UserManager;
use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\Container;

class DocumentHandler
{
    private $container;
    /** @var  EntityManager */
    private $entityManager;
    /** @var  EchoSignApi */
    private $echoSignApi;
    /** @var  GoogleDriveApi */
    private $googleApi;
    /** @var  GoogleUser */
    private $googleUser;
    /** @var  EchoSignUser */
    private $echoSignUser;
    /** @var  Document */
    private $document;
    private $file;
    private $fileId;
    private $documentName;
    private $currentName;
    private $uniqueName;
    private $documentStatus;
    private $latestDocumentKey;
    private $echoSignToken;

    /**
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function init($userId)
    {
        $this->entityManager = $this->container->get('doctrine.orm.entity_manager');

        /** @var UserManager $userManager */
        $userManager = $this->container->get('adobe_echo_sign_google.user_manager');

        $this->echoSignApi = $this->container->get('adobe_echo_sign_google.echosign_api');
        $this->googleApi = $this->container->get('adobe_echo_sign_google.drive_api');

        /** @var CryptManager $cryptManager */
        $cryptManager = $this->container->get('adobe_echo_sign_google.crypt_manager');

        $this->googleUser = $userManager->getGoogleUserByGoogleUserId($userId);

        if (!$this->googleUser) {
            $this->document->setQueued(false);
            $this->entityManager->persist($this->document);
            $this->entityManager->flush();
            throw new \Exception("Google user not found \n UsedId: $userId");
        }

        $this->echoSignUser = $userManager->getSignUserByGoogleUserId($userId);
        $this->echoSignToken = $cryptManager->decrypt($this->echoSignUser->getToken());
        $googleToken = $cryptManager->decrypt($this->googleUser->getToken());
        $this->googleApi->setToken($googleToken);
    }

    public function handleDocument($documentId)
    {
        $this->cleanState();

        if (!$this->fetchDocument($documentId)) {
            return;
        }

        if (!$this->initDocumentInfo()) {
            return;
        }

        if (!$this->checkDocument()) {
            return;
        };

        $this->initUniqueName();
        $this->checkDestinationFolder();

        foreach ($this->getTypeDocuments() as $type) {
            $this->initCurrentName($type);

            if (!$this->download($type)) {
                return;
            };

            $this->upload();

            if (!$this->fileId) {
                return;
            }
        }

        $this->persist();
    }

    private function cleanState()
    {
        $this->document = null;
        $this->file = null;
        $this->fileId = null;
        $this->documentName = null;
        $this->currentName = null;
        $this->latestDocumentKey = null;
        $this->documentStatus = null;
    }

    private function fetchDocument($id)
    {
        $success = true;

        if (!$this->document = $this->entityManager->getRepository('AdobeEchoSignGoogleBundle:Document')->find($id)) {
            $success = false;
            error_log("Document was not found or incorrect id \n DocumentId: $id");
        };

        return $success;
    }

    private function initUniqueName()
    {
        if ($this->document->getUniqueName()) {
            $this->uniqueName = $this->document->getUniqueName();
        } else {
            $uniqueName = $this->documentName;
            /** @var DocumentRepository $repository */
            $repository = $this->entityManager->getRepository('AdobeEchoSignGoogleBundle:Document');
            $repeats = intval($repository->findNameByPattern($uniqueName, $this->echoSignUser->getId()));

            if ($repeats >= 1) {
                $uniqueName .= '-' . ++$repeats;
            }

            $uniqueName = str_replace('/', ' ', $uniqueName);
            $uniqueName = str_replace('\\', ' ', $uniqueName);

            $this->uniqueName = $uniqueName;
        }
    }

    private function initDocumentInfo()
    {
        $success = true;

        if (!$info = $this->echoSignApi->getDocumentInfo($this->document->getDocumentKey(), $this->echoSignToken)) {
            $success = false;
            error_log("Document info was not retrieved \n DocumentKey: " . $this->document->getDocumentKey());
        } else {
            $this->documentStatus = $info->documentInfo->status;
            $this->documentName = $info->documentInfo->name;
            $this->latestDocumentKey = $info->documentInfo->latestDocumentKey;
        }

        return $success;
    }

    private function checkDocument()
    {
        $success = true;

        if ($this->document->getLatestDocumentKey() == $this->latestDocumentKey && $this->documentStatus == $this->document->getStatus()) {
            $success = false;
            error_log("Document in this status already handled \n DocumentKey: " . $this->document->getDocumentKey() . " \n Status: " . $this->documentStatus);
            $this->document->setQueued(false);
            $this->entityManager->persist($this->document);
            $this->entityManager->flush();
        }

        return $success;
    }

    private function checkDestinationFolder()
    {
        $this->googleApi->unTrashFolders(array($this->document->getFolder()->getDriveId()));
    }

    private function getTypeDocuments()
    {
        $types = ['auditTrail'];

        if ($this->documentStatus == 'SIGNED' || $this->documentStatus == 'APPROVED') {
            $types[] = 'document';
        }

        return $types;
    }

    private function initCurrentName($type)
    {
        $this->currentName = $this->uniqueName;

        if ($type == 'document') {
            $this->currentName .= '_signed';
        } else {
            $this->currentName .= '_audit';
        }
    }

    private function download($type)
    {
        $success = true;
        $documentKey = $this->document->getDocumentKey();

        if ($type == 'document') {
            $response = $this->echoSignApi->getDocumentUrls($documentKey, $this->echoSignToken);

            if (!$urls = $response->getDocumentUrlsResult->urls) {
                return $success = false;
            }

            $url = $urls->DocumentUrl->url;
            $data = @file_get_contents($url);
        } else {
            $auditResult = $this->echoSignApi->getAuditTrail($documentKey, $this->echoSignToken);

            if (!$data = $auditResult->getAuditTrailResult->auditTrailPdf) {
                return $success = false;
            }
        }

        $this->file = $data;

        return $success;
    }

    private function upload()
    {
        $fileId = null;

        $fileId = $this->googleApi->uploadFile(
            $this->file,
            $this->document->getFolder()->getDriveId(),
            $this->currentName
        );

        $this->fileId = $fileId;
    }

    private function persist()
    {
        $this->document->setGoogleFileId($this->fileId);
        $this->document->setName($this->documentName);
        $this->document->setUniqueName($this->uniqueName);
        $this->document->setLatestDocumentKey($this->latestDocumentKey);
        $this->document->setSigned($this->documentStatus == 'SIGNED' || $this->documentStatus == 'APPROVED');
        $this->document->setStatus($this->documentStatus);
        $this->document->setQueued(false);
        $this->entityManager->persist($this->document);
        $this->entityManager->persist($this->echoSignUser);
        $this->entityManager->flush();
    }
}