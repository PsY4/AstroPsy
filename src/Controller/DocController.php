<?php

namespace App\Controller;

use App\Entity\Doc;
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
    public function __construct(private readonly StoragePathResolver $resolver) {}

    #[Route('/doc/{filename}/dl', name: 'direct_download')]
    public function fileDownload($filename): BinaryFileResponse
    {
        $documentsPath = $this->resolver->getDocumentsPath();
        if (!$filename) {
            throw new NotFoundHttpException('No file associated with this document.');
        }
        $filePath = $documentsPath . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($filePath)) {
            throw new NotFoundHttpException('File not found.');
        }

        $response = new BinaryFileResponse($filePath);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE, // or INLINE for previews
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
    public function new(Request $request, EntityManagerInterface $em, DocService $docService): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            docService: $docService,
            doc: new Doc(),
            template: 'doc/index.html.twig'
        );
    }

    #[Route('/doc/upload', name: 'doc_upload')]
    public function upload(Request $request, EntityManagerInterface $em, DocService $docService): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            docService: $docService,
            doc: new Doc(),
            template: 'doc/index.html.twig',
            isUpload: true
        );
    }

    #[Route('/doc/{doc}/edit', name: 'doc_edit')]
    public function edit(Doc $doc, Request $request, EntityManagerInterface $em, DocService $docService): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            docService: $docService,
            doc: $doc,
            template: 'doc/index.html.twig'
        );
    }

    #[Route('/doc/{doc}/delete', name: 'doc_delete')]
    public function delete(Doc $doc, EntityManagerInterface $em, DocService $docService): Response
    {
        $em->remove($doc);
        $em->flush();
        return $this->redirectToRoute('doc_list');
    }

    #[Route('/doc/{doc}/view', name: 'doc_view')]
    public function view(Doc $doc, Request $request, EntityManagerInterface $em, DocService $docService): Response
    {
        return $this->handleForm(
            request: $request,
            em: $em,
            docService: $docService,
            doc: $doc,
            template: 'doc/view.html.twig'
        );
    }
    #[Route('/doc/{doc}/download', name: 'doc_download')]
    public function download(Doc $doc): BinaryFileResponse
    {
        $documentsPath = $this->resolver->getDocumentsPath();
        $filename = $doc->getPath();
        if (!$filename) {
            throw new NotFoundHttpException('No file associated with this document.');
        }
        $filePath = $documentsPath . DIRECTORY_SEPARATOR . $filename;
        if (!file_exists($filePath)) {
            throw new NotFoundHttpException('File not found.');
        }

        $response = new BinaryFileResponse($filePath);
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE, // or INLINE for previews
            $filename
        );
        $response->headers->set('Content-Disposition', $disposition);

        return $response;
    }
    private function handleForm(
        Request $request,
        EntityManagerInterface $em,
        DocService $docService,
        Doc $doc,
        string $template,
        bool $isUpload = false
    ): Response {
        $form = $this->createForm(DocType::class, $doc);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $uploadedFile */
            $uploadedFile = $form->get('uploadedFile')->getData();
            if ($uploadedFile) {
                $documentsPath = $this->resolver->getDocumentsPath();

                $newFilename = $uploadedFile->getClientOriginalName();
                $uploadedFile->move(
                    $documentsPath,
                    $newFilename
                );
                $doc->setPath($newFilename);
            } else {
                $docService->saveDocToStorage($doc);
            }

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
