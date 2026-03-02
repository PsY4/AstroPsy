<?php

namespace App\Controller;

use App\Entity\Doc;
use App\Entity\Session;
use App\Form\DocType;
use App\Service\DocService;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DocController extends AbstractController
{
    public function __construct(
        private readonly StoragePathResolver $resolver,
        private readonly DocService $docService,
    ) {}

    #[Route('/doc/{filename}/dl', name: 'direct_download')]
    public function fileDownload(string $filename): BinaryFileResponse
    {
        $this->docService->validateFilename($filename);

        $documentsPath = $this->resolver->getDocumentsPath();
        $filePath = $documentsPath . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($filePath)) {
            throw new NotFoundHttpException('File not found.');
        }

        $response = new BinaryFileResponse($filePath);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $filename
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }

    #[Route('/doc/list', name: 'doc_list')]
    public function list(EntityManagerInterface $em): Response
    {
        $docs = $em->getRepository(Doc::class)->findAll();

        return $this->render('doc/list.html.twig', [
            'docs' => $docs,
        ]);
    }

    #[Route('/doc/new', name: 'doc_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $doc = new Doc();

        // Pre-select session from query param
        $sessionId = $request->query->get('session');
        if ($sessionId) {
            $session = $em->getRepository(Session::class)->find($sessionId);
            if ($session) {
                $doc->setSession($session);
            }
        }

        return $this->handleForm(
            request: $request,
            em: $em,
            doc: $doc,
            template: 'doc/index.html.twig'
        );
    }

    #[Route('/doc/upload', name: 'doc_upload')]
    public function upload(Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            doc: new Doc(),
            template: 'doc/index.html.twig',
            isUpload: true
        );
    }

    #[Route('/doc/{doc}/edit', name: 'doc_edit')]
    public function edit(Doc $doc, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            doc: $doc,
            template: 'doc/index.html.twig'
        );
    }

    #[Route('/doc/{doc}/delete', name: 'doc_delete')]
    public function delete(Doc $doc, EntityManagerInterface $em): Response
    {
        $em->remove($doc);
        $em->flush();
        return $this->redirectToRoute('doc_list');
    }

    #[Route('/doc/{doc}/view', name: 'doc_view')]
    public function view(Doc $doc, Request $request, EntityManagerInterface $em): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            doc: $doc,
            template: 'doc/view.html.twig'
        );
    }

    #[Route('/doc/{doc}/download', name: 'doc_download')]
    public function download(Doc $doc): BinaryFileResponse
    {
        $filename = $doc->getPath();
        if (!$filename) {
            throw new NotFoundHttpException('No file associated with this document.');
        }

        return $this->fileDownload($filename);
    }

    private function handleForm(
        Request $request,
        EntityManagerInterface $em,
        Doc $doc,
        string $template,
        bool $isUpload = false
    ): Response {
        $isNew = $doc->getId() === null;
        $form = $this->createForm(DocType::class, $doc);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $form->get('uploadedFile')->getData();
            if ($uploadedFile) {
                $newFilename = $this->docService->moveUploadedFile($uploadedFile);
                $doc->setPath($newFilename);
            } elseif ($doc->getDoc()) {
                $this->docService->saveDocToStorage($doc);
            }

            if ($isNew) {
                $doc->setCreationDate(new \DateTime());
            }
            $doc->setUpdateDate(new \DateTime());

            $em->persist($doc);
            $em->flush();

            return $this->redirectToRoute('doc_list');
        }

        return $this->render($template, [
            'form' => $form->createView(),
            'doc'  => $doc,
            'isUpload'  => $isUpload,
        ]);
    }
}
